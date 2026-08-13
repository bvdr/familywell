<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// A recorded transfer moves money between two people in the balances, so the settle endpoint's guards
// (no self-transfer, positive amount, real participant) are money-integrity checks. Prove each rejects
// and writes nothing, so a bad request can never quietly skew who-owes-who.

// Owner + two members, spending enabled, one event. Returns [owner, members[], event].
function settleFamily(): array
{
    $users = User::factory()->count(3)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);

    return [$users[0], $users, SpendEvent::first()];
}

it('rejects a transfer from a participant to themselves', function () {
    [$owner, $users, $event] = settleFamily();

    actingAs($owner)->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'u'.$users[0]->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 1000,
    ])->assertSessionHasErrors('to_key');

    expect($event->settlements()->count())->toBe(0);
});

it('rejects a non-positive transfer amount', function () {
    [$owner, $users, $event] = settleFamily();
    $payload = ['from_key' => 'u'.$users[1]->id, 'to_key' => 'u'.$users[0]->id];

    actingAs($owner)->post("/m/spending/events/{$event->id}/settle", $payload + ['amount' => 0])
        ->assertSessionHasErrors('amount');
    actingAs($owner)->post("/m/spending/events/{$event->id}/settle", $payload + ['amount' => -500])
        ->assertSessionHasErrors('amount');

    expect($event->settlements()->count())->toBe(0);
});

it('rejects a transfer naming someone who is not a participant', function () {
    [$owner, $users, $event] = settleFamily();

    // 'u999999' is a valid key shape but no such participant exists on this event.
    actingAs($owner)->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'u999999', 'to_key' => 'u'.$users[0]->id, 'amount' => 1000,
    ])->assertSessionHasErrors('from_key');

    expect($event->settlements()->count())->toBe(0);
});

it('records a valid transfer between two participants', function () {
    [$owner, $users, $event] = settleFamily();

    actingAs($owner)->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'u'.$users[1]->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 1500, 'note' => 'cash',
    ])->assertRedirect();

    $t = $event->settlements()->sole();
    expect($t->from_key)->toBe('u'.$users[1]->id)
        ->and($t->to_key)->toBe('u'.$users[0]->id)
        ->and($t->amount)->toBe(1500)
        ->and($t->note)->toBe('cash');
});
