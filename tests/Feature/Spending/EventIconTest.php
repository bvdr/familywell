<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// A family with the spending module enabled and active in session. Local (uniquely-named) helper so this
// file runs standalone; mirrors spendingFamily() in SpendingTest.php, which is unconditionally declared
// there and so can't be safely reused across files.
function iconSpendingFamily(): array
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

it('stores a chosen icon on a new list and returns it in the overview payload', function () {
    [, $users] = iconSpendingFamily();

    actingAs($users[0])->post('/m/spending/events', [
        'name' => 'Road Trip',
        'currency' => 'EUR',
        'icon' => 'car',
    ])->assertRedirect();

    $event = SpendEvent::withoutGlobalScopes()->firstWhere('name', 'Road Trip');
    expect($event->icon)->toBe('car');

    // The overview (index) payload exposes the key so the card can render it.
    actingAs($users[0])->get('/m/spending')->assertInertia(fn ($page) => $page
        ->component('spending/events')
        ->where('events.0.icon', 'car'));
});

it('defaults the icon to null when none is chosen and still renders the list', function () {
    [, $users] = iconSpendingFamily();

    actingAs($users[0])->post('/m/spending/events', ['name' => 'No Icon', 'currency' => 'EUR'])->assertRedirect();

    $event = SpendEvent::withoutGlobalScopes()->firstWhere('name', 'No Icon');
    expect($event->icon)->toBeNull();

    actingAs($users[0])->get('/m/spending')->assertInertia(fn ($page) => $page->where('events.0.icon', null));
    // The detail payload also carries the (null) icon; the client falls back to Wallet.
    actingAs($users[0])->get("/m/spending/events/{$event->id}")->assertInertia(fn ($page) => $page->where('event.icon', null));
});

it('rejects a junk icon key on create', function () {
    [, $users] = iconSpendingFamily();

    actingAs($users[0])->post('/m/spending/events', [
        'name' => 'Bad Icon',
        'currency' => 'EUR',
        'icon' => 'definitely-not-a-real-lucide-icon',
    ])->assertSessionHasErrors('icon');

    expect(SpendEvent::withoutGlobalScopes()->where('name', 'Bad Icon')->exists())->toBeFalse();
});

it('updates and clears the icon on an existing list', function () {
    [, $users] = iconSpendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::withoutGlobalScopes()->firstWhere('name', 'Trip');

    // Set it.
    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['icon' => 'plane'])->assertRedirect();
    expect($event->fresh()->icon)->toBe('plane');

    // Clear it back to the default (null).
    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['icon' => null])->assertRedirect();
    expect($event->fresh()->icon)->toBeNull();
});

it('rejects a junk icon key on update and leaves the stored icon untouched', function () {
    [, $users] = iconSpendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR', 'icon' => 'plane']);
    $event = SpendEvent::withoutGlobalScopes()->firstWhere('name', 'Trip');

    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['icon' => 'not an icon!!'])
        ->assertSessionHasErrors('icon');

    expect($event->fresh()->icon)->toBe('plane');
});
