<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Birthdays\BirthdayDate;
use Modules\Contacts\Person;
use Modules\Notifications\PushAlert;

use function Pest\Laravel\actingAs;

function todayFamily(): array
{
    $admin = User::factory()->create(['locale' => 'en']);
    $ro = User::factory()->create(['locale' => 'ro']);
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($ro, ['role' => 'member']);
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    $registry = app(ModuleRegistry::class);
    $registry->enable('contacts', $family->id);
    $registry->enable('birthdays', $family->id);

    return [$family, $admin, $ro];
}

function todayBirthday(int $creator, string $name, ?int $year, int $month, int $day): BirthdayDate
{
    $person = Person::create(['name' => $name, 'created_by' => $creator]);

    return BirthdayDate::create(['person_id' => $person->id, 'month' => $month, 'day' => $day,
        'birthdate' => $year ? Carbon::create($year, $month, $day)->toDateString() : null]);
}

it('pushes a localised birthday wish to every member, once per day', function () {
    [, $admin, $ro] = todayFamily();
    Carbon::setTestNow('2026-09-19');
    todayBirthday($admin->id, 'Veronica', 2004, 9, 19);
    todayBirthday($admin->id, 'Buni', null, 10, 22); // not today
    Notification::fake();

    test()->artisan('birthdays:notify')->assertExitCode(0);

    Notification::assertSentTo($admin, PushAlert::class, fn (PushAlert $n) => $n->title === '🎂 Veronica turns 22 today' && $n->category === 'birthdays');
    Notification::assertSentTo($ro, PushAlert::class, fn (PushAlert $n) => $n->title === '🎂 Veronica împlinește 22 ani azi');
    Notification::assertSentTimes(PushAlert::class, 2);

    // Same day again: nothing new (notified_on ledger).
    test()->artisan('birthdays:notify')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 2);

    // Next year it fires again.
    Carbon::setTestNow('2027-09-19');
    test()->artisan('birthdays:notify')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 4);
});

it('celebrates a Feb 29 birthday on Mar 1 in a non-leap year and skips disabled families', function () {
    [$family, $admin] = todayFamily();
    Carbon::setTestNow('2027-03-01');
    todayBirthday($admin->id, 'Leap', null, 2, 29);
    Notification::fake();

    test()->artisan('birthdays:notify')->assertExitCode(0);
    Notification::assertSentTo($admin, PushAlert::class, fn (PushAlert $n) => $n->title === "🎂 It's Leap's birthday today");

    app(ModuleRegistry::class)->disable('birthdays', $family->id);
    Carbon::setTestNow('2028-02-29');
    test()->artisan('birthdays:notify')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 2); // still just the two members from Mar 1
});

it('shows a dashboard card for whoever celebrates today', function () {
    [, $admin] = todayFamily();
    Carbon::setTestNow('2026-09-19');
    todayBirthday($admin->id, 'Veronica', 2004, 9, 19);
    todayBirthday($admin->id, 'Buni', null, 10, 22);

    actingAs($admin)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard')
            ->where('widgets.birthdays.0.name', 'Veronica')
            ->where('widgets.birthdays.0.turning', 22)
            ->count('widgets.birthdays', 1));

    Carbon::setTestNow('2026-09-20');
    actingAs($admin)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->missing('widgets.birthdays'));
});
