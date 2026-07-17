<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// A family with three members, the spending module installed and enabled, and it active in session.
function spendingFamily(int $members = 3): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);

    return [$family, $users];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/spending')->assertNotFound();
});

it('lists the spending page when enabled', function () {
    [$family, $users] = spendingFamily();

    actingAs($users[0])->get('/m/spending')->assertOk();
});

it('creates an event with all family members as participants', function () {
    [$family, $users] = spendingFamily();

    actingAs($users[0])->post('/m/spending/events', [
        'name' => 'Greece 2026', 'currency' => 'EUR',
    ])->assertRedirect();

    $event = SpendEvent::first();
    expect($event->name)->toBe('Greece 2026')
        ->and($event->members)->toHaveCount(3);
});

it('records spends and reports balances and settle-up suggestions', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    // user[0] pays 30.00, split three ways.
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ])->assertRedirect();

    expect($event->spends()->first()->amount)->toBe(3000); // stored as cents

    actingAs($users[0])->get("/m/spending/events/{$event->id}")
        ->assertInertia(fn ($page) => $page
            ->component('spending/event')
            ->where('event.total', 3000)
            ->where('balances.0.net', 2000)      // payer is owed 20.00
            ->where('suggestions.0.amount', 1000) // each other owes 10.00
        );
});

it('shifts balances after a settlement is marked paid', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);

    actingAs($users[0])->post("/m/spending/events/{$event->id}/settle", [
        'from_user_id' => $users[1]->id, 'to_user_id' => $users[0]->id, 'amount' => 1000,
    ])->assertRedirect();

    expect($event->settlements()->count())->toBe(1);
});

it('records a foreign-currency spend converted to the event currency and frozen', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    // 100.00 RON at 0.2 -> 20.00 EUR base.
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'transport', 'payer_id' => $users[0]->id, 'spent_at' => '2026-06-15',
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->amount)->toBe(10000)          // original RON minor units
        ->and($spend->currency)->toBe('RON')
        ->and($spend->base_amount)->toBe(2000);  // frozen EUR base

    // Balances are in the base currency (EUR): payer is owed 2/3 of 20.00.
    actingAs($users[0])->get("/m/spending/events/{$event->id}")
        ->assertInertia(fn ($page) => $page->where('event.total', 2000)->where('balances.0.net', 1333));
});

it('does not leak events across families', function () {
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/events', ['name' => 'A trip', 'currency' => 'EUR']);
    $eventA = SpendEvent::first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->get("/m/spending/events/{$eventA->id}")->assertNotFound();
});
