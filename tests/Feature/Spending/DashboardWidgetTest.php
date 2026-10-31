<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// The dashboard's Spending card (widgets.spending): this month's total in the family display currency,
// the same figure as the Spending overview widget (shared MonthToDate).

// Own helper (not SpendingTest's spendingFamily): Pest only loads the files it runs, so a cross-file
// helper breaks when this file is run on its own.
function widgetFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);

    return [$family, $users];
}

it('puts this month\'s spending total on the dashboard when the module is enabled', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [, $users] = widgetFamily();
    actingAs($users[0])->patch('/m/spending/settings', ['default_currency' => 'EUR'])->assertRedirect();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'RON']);
    $eur = SpendEvent::where('currency', 'EUR')->first();
    $ron = SpendEvent::where('currency', 'RON')->first();

    // Nothing spent yet: still present, reads 0 in the display currency.
    actingAs($users[0])->get('/dashboard')
        ->assertInertia(fn ($p) => $p->component('dashboard')->where('widgets.spending.total', 0)->where('widgets.spending.currency', 'EUR')
            ->where('widgets.spending.count', 0)->etc());

    // mtd-safe dates: never in the future, always inside this month / last month.
    $thisMonth = now()->startOfMonth()->addDays(3)->min(now())->toDateString();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();

    // This month: 20.00 EUR (2000) + 100.00 RON (10000 RON -> 2000 EUR). Last month: 5.00 EUR, excluded.
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$ron->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $lastMonth,
    ])->assertRedirect();

    actingAs($users[0])->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('widgets.spending.total', 4000)->where('widgets.spending.currency', 'EUR')
            // Home's weekly bars: the two spends this month, in one Monday-start week; the weeks add up to the total.
            ->where('widgets.spending.count', 2)
            ->where('widgets.spending.weeks', fn ($w) => array_sum($w->all()) === 4000 && count($w) === (int) now()->startOfMonth()->startOfWeek()->diffInWeeks(now()->startOfWeek()) + 1)
            ->etc());

    // Visibility: the RON list goes private, so a sibling member's card drops its 2000.
    $ron->setVisibility('private');
    actingAs($users[1])->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('widgets.spending.total', 2000)->etc());

    // Family scoping: another family (spending on) sees none of these spends.
    $outsider = User::factory()->create();
    $other = Family::factory()->create(['owner_id' => $outsider->id]);
    $other->members()->attach($outsider, ['role' => 'admin']);
    session(['active_family_id' => $other->id]);
    app(ModuleRegistry::class)->enable('spending', $other->id);
    actingAs($outsider)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('widgets.spending.total', 0)->etc());
});

it('leaves the spending card off the dashboard when the module is disabled', function () {
    [$family, $users] = widgetFamily();
    app(ModuleRegistry::class)->disable('spending', $family->id);

    actingAs($users[0])->get('/dashboard')
        ->assertInertia(fn ($p) => $p->component('dashboard')->missing('widgets.spending')->etc());
});
