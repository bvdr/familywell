<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Add\AddContext;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardData;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Birthdays\BirthdayDate;
use Modules\Contacts\Person;
use Modules\Notifications\PushAlert;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/Calendar/helpers.php';

// Every "which day is it" follows the family's zone (families.timezone, FamilyClock), not the server's UTC day.
// 2026-10-29 23:30 UTC is already 01:30 on Oct 30 in Bucharest (UTC+2 after the Oct 25 DST change).

function zoned(string $zone, array $modules = ['tasks']): array
{
    [$family, $users] = calendarFamily($modules);
    $family->forceFill(['timezone' => $zone])->save();

    return [$family, $users[0], $users[1]];
}

function dueTask(User $by, string $title, string $due): void
{
    $list = TaskList::query()->first() ?? TaskList::create(['name' => 'House', 'created_by' => $by->id]);
    Task::create(['task_list_id' => $list->id, 'title' => $title, 'due_date' => $due, 'created_by' => $by->id]);
}

it('puts a Bucharest family on Oct 30 at 23:30 UTC, and a UTC family still on Oct 29', function () {
    Carbon::setTestNow('2026-10-29 23:30');
    [, $admin] = zoned('Europe/Bucharest');
    actingAs($admin);
    dueTask($admin, 'Yesterday', '2026-10-29');
    dueTask($admin, 'Today', '2026-10-30');
    dueTask($admin, 'Tomorrow', '2026-10-31');

    $home = DashboardData::build();
    expect(FamilyClock::today())->toBe('2026-10-30')
        ->and(collect($home['today'])->pluck('title')->sort()->values()->all())->toBe(['Today', 'Yesterday']) // overdue rolls in
        ->and(collect($home['tomorrow'])->pluck('title')->all())->toBe(['Tomorrow']);

    // Same instant, a family on UTC: the 30th is tomorrow, the 31st is later this week.
    Family::find(CurrentFamily::id())->forceFill(['timezone' => 'UTC'])->save();
    $home = DashboardData::build();
    expect(collect($home['today'])->pluck('title')->all())->toBe(['Yesterday'])
        ->and(collect($home['tomorrow'])->pluck('title')->all())->toBe(['Today'])
        ->and(collect($home['later'])->pluck('title')->all())->toBe(['Tomorrow']);
});

it('runs the next-3-days strip on the family\'s day', function () {
    // Sunday Nov 1, 23:30 UTC = Monday Nov 2 in Bucharest: Tomorrow is Tuesday there, so the strip is Wed-Fri.
    Carbon::setTestNow('2026-11-01 23:30');
    zoned('Europe/Bucharest');
    expect(DashboardAgenda::until())->toBe('2026-11-06')
        ->and(DashboardAgenda::stripDays())->toBe(['2026-11-04', '2026-11-05', '2026-11-06']);

    Family::find(CurrentFamily::id())->forceFill(['timezone' => 'UTC'])->save();
    expect(DashboardAgenda::stripDays())->toBe(['2026-11-03', '2026-11-04', '2026-11-05']); // still Sunday in UTC
});

it('falls back to the app zone when a family has none, and validates a picked zone', function () {
    Carbon::setTestNow('2026-10-29 23:30');
    [$family, [$admin]] = calendarFamily(['tasks']);
    expect($family->timezone)->toBeNull()
        ->and(FamilyClock::zone())->toBe('UTC')
        ->and(FamilyClock::today())->toBe('2026-10-29');

    actingAs($admin)->patch('/settings/family/region', ['timezone' => 'Mars/Olympus_Mons'])->assertSessionHasErrors('timezone');
    expect($family->fresh()->timezone)->toBeNull();

    actingAs($admin)->patch('/settings/family/region', ['timezone' => 'Europe/Bucharest'])->assertRedirect(route('settings.family'));
    expect($family->fresh()->timezone)->toBe('Europe/Bucharest')
        ->and(FamilyClock::today())->toBe('2026-10-30');
    actingAs($admin)->get('/settings/family')->assertInertia(fn ($page) => $page->where('region.timezone', 'Europe/Bucharest'));
});

