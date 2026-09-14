<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendSetting;

use function Pest\Laravel\actingAs;

// Spending is expense-first (2026-09-14): the overview opens on a flat Expenses feed across every visible
// list, and "+" adds an expense straight away — with no list picked it lands on the family's single,
// auto-created "Others" list.

function expenseFamily(): array
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

it('feeds every visible spend across lists, newest first, with amounts rolled into the display currency', function () {
    Http::fake(['*' => Http::response(['rates' => ['EUR' => 0.2, 'RON' => 1, 'USD' => 0.22, 'GBP' => 0.17]])]);
    [, $users] = expenseFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'RON']);
    $trip = SpendEvent::where('name', 'Trip')->first();
    $home = SpendEvent::where('name', 'Home')->first();
    actingAs($users[0])->patch('/m/spending/settings', ['default_currency' => 'EUR']);

    // Older spend first, so "newest first" is a real ordering check (not insertion order).
    actingAs($users[0])->post("/m/spending/events/{$trip->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-09-01', 'note' => 'Lunch',
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$home->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'other', 'payer_key' => 'u'.$users[1]->id, 'spent_at' => '2026-09-05',
    ])->assertRedirect();

    actingAs($users[0])->get('/m/spending')->assertInertia(fn ($p) => $p
        ->component('spending/events')
        ->where('expenses', fn ($rows) => collect($rows)->pluck('list')->all() === ['Home', 'Trip']
            && $rows[0]['amount'] === 10000 && $rows[0]['currency'] === 'RON'
            && $rows[0]['converted'] === 2000 // 100 RON -> 20 EUR at the faked rate
            && $rows[0]['payer'] === $users[1]->name
            && $rows[0]['list_id'] === $home->id
            && $rows[1]['note'] === 'Lunch' && $rows[1]['category'] === 'food' && $rows[1]['converted'] === 2000)
        ->where('thisMonth.currency', 'EUR')
        ->where('feedCap', 200)
        ->etc());
});

it('keeps the feed family-scoped and hides spends from lists the viewer cannot see', function () {
    [, $users] = expenseFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Mine', 'currency' => 'EUR']);
    $mine = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$mine->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-09-01',
    ]);
    // Narrow the list to private: the other member no longer sees it, nor its spends.
    $mine->setVisibility('private');

    actingAs($users[1])->get('/m/spending')->assertInertia(fn ($p) => $p->where('expenses', [])->etc());
    actingAs($users[0])->get('/m/spending')->assertInertia(fn ($p) => $p->where('expenses', fn ($r) => count($r) === 1)->etc());

    // Another family sees nothing of ours.
    $other = User::factory()->create();
    $otherFamily = Family::factory()->create(['owner_id' => $other->id]);
    $otherFamily->members()->attach($other, ['role' => 'admin']);
    app(ModuleRegistry::class)->enable('spending', $otherFamily->id);
    session(['active_family_id' => $otherFamily->id]);
    actingAs($other)->get('/m/spending')->assertInertia(fn ($p) => $p->where('expenses', [])->where('events', [])->etc());
});

it('adds an expense with no list to a single auto-created Others list, idempotently', function () {
    [$family, $users] = expenseFamily();
    actingAs($users[0])->patch('/m/spending/settings', ['default_currency' => 'RON']);

    $add = fn (User $u, string $amount) => actingAs($u)->post('/m/spending/spends', [
        'amount' => $amount, 'currency' => 'RON', 'category' => 'food', 'payer_key' => 'u'.$u->id, 'spent_at' => '2026-09-10',
    ]);
    $add($users[0], '10.00')->assertRedirect()->assertSessionHasNoErrors();
    $add($users[1], '15.00')->assertRedirect()->assertSessionHasNoErrors(); // a different member: still the same list

    expect(SpendEvent::count())->toBe(1);
    $others = SpendEvent::first();
    expect($others->name)->toBe('Others')
        ->and($others->currency)->toBe('RON') // family default currency
        ->and($others->members)->toHaveCount(2) // everyone joins, like a hand-made list
        ->and($others->visibility())->toBe('family')
        ->and(SpendSetting::current()->others_event_id)->toBe($others->id)
        ->and(Spend::where('spend_event_id', $others->id)->count())->toBe(2)
        ->and($others->shares()->where('role', 'owner')->value('user_id'))->toBe($users[0]->id);

    // Archived pointer target => a fresh one is created and the pointer healed; the archived one is left alone.
    $others->forceFill(['archived_at' => now()])->save();
    $add($users[0], '1.00')->assertRedirect()->assertSessionHasNoErrors();
    expect(SpendEvent::count())->toBe(2)
        ->and(SpendSetting::current()->others_event_id)->not->toBe($others->id)
        ->and(SpendEvent::whereNull('archived_at')->count())->toBe(1);
});

it('adopts a hand-made Others list in any locale instead of creating a second one', function () {
    [, $users] = expenseFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Altele', 'currency' => 'EUR']);
    $altele = SpendEvent::first();

    actingAs($users[0])->post('/m/spending/spends', [
        'amount' => '3.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-09-10',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SpendEvent::count())->toBe(1)
        ->and(Spend::first()->spend_event_id)->toBe($altele->id)
        ->and(SpendSetting::current()->others_event_id)->toBe($altele->id);
});

it('adds an expense to the chosen list and rejects one the viewer cannot edit or another family owns', function () {
    [, $users] = expenseFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $trip = SpendEvent::first();

    actingAs($users[1])->post('/m/spending/spends', [
        'event_id' => $trip->id, 'amount' => '7.50', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[1]->id, 'spent_at' => '2026-09-10',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(SpendEvent::count())->toBe(1) // no Others list was created
        ->and(Spend::first()->spend_event_id)->toBe($trip->id)
        ->and(Spend::first()->amount)->toBe(750);

    // Viewer-only member: 403, same as the per-list endpoint.
    $trip->setVisibility('invited');
    $trip->shares()->create(['user_id' => $users[1]->id, 'role' => 'viewer']);
    actingAs($users[1])->post('/m/spending/spends', [
        'event_id' => $trip->id, 'amount' => '1.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[1]->id, 'spent_at' => '2026-09-10',
    ])->assertForbidden();

    // A list id from another family 404s (family-scoped resolve), never leaks a spend into it.
    $other = User::factory()->create();
    $otherFamily = Family::factory()->create(['owner_id' => $other->id]);
    $otherFamily->members()->attach($other, ['role' => 'admin']);
    app(ModuleRegistry::class)->enable('spending', $otherFamily->id);
    session(['active_family_id' => $otherFamily->id]);
    actingAs($other)->post('/m/spending/spends', [
        'event_id' => $trip->id, 'amount' => '1.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$other->id, 'spent_at' => '2026-09-10',
    ])->assertNotFound();
    expect(Spend::withoutGlobalScopes()->count())->toBe(1);
});
