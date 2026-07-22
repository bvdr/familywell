<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Spending\EventBalances;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendParticipant;
use Modules\Spending\SpendShare;

use function Pest\Laravel\actingAs;

// Wiring test: SpendParticipant (weight / covered_by) and SpendShare (per-spend subset) rows must flow
// through SpendEvent::splitSettings + EventBalances into the balances. The pure math is in BalancesTest.
function splitEvent(): array
{
    $users = User::factory()->count(3)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    CurrentFamily::set($family->id);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);

    return [$family, $users, SpendEvent::first()];
}

// key => net, computed exactly like the payload does.
function netsOf(SpendEvent $event): array
{
    $event->refresh()->unsetRelation('participantSettings');
    $spends = $event->spends()->with('shares')->get();

    return collect(EventBalances::of($event, $spends, $event->settlements()->get())['balances'])
        ->keyBy('id')->map->net->all();
}

it('weights the split by participant headcount', function () {
    [, $users, $event] = splitEvent();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);

    // Give user[1] a headcount of 2 -> total weight 4, spend 3000 splits 750 / 1500 / 750.
    SpendParticipant::create(['spend_event_id' => $event->id, 'participant_key' => 'u'.$users[1]->id, 'weight' => 2]);

    $net = netsOf($event);
    expect($net['u'.$users[0]->id])->toBe(2250)   // paid 3000, owes 750
        ->and($net['u'.$users[1]->id])->toBe(-1500)
        ->and($net['u'.$users[2]->id])->toBe(-750);
});

it('bills a covered member to their coverer', function () {
    [, $users, $event] = splitEvent();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);

    // user[2] is covered by user[0].
    SpendParticipant::create(['spend_event_id' => $event->id, 'participant_key' => 'u'.$users[2]->id, 'covered_by' => 'u'.$users[0]->id]);

    $net = netsOf($event);
    expect($net['u'.$users[2]->id])->toBe(0)       // covered -> owes nothing
        ->and($net['u'.$users[0]->id])->toBe(1000) // paid 3000, owes own 1000 + user[2]'s 1000
        ->and($net['u'.$users[1]->id])->toBe(-1000);
});

it('limits a spend to only the participants it is shared with', function () {
    [, $users, $event] = splitEvent();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    // Share this spend only with user[0] and user[1] (null amount = weighted among them).
    SpendShare::create(['spend_id' => $spend->id, 'participant_key' => 'u'.$users[0]->id, 'amount' => null]);
    SpendShare::create(['spend_id' => $spend->id, 'participant_key' => 'u'.$users[1]->id, 'amount' => null]);

    $net = netsOf($event);
    expect($net['u'.$users[0]->id])->toBe(1500)   // paid 3000, owes 1500
        ->and($net['u'.$users[1]->id])->toBe(-1500)
        ->and($net['u'.$users[2]->id])->toBe(0);  // not part of this spend
});