it('fills an empty zone from an admin\'s browser, never from a member\'s, and never over a pick', function () {
    [$family, [$admin, $member]] = calendarFamily(['tasks']);

    actingAs($member)->withUnencryptedCookie('fw_tz', 'America/New_York')->get('/dashboard')->assertOk();
    expect($family->fresh()->timezone)->toBeNull();

    actingAs($admin)->withUnencryptedCookie('fw_tz', 'Not/AZone')->get('/dashboard')->assertOk();
    expect($family->fresh()->timezone)->toBeNull();

    actingAs($admin)->withUnencryptedCookie('fw_tz', 'Europe/Bucharest')->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('timezone', 'Europe/Bucharest'));
    expect($family->fresh()->timezone)->toBe('Europe/Bucharest');

    actingAs($admin)->withUnencryptedCookie('fw_tz', 'America/Chicago')->get('/dashboard')->assertOk();
    expect($family->fresh()->timezone)->toBe('Europe/Bucharest');
});

it('stores the creator\'s browser zone at onboarding and drops one it doesn\'t know', function () {
    $user = User::factory()->create();
    actingAs($user)->post('/onboarding', ['name' => 'The Riveras', 'color' => '#2D5E3A', 'timezone' => 'America/Los_Angeles'])->assertRedirect();
    expect($user->families()->first()->timezone)->toBe('America/Los_Angeles');

    $other = User::factory()->create();
    actingAs($other)->post('/onboarding', ['name' => 'The Popescus', 'color' => '#2D5E3A', 'timezone' => 'Nowhere/Land'])->assertRedirect();
    expect($other->families()->first()->timezone)->toBeNull();
});

it('gives the Add reader the family\'s today', function () {
    Carbon::setTestNow('2026-10-29 23:30');
    [, $admin] = zoned('Europe/Bucharest');
    expect(AddContext::for($admin, '/dashboard')->payload()['today'])->toBe('2026-10-30');
});

it('celebrates a birthday from the family\'s midnight and pushes it on their morning', function () {
    Carbon::setTestNow('2026-10-29 22:30'); // 00:30 on Oct 30 in Bucharest
    [$family, $admin] = zoned('Europe/Bucharest', ['contacts', 'birthdays']);
    app(ModuleRegistry::class)->enable('birthdays', $family->id);
    $person = Person::create(['name' => 'Ioana', 'created_by' => $admin->id]);
    BirthdayDate::create(['person_id' => $person->id, 'month' => 10, 'day' => 30, 'birthdate' => '2004-10-30']);

    actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->where('widgets.birthdays.0.name', 'Ioana')
        ->where('widgets.birthdays.0.inDays', 0)
        ->where('widgets.birthdays.0.turning', 22));

    // The push waits for 08:00 on the family's clock: not at 00:30, yes at 06:00 UTC (08:00 there).
    Notification::fake();
    $this->artisan('birthdays:notify')->assertExitCode(0);
    Notification::assertNothingSent();

    Carbon::setTestNow('2026-10-30 06:00');
    $this->artisan('birthdays:notify')->assertExitCode(0);
    Notification::assertSentTo($admin, PushAlert::class, fn (PushAlert $n) => str_contains($n->title, 'Ioana turns 22 today'));
});

it('lets a country pick fill in its zone, and lists every zone with its country', function () {
    [, [$admin]] = calendarFamily(['tasks']);

    actingAs($admin)->get('/settings/family/region')->assertOk()->assertInertia(fn ($page) => $page
        ->where('regionSheet.countries', fn ($countries) => collect($countries)->contains(fn ($c) => $c['code'] === 'RO' && $c['timezone'] === 'Europe/Bucharest')
            && collect($countries)->contains(fn ($c) => $c['code'] === 'US' && $c['timezone'] === 'America/New_York')) // primary, not Adak
        ->where('regionSheet.timezones', fn ($zones) => collect($zones)->contains(fn ($z) => $z[0] === 'America/Los_Angeles' && $z[1] === 'US')));
});
