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
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
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
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
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
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
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

it('drops family members without access from the split, but keeps anyone who paid', function () {
    [, $users, $event] = splitEvent(); // `family` visibility, 3 members, owner = users[0]

    // By default (family visibility) everyone is in the split.
    expect(array_keys($event->participantNames()))->toContain('u'.$users[1]->id);

    // users[1] fronts money while still a participant (the payer must be a current participant).
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[1]->id), 'spent_at' => '2026-07-16',
    ]);

    // Narrow to invited: only the owner holds a share. users[2] (no access, never paid) drops out;
    // users[1] stays because their payment must still be attributed and settled.
    $event->setVisibility('invited');
    $keys = array_keys($event->fresh()->participantNames());
    expect($keys)->toContain('u'.$users[0]->id)
        ->and($keys)->toContain('u'.$users[1]->id)
        ->and($keys)->not->toContain('u'.$users[2]->id);
});

it('sets a headcount through the endpoint and clears it back to default', function () {
    [, $users, $event] = splitEvent();
    $key = 'u'.$users[1]->id;

    actingAs($users[0])->patch("/m/spending/events/{$event->id}/participants/{$key}", ['weight' => 3, 'covered_by' => ''])->assertRedirect();
    expect(SpendParticipant::where('participant_key', $key)->value('weight'))->toBe(3);

    // weight 1 + no cover is the default, so the row is removed (table stays sparse).
    actingAs($users[0])->patch("/m/spending/events/{$event->id}/participants/{$key}", ['weight' => 1, 'covered_by' => '']);
    expect(SpendParticipant::where('participant_key', $key)->exists())->toBeFalse();
});

it('stores a spend split among only some participants via the form', function () {
    [, $users, $event] = splitEvent();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'split' => 'some', 'shares' => [['key' => 'u'.$users[0]->id], ['key' => 'u'.$users[1]->id]],
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->shares()->count())->toBe(2)
        ->and($spend->shares()->whereNotNull('amount')->count())->toBe(0); // 'some' rows carry no amount

    $net = netsOf($event);
    expect($net['u'.$users[0]->id])->toBe(1500)
        ->and($net['u'.$users[1]->id])->toBe(-1500)
        ->and($net['u'.$users[2]->id])->toBe(0); // excluded from this spend
});

it('stores exact per-person amounts (spend currency) and bills them', function () {
    [, $users, $event] = splitEvent();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'split' => 'exact', 'shares' => [
            ['key' => 'u'.$users[0]->id, 'amount' => '20'],
            ['key' => 'u'.$users[1]->id, 'amount' => '10'],
            ['key' => 'u'.$users[2]->id, 'amount' => '0'],
        ],
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect((int) $spend->shares()->where('participant_key', 'u'.$users[0]->id)->value('amount'))->toBe(2000); // stored in spend currency minor units

    $net = netsOf($event);
    expect($net['u'.$users[0]->id])->toBe(1000)   // paid 3000, owes 2000
        ->and($net['u'.$users[1]->id])->toBe(-1000)
        ->and($net['u'.$users[2]->id])->toBe(0);
});

it('validates cover targets: no self-cover and no chains', function () {
    [, $users, $event] = splitEvent();
    [$a, $b, $c] = ['u'.$users[0]->id, 'u'.$users[1]->id, 'u'.$users[2]->id];

    // Can't cover yourself.
    actingAs($users[0])->patch("/m/spending/events/{$event->id}/participants/{$a}", ['weight' => 1, 'covered_by' => $a])->assertStatus(422);

    // a covers b (fine).
    actingAs($users[0])->patch("/m/spending/events/{$event->id}/participants/{$b}", ['weight' => 1, 'covered_by' => $a])->assertRedirect();

    // a now covers someone, so a itself can't be covered.
    actingAs($users[0])->patch("/m/spending/events/{$event->id}/participants/{$a}", ['weight' => 1, 'covered_by' => $c])->assertStatus(422);

    // b is covered, so nobody can be covered by b.
    actingAs($users[0])->patch("/m/spending/events/{$event->id}/participants/{$c}", ['weight' => 1, 'covered_by' => $b])->assertStatus(422);
});
