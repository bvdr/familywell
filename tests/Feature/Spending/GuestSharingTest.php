<?php

use App\Models\Family;
use App\Models\Guest;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// Reuse the same three-member family setup as SpendingTest (owner = users[0]).
function guestFamily(): array
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

    return [$family, $users, SpendEvent::first()];
}

it('lets the owner enable a guest link and add guests, but blocks non-owners', function () {
    [, $users, $event] = guestFamily();
    $base = "/guest-links/spend_event/{$event->id}";

    actingAs($users[0])->patch($base, ['enabled' => true])->assertRedirect();
    actingAs($users[0])->post("{$base}/guests", ['name' => 'Alex', 'role' => 'editor'])->assertRedirect();

    $event->refresh();
    expect($event->guestLink)->not->toBeNull()
        ->and(strlen($event->guestLink->token))->toBe(48)
        ->and($event->guests)->toHaveCount(1);

    // A different family member is not the owner, so they cannot manage guest sharing.
    actingAs($users[1])->post("{$base}/guests", ['name' => 'Mallory', 'role' => 'editor'])->assertForbidden();
});

it('serves the guest page with no auth via the link token', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);

    $this->get("/g/{$link->token}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('spending/guest-event')
        ->where('token', $link->token)
        ->has('guests', 1)
    );
});

it('404s an unknown link token', function () {
    guestFamily();
    $this->get('/g/nope-not-a-real-token')->assertNotFound();
});

it('gates a password-protected link and unlocks it in the session', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    actingAs($users[0])->patch("/guest-links/spend_event/{$event->id}/password", ['password' => 'secret']);

    // Locked: the guest page is replaced by the unlock screen.
    $this->get("/g/{$link->token}")->assertInertia(fn ($page) => $page->component('guests/locked'));

    // Wrong password stays locked; correct password unlocks and redirects to the entity.
    $this->post("/g/{$link->token}/unlock", ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post("/g/{$link->token}/unlock", ['password' => 'secret'])->assertRedirect("/g/{$link->token}");

    // The session now clears the gate.
    $this->get("/g/{$link->token}")->assertInertia(fn ($page) => $page->component('spending/guest-event'));
});

it('lets an editor guest add a spend that lands in the balances', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);

    $this->post("/m/spending/guest/{$link->token}/spends", [
        'guest_id' => $alex->id,
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-07-16',
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->payer_guest_id)->toBe($alex->id)
        ->and($spend->amount)->toBe(3000)
        ->and($spend->payer_id)->toBeNull();

    // Alex paid 30, split among the 3 members + Alex (4 ways) -> net +22.50.
    $this->get("/g/{$link->token}")->assertInertia(fn ($page) => $page
        ->where('balances', fn ($b) => collect($b)->firstWhere('id', 'g'.$alex->id)['net'] === 2250)
    );
});

it('lets the owner record a transfer involving a guest', function () {
    [, $users, $event] = guestFamily();
    $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);

    // Owner records that the guest paid them back, with a note.
    actingAs($users[0])->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'g'.$alex->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 1500, 'note' => 'cash',
    ])->assertRedirect();

    $t = $event->settlements()->first();
    expect($t->from_key)->toBe('g'.$alex->id)
        ->and($t->to_key)->toBe('u'.$users[0]->id)
        ->and($t->amount)->toBe(1500)
        ->and($t->note)->toBe('cash');
});

it('lets an editor guest record a transfer involving themselves', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);

    $this->post("/m/spending/guest/{$link->token}/settle", [
        'guest_id' => $alex->id,
        'from_key' => 'g'.$alex->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 2000, 'note' => 'venmo',
    ])->assertRedirect();

    $t = $event->settlements()->first();
    expect($t->from_key)->toBe('g'.$alex->id)
        ->and($t->to_key)->toBe('u'.$users[0]->id)
        ->and($t->note)->toBe('venmo');
});

it('forbids a guest from recording a transfer they are not part of', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);

    // Alex tries to record a payment between two OTHER members — not allowed.
    $this->post("/m/spending/guest/{$link->token}/settle", [
        'guest_id' => $alex->id,
        'from_key' => 'u'.$users[1]->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 500,
    ])->assertForbidden();

    expect($event->settlements()->count())->toBe(0);
});

it('forbids a view-only guest from writing', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $sam = $event->guests()->create(['name' => 'Sam', 'role' => 'viewer']);

    $this->post("/m/spending/guest/{$link->token}/spends", [
        'guest_id' => $sam->id,
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-07-16',
    ])->assertForbidden();

    expect($event->spends()->count())->toBe(0);
});

it('lets an editor guest touch only their own spends', function () {
    [, $users, $event] = guestFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);
    $bea = $event->guests()->create(['name' => 'Bea', 'role' => 'editor']);

    // Bea's spend.
    $spend = $event->spends()->create([
        'payer_guest_id' => $bea->id, 'family_id' => $event->family_id, 'created_by' => $event->created_by,
        ...$event->buildSpend(['amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-07-16']),
    ]);

    // Alex cannot edit or delete Bea's spend.
    $this->patch("/m/spending/guest/{$link->token}/spends/{$spend->id}", [
        'guest_id' => $alex->id, 'amount' => '99.00', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-07-16',
    ])->assertNotFound();
    $this->delete("/m/spending/guest/{$link->token}/spends/{$spend->id}", ['guest_id' => $alex->id])->assertNotFound();

    // Bea can delete her own.
    $this->delete("/m/spending/guest/{$link->token}/spends/{$spend->id}", ['guest_id' => $bea->id])->assertRedirect();
    expect($event->spends()->count())->toBe(0);
});

it('cascades guest deletion to the spends they added', function () {
    [, $users, $event] = guestFamily();
    $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);
    $event->spends()->create([
        'payer_guest_id' => $alex->id, 'family_id' => $event->family_id, 'created_by' => $event->created_by,
        ...$event->buildSpend(['amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-07-16']),
    ]);

    $alex->delete();
    expect($event->spends()->count())->toBe(0)
        ->and(Guest::count())->toBe(0);
});
