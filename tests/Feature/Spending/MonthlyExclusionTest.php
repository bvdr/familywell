<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Inertia\Testing\AssertableInertia;
use Modules\Projects\Project;
use Modules\Spending\MonthToDate;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// "Count in monthly spending": a list setting + a per-expense override (null = follow the list). Excluded
// expenses stay out of MonthToDate (dashboard + overview widget + shop hint) and global analytics, but
// still count in the list's own total and stats. Everything in EUR so no FX is involved.

// Own helper (Pest only loads the files it runs, so a cross-file helper breaks single-file runs).
function monthlyFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    app(ModuleRegistry::class)->enable('projects', $family->id);
    actingAs($users[0])->patch('/m/spending/settings', ['default_currency' => 'EUR'])->assertRedirect();

    return [$family, $users];
}

function addSpend(User $u, SpendEvent $e, string $amount, array $extra = []): void
{
    actingAs($u)->post("/m/spending/events/{$e->id}/spends", [
        'amount' => $amount, 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$u->id,
        'store' => 'Lidl', 'spent_at' => now()->toDateString(), ...$extra,
    ])->assertRedirect();
}

it('keeps excluded expenses out of the monthly total, shop hint and analytics, but in the list total', function () {
    [, $users] = monthlyFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Kitchen project', 'currency' => 'EUR']);
    $home = SpendEvent::where('name', 'Home')->first();
    $project = SpendEvent::where('name', 'Kitchen project')->first();
    actingAs($users[0])->patch("/m/spending/events/{$project->id}", ['count_in_monthly' => false])->assertRedirect();

    addSpend($users[0], $home, '10.00');                                  // counts (list on)
    addSpend($users[0], $home, '1.00', ['count_in_monthly' => false]);    // pinned out
    addSpend($users[0], $project, '100.00');                              // out (list off)
    addSpend($users[0], $project, '5.00', ['count_in_monthly' => true]);  // pinned in

    $ccy = MonthToDate::visibleEventCurrencies($users[0]->id);
    expect(MonthToDate::total($ccy, 'EUR'))->toBe(1500)
        ->and(MonthToDate::byShop($ccy, 'EUR'))->toBe(['Lidl' => 1500]);

    actingAs($users[0])->get('/m/spending')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('thisMonth.total', 1500)
        ->where('events', fn ($events) => collect($events)->firstWhere('name', 'Kitchen project')['total'] === 10500)
        ->where('expenses', fn ($rows) => collect($rows)->where('inMonthly', false)->pluck('amount')->sort()->values()->all() === [100, 10000]));

    actingAs($users[0])->get('/m/spending/analytics?period=mtd')->assertInertia(fn (AssertableInertia $p) => $p->where('total', 1500));

    // The list's own stats still count every expense.
    actingAs($users[0])->get("/m/spending/events/{$project->id}/stats")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('event.total', 10500));
});

it('stores an override only when it differs from the list, and follows the list otherwise', function () {
    [, $users] = monthlyFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'EUR']);
    $home = SpendEvent::first();

    addSpend($users[0], $home, '3.00', ['count_in_monthly' => true]); // same as the list -> follow it
    $spend = Spend::withoutGlobalScopes()->first();
    expect($spend->count_in_monthly)->toBeNull();

    // Flipping the list now moves the non-overridden expense out of the monthly figure.
    actingAs($users[0])->patch("/m/spending/events/{$home->id}", ['count_in_monthly' => false])->assertRedirect();
    expect(MonthToDate::total(MonthToDate::visibleEventCurrencies($users[0]->id), 'EUR'))->toBe(0);
});

it('creates a project expense list linked to the project and kept out of monthly spending', function () {
    [$family, $users] = monthlyFamily();
    CurrentFamily::set($family->id);
    $project = Project::createFor($users[0], ['name' => 'Kitchen']);

    actingAs($users[0])->post("/m/projects/{$project->id}/spend-lists/new", ['name' => 'Kitchen costs'])->assertRedirect();

    $list = SpendEvent::where('name', 'Kitchen costs')->first();
    expect($list->count_in_monthly)->toBeFalse()
        ->and($list->currency)->toBe('EUR')
        ->and($project->links()->where(['linkable_type' => 'spend_event', 'linkable_id' => $list->id])->exists())->toBeTrue();

    // A list made on the Spending page still counts by default.
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Groceries', 'currency' => 'EUR']);
    expect(SpendEvent::where('name', 'Groceries')->first()->count_in_monthly)->toBeTrue();
});
