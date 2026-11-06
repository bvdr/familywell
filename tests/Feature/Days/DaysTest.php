<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Days\Day;
use Modules\Notifications\PushAlert;

use function Pest\Laravel\actingAs;

// [family, admin (en), member (ro)] with contacts + days on.
function daysFamily(): array
{
    $admin = User::factory()->create(['locale' => 'en']);
    $ro = User::factory()->create(['locale' => 'ro']);
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($ro, ['role' => 'member']);
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('contacts', $family->id);
    app(ModuleRegistry::class)->enable('days', $family->id);

    return [$family, $admin, $ro];
}

function dayBody(array $over = []): array
{
    return array_merge(['title' => "Ana & Dan's wedding", 'tone' => 'celebrate', 'icon' => 'heart', 'day' => 7, 'month' => 11,
        'year' => 2014, 'note' => null, 'person_ids' => [], 'remind_days' => 7, 'visibility' => 'family'], $over);
}

afterEach(fn () => Carbon::setTestNow());

it('adds a day, counts the years and lists it next date first', function () {
    [, $admin] = daysFamily();
    Carbon::setTestNow('2026-10-20 10:00');

    actingAs($admin)->post('/m/days', dayBody())->assertRedirect();
    actingAs($admin)->post('/m/days', dayBody(['title' => 'Grandpa Ion', 'tone' => 'quiet', 'icon' => 'leaf', 'day' => 20, 'month' => 10, 'year' => 2023, 'remind_days' => null]))->assertRedirect();

    actingAs($admin)->get('/m/days')->assertInertia(fn ($page) => $page->component('days/index')
        ->where('days.0.title', 'Grandpa Ion')->where('days.0.daysUntil', 0)->where('days.0.years', 3)
        ->where('days.1.daysUntil', 18)->where('days.1.years', 12)->where('days.1.remindDays', 7));
});

it('keeps an Only me day from the rest of the family', function () {
    [, $admin, $ro] = daysFamily();
    actingAs($admin)->post('/m/days', dayBody(['visibility' => 'private']))->assertRedirect();
    $day = Day::first();

    actingAs($ro)->get('/m/days')->assertInertia(fn ($page) => $page->has('days', 0));
    actingAs($ro)->patch("/m/days/{$day->id}", dayBody(['title' => 'Mine now']))->assertForbidden();
    actingAs($ro)->delete("/m/days/{$day->id}")->assertForbidden();
    actingAs($admin)->get('/m/days')->assertInertia(fn ($page) => $page->has('days', 1));
});

it('sends the reminder at 9:00 on the right day, once, in each language', function () {
    [, $admin, $ro] = daysFamily();
    Carbon::setTestNow('2026-10-31 08:00');
    actingAs($admin)->post('/m/days', dayBody())->assertRedirect(); // Nov 7, a week ahead
    actingAs($admin)->post('/m/days', dayBody(['title' => 'Grandpa Ion', 'tone' => 'quiet', 'day' => 3, 'month' => 11, 'remind_days' => 3]))->assertRedirect();
    actingAs($admin)->post('/m/days', dayBody(['title' => 'No reminder', 'remind_days' => null, 'day' => 31, 'month' => 10]))->assertRedirect();
    Notification::fake();

    test()->artisan('days:notify')->assertExitCode(0);
    Notification::assertNothingSent(); // 8:00 is before the send hour

    Carbon::setTestNow('2026-10-31 09:00');
    test()->artisan('days:notify')->assertExitCode(0);
    test()->artisan('days:notify')->assertExitCode(0); // idempotent

    Notification::assertSentToTimes($admin, PushAlert::class, 2);
    Notification::assertSentTo($admin, PushAlert::class, fn (PushAlert $n) => $n->title === "Ana & Dan's wedding: 12 years in a week." && $n->category === 'days');
    Notification::assertSentTo($admin, PushAlert::class, fn (PushAlert $n) => $n->title === "Grandpa Ion's day is in 3 days.");
    Notification::assertSentToTimes($ro, PushAlert::class, 2);
});

it('shows today\'s day on Home with its linked people', function () {
    [, $admin] = daysFamily();
    Carbon::setTestNow('2026-11-07 10:00');
    actingAs($admin)->post('/m/days', dayBody())->assertRedirect();

    actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('widgets.days.0.title', "Ana & Dan's wedding")->where('widgets.days.0.daysUntil', 0)->where('widgets.days.0.years', 12)
        ->where('sections', fn ($s) => in_array('days', collect($s)->all(), true)));
});
