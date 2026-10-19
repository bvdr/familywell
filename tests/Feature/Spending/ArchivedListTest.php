<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// An archived list is read-only for everyone (SpendEvent::assertWritable, enforced in the Spend and
// SpendSettlement model events). Why: on 2026-10-19 a stranger used an archived list's old, open
// guest link to add a EUR 2.3M spend.

// Two-member family with one list that already has a member spend + an editor guest on an open link.
function archivedListFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-07-16',
    ])->assertRedirect();
    $link = $event->enableGuestLink($users[0]->id);
    $guest = $event->guests()->create(['name' => 'Alex', 'role' => 'editor']);

    return [$users, $event, $link, $guest];
}

function archive(SpendEvent $event, User $by, bool $archived = true): void
{
    actingAs($by)->patch("/m/spending/events/{$event->id}", ['archived' => $archived])->assertRedirect();
}

it('blocks a guest from adding, editing, deleting or settling on an archived list', function () {
    [$users, $event, $link, $guest] = archivedListFamily();
    $base = "/m/spending/guest/{$link->token}";
    $spend = ['guest_id' => $guest->id, 'amount' => '2342342', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-10-19'];

    // While live the guest can add, so they have an own spend to try editing/deleting later.
    $this->post("{$base}/spends", $spend)->assertRedirect();
    $own = $event->spends()->where('payer_guest_id', $guest->id)->first();

    archive($event, $users[0]);

    // add_anyway: skip the soft duplicate warning so the request reaches the archive guard.
    $this->post("{$base}/spends", [...$spend, 'add_anyway' => 1])->assertSessionHasErrors('archived');
    $this->patch("{$base}/spends/{$own->id}", [...$spend, 'amount' => '1'])->assertSessionHasErrors('archived');
    $this->delete("{$base}/spends/{$own->id}", ['guest_id' => $guest->id])->assertSessionHasErrors('archived');
    $this->post("{$base}/settle", [
        'guest_id' => $guest->id, 'from_key' => 'g'.$guest->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 500,
    ])->assertSessionHasErrors('archived');

    expect($event->spends()->count())->toBe(2)
        ->and($own->fresh()->amount)->toBe(234234200)
        ->and($event->settlements()->count())->toBe(0);
});

it('tells the guest page the list is archived', function () {
    [$users, $event, $link] = archivedListFamily();

    $this->get("/g/{$link->token}")->assertInertia(fn ($page) => $page->where('event.archived', false));
    archive($event, $users[0]);
    $this->get("/g/{$link->token}")->assertInertia(fn ($page) => $page->where('event.archived', true));
});

it('blocks members from adding, editing, deleting, moving or settling on an archived list', function () {
    [$users, $event] = archivedListFamily();
    $spend = $event->spends()->first();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Other', 'currency' => 'EUR']);
    $other = SpendEvent::where('name', 'Other')->first();

    archive($event, $users[0]);

    $input = ['amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-10-19'];
    actingAs($users[1])->post("/m/spending/events/{$event->id}/spends", $input)->assertSessionHasErrors('archived');
    actingAs($users[0])->patch("/m/spending/spends/{$spend->id}", $input)->assertSessionHasErrors('archived');
    actingAs($users[0])->delete("/m/spending/spends/{$spend->id}")->assertSessionHasErrors('archived');
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/move", ['target_event_id' => $other->id])->assertSessionHasErrors('archived');
    actingAs($users[0])->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'u'.$users[1]->id, 'to_key' => 'u'.$users[0]->id, 'amount' => 500,
    ])->assertSessionHasErrors('archived');

    expect($event->spends()->count())->toBe(1)
        ->and($spend->fresh()->amount)->toBe(1000)
        ->and($spend->fresh()->spend_event_id)->toBe($event->id)
        ->and($event->settlements()->count())->toBe(0);

    // Moving a spend INTO an archived list is blocked too (the guard checks old and new list).
    actingAs($users[0])->post("/m/spending/events/{$other->id}/spends", $input)->assertRedirect();
    $live = $other->spends()->first();
    actingAs($users[0])->post("/m/spending/spends/{$live->id}/move", ['target_event_id' => $event->id])->assertSessionHasErrors('archived');
    expect($live->fresh()->spend_event_id)->toBe($other->id);
});

it('blocks every write path at the model, not just the routes', function () {
    [$users, $event] = archivedListFamily();
    archive($event, $users[0]);

    // MCP tools, imports and anything else that creates rows go through the same model events.
    expect(fn () => $event->spends()->create([...$event->buildSpend([
        'amount' => '1', 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-10-19',
    ]), 'payer_id' => $users[0]->id, 'created_by' => $users[0]->id]))->toThrow(ValidationException::class);
});

it('allows writes again once the list is unarchived, and trash restore + purge while archived', function () {
    [$users, $event] = archivedListFamily();
    $spend = $event->spends()->first();
    actingAs($users[0])->delete("/m/spending/spends/{$spend->id}")->assertRedirect(); // trashed while live

    archive($event, $users[0]);
    $spend = Spend::withTrashed()->find($spend->id); // as the trash screen loads it
    $spend->restore(); // a trash restore puts back what was there; not a new write
    expect($spend->fresh()->trashed())->toBeFalse();
    $spend->forceDelete(); // the 30-day purge must not be blocked by the archive either
    expect($event->spends()->withTrashed()->count())->toBe(0);

    archive($event, $users[0], false);
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-10-19',
    ])->assertSessionHasNoErrors();
    expect($event->spends()->count())->toBe(1);
});
