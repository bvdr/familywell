<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// Soft duplicate warning on add: same amount + day + shop (or category) as a spend the viewer can see
// answers with a validation message on `add_anyway`; re-posting with add_anyway=1 always goes through.

function dupSpendingFamily(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    actingAs($user)->post('/m/spending/events', ['name' => 'Home', 'currency' => 'EUR']);

    return [$user, SpendEvent::withoutGlobalScopes()->where('name', 'Home')->first()];
}

function spendPayload(User $user, array $over = []): array
{
    return ['amount' => '12.50', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$user->id, 'spent_at' => '2026-09-20', 'store' => 'Lidl', ...$over];
}

it('warns on the same amount, date and shop, but still adds with add_anyway', function () {
    [$user, $event] = dupSpendingFamily();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user))->assertSessionDoesntHaveErrors();

    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['store' => 'lidl']))
        ->assertSessionHasErrors(['add_anyway' => 'This looks like a duplicate of an expense you already added.']);
    expect(Spend::withoutGlobalScopes()->count())->toBe(1);

    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['add_anyway' => 1]))->assertSessionDoesntHaveErrors();
    expect(Spend::withoutGlobalScopes()->count())->toBe(2);
});

it('does not warn when the amount, date or shop differs', function () {
    [$user, $event] = dupSpendingFamily();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user));

    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['amount' => '12.51']))->assertSessionDoesntHaveErrors();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['spent_at' => '2026-09-21']))->assertSessionDoesntHaveErrors();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['store' => 'Kaufland']))->assertSessionDoesntHaveErrors();
    expect(Spend::withoutGlobalScopes()->count())->toBe(4);
});

it('falls back to the category when no shop is given, and matches across the family lists', function () {
    [$user, $event] = dupSpendingFamily();
    actingAs($user)->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $trip = SpendEvent::withoutGlobalScopes()->where('name', 'Trip')->first();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['store' => '']));

    // Same category, no shop, other list of the same family: still looks like a duplicate.
    actingAs($user)->post("/m/spending/events/{$trip->id}/spends", spendPayload($user, ['store' => '']))->assertSessionHasErrors('add_anyway');
    // A different category is a different purchase.
    actingAs($user)->post("/m/spending/events/{$trip->id}/spends", spendPayload($user, ['store' => '', 'category' => 'other']))->assertSessionDoesntHaveErrors();
});

it('is family-scoped: another family\'s identical spend does not warn', function () {
    [$user, $event] = dupSpendingFamily();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user));

    $other = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $other->id]);
    $family->members()->attach($other, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    actingAs($other)->post('/m/spending/events', ['name' => 'Home', 'currency' => 'EUR']);
    $theirs = SpendEvent::withoutGlobalScopes()->where('family_id', $family->id)->first();

    actingAs($other)->post("/m/spending/events/{$theirs->id}/spends", spendPayload($other))->assertSessionDoesntHaveErrors();
});

it('warns when editing a spend into a copy of another one, but never against itself', function () {
    [$user, $event] = dupSpendingFamily();
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user));
    actingAs($user)->post("/m/spending/events/{$event->id}/spends", spendPayload($user, ['amount' => '3.00']));
    [$first, $second] = Spend::withoutGlobalScopes()->orderBy('id')->get();

    // Re-saving a spend unchanged is not a duplicate of itself.
    actingAs($user)->patch("/m/spending/spends/{$first->id}", spendPayload($user))->assertSessionDoesntHaveErrors();
    // Editing the second into the first's amount warns; add_anyway saves.
    actingAs($user)->patch("/m/spending/spends/{$second->id}", spendPayload($user))->assertSessionHasErrors('add_anyway');
    expect($second->fresh()->amount)->toBe(300);
    actingAs($user)->patch("/m/spending/spends/{$second->id}", spendPayload($user, ['add_anyway' => 1]))->assertSessionDoesntHaveErrors();
    expect($second->fresh()->amount)->toBe(1250);
});

it('warns a guest adding through the link, bounded to that list', function () {
    [$user, $event] = dupSpendingFamily();
    $link = $event->enableGuestLink($user->id);
    $alex = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);
    $payload = ['guest_id' => $alex->id, 'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-07-16'];

    test()->post("/m/spending/guest/{$link->token}/spends", $payload)->assertSessionDoesntHaveErrors();
    test()->post("/m/spending/guest/{$link->token}/spends", $payload)->assertSessionHasErrors('add_anyway');
    expect(Spend::withoutGlobalScopes()->count())->toBe(1);
    test()->post("/m/spending/guest/{$link->token}/spends", $payload + ['add_anyway' => 1])->assertSessionDoesntHaveErrors();
    expect(Spend::withoutGlobalScopes()->count())->toBe(2);
});
