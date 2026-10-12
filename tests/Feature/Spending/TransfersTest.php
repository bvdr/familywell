<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Inertia\Testing\AssertableInertia;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendSettlement;

use function Pest\Laravel\actingAs;

// Transfers (settle-up payments): the soft "already recorded?" warning that stops a re-entered transfer
// from silently landing twice (list 1896, 2026-10-12), plus edit / delete-to-trash / restore.

// Own helper (Pest only loads the files it runs, so a cross-file helper breaks single-file runs).
function transfersFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Container', 'currency' => 'RON']);

    return [$family, $users, SpendEvent::first()];
}

function payRazvan(SpendEvent $event, User $as, string $to, array $extra = [])
{
    return actingAs($as)->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'u'.$as->id, 'to_key' => $to, 'amount' => 670000, ...$extra,
    ]);
}

it('warns instead of silently recording the same transfer twice, and records it on "anyway"', function () {
    [, $users, $event] = transfersFamily();
    $razvan = 'g'.$event->guests()->create(['name' => 'Razvan', 'role' => 'editor'])->id;

    payRazvan($event, $users[0], $razvan)->assertRedirect()->assertSessionHasNoErrors();
    payRazvan($event, $users[0], $razvan)->assertSessionHasErrors('add_anyway');
    expect($event->settlements()->count())->toBe(1);

    // A different amount is a different payment: no warning.
    payRazvan($event, $users[0], $razvan, ['amount' => 100000])->assertSessionHasNoErrors();
    // Confirmed: recorded again.
    payRazvan($event, $users[0], $razvan, ['add_anyway' => true])->assertSessionHasNoErrors();
    expect($event->settlements()->count())->toBe(3);

    // A day later the same payment is plausible again, so no warning.
    $this->travel(25)->hours();
    payRazvan($event, $users[0], $razvan)->assertSessionHasNoErrors();
});

it('applies the same warning to a guest recording a transfer through the guest link', function () {
    [, $users, $event] = transfersFamily();
    $link = $event->enableGuestLink($users[0]->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);
    $pay = fn (array $extra = []) => $this->post("/m/spending/guest/{$link->token}/settle", [
        'guest_id' => $alex->id, 'from_key' => 'g'.$alex->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 2000, ...$extra,
    ]);

    $pay()->assertSessionHasNoErrors();
    $pay()->assertSessionHasErrors('add_anyway');
    $pay(['add_anyway' => true])->assertSessionHasNoErrors();
    expect($event->settlements()->count())->toBe(2);
});

it('edits and deletes a transfer, recomputing balances, and restores it from the trash', function () {
    [, $users, $event] = transfersFamily();
    $razvan = 'g'.$event->guests()->create(['name' => 'Razvan', 'role' => 'editor'])->id;
    payRazvan($event, $users[0], $razvan);
    $transfer = SpendSettlement::withoutGlobalScopes()->first();
    $netOf = fn () => collect(actingAs($users[0])->get("/m/spending/events/{$event->id}")->viewData('page')['props']['balances'])
        ->firstWhere('id', 'u'.$users[0]->id)['net'];
    expect($netOf())->toBe(670000);

    // Edit: amount + note change, and editing it into itself is not a "duplicate".
    actingAs($users[0])->patch("/m/spending/transfers/{$transfer->id}", [
        'from_key' => 'u'.$users[0]->id, 'to_key' => $razvan, 'amount' => 500000, 'note' => 'cash',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($transfer->fresh())->amount->toBe(500000)->note->toBe('cash')
        ->and($netOf())->toBe(500000);

    // Delete: gone from the list and the balances, but in the trash.
    actingAs($users[0])->delete("/m/spending/transfers/{$transfer->id}")->assertRedirect();
    expect($event->settlements()->count())->toBe(0)->and($netOf())->toBe(0);
    actingAs($users[0])->get('/settings/recently-deleted')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('items', fn ($items) => collect($items)->contains(fn ($i) => $i['type'] === 'transfer'
            && $i['title'] === $users[0]->name.' → Razvan' && $i['amount'] === '5000.00 RON')));

    actingAs($users[0])->post("/settings/recently-deleted/spending/transfer/{$transfer->id}")->assertRedirect();
    expect($event->settlements()->count())->toBe(1)->and($netOf())->toBe(500000);
});

it('keeps transfers of other families and other people\'s lists out of reach', function () {
    [, $users, $event] = transfersFamily();
    $razvan = 'g'.$event->guests()->create(['name' => 'Razvan', 'role' => 'editor'])->id;
    payRazvan($event, $users[0], $razvan);
    $transfer = SpendSettlement::withoutGlobalScopes()->first();

    // A private list of someone else: no edit, no delete.
    $event->setVisibility('private');
    actingAs($users[1])->delete("/m/spending/transfers/{$transfer->id}")->assertForbidden();

    // Another family: not found (family-scoped resolve).
    $stranger = User::factory()->create();
    $other = Family::factory()->create(['owner_id' => $stranger->id]);
    $other->members()->attach($stranger, ['role' => 'admin']);
    app(ModuleRegistry::class)->enable('spending', $other->id);
    session(['active_family_id' => $other->id]);
    actingAs($stranger)->delete("/m/spending/transfers/{$transfer->id}")->assertNotFound();
    expect(SpendSettlement::withoutGlobalScopes()->count())->toBe(1);
});
