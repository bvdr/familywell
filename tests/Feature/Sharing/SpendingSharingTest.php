<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// A family with the spending module enabled and active in session. users[0] is the admin/creator.
function sharingFamily(int $members = 3): array
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

function makeEvent(User $owner): SpendEvent
{
    actingAs($owner)->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR'])->assertRedirect();

    return SpendEvent::latest('id')->first();
}

function validSpend(User $payer): array
{
    return ['amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $payer->id, 'spent_at' => '2026-07-16'];
}

it('makes the creator the owner', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);

    expect($event->shares()->where('user_id', $u[0]->id)->value('role'))->toBe('owner');
});

it('lets any family member view and add spends by default (family visibility)', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);

    actingAs($u[1])->get("/m/spending/events/{$event->id}")->assertOk();
    actingAs($u[1])->post("/m/spending/events/{$event->id}/spends", validSpend($u[1]))->assertRedirect();
    expect($event->spends()->count())->toBe(1);
});

it('hides a private event from other members but not the owner', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);
    $event->setVisibility('private');

    actingAs($u[1])->get("/m/spending/events/{$event->id}")->assertForbidden();
    actingAs($u[0])->get("/m/spending/events/{$event->id}")->assertOk();
});

it('lets a viewer read but not add spends', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);
    $event->setVisibility('invited');
    $event->shares()->create(['user_id' => $u[1]->id, 'role' => 'viewer']);

    actingAs($u[1])->get("/m/spending/events/{$event->id}")->assertOk();
    actingAs($u[1])->post("/m/spending/events/{$event->id}/spends", validSpend($u[1]))->assertForbidden();

    // A member with no share sees nothing on an invited event.
    actingAs($u[2])->get("/m/spending/events/{$event->id}")->assertForbidden();
});

it('only lets the owner change visibility', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);

    actingAs($u[1])->patch("/shares/spend_event/{$event->id}/visibility", ['visibility' => 'private'])->assertForbidden();
    actingAs($u[0])->patch("/shares/spend_event/{$event->id}/visibility", ['visibility' => 'private'])->assertRedirect();

    expect(SpendEvent::find($event->id)->visibility())->toBe('private');
});

it('serves a public read-only view via an invite link with no auth', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);
    $link = $event->shareLinks()->create(['created_by' => $u[0]->id]);

    get("/e/{$link->token}")->assertOk(); // guest, no actingAs
});

it('404s an unknown or revoked link token', function () {
    sharingFamily();
    get('/e/does-not-exist')->assertNotFound();
});

it('renders event stats for someone who can view and 403s those who cannot', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);
    actingAs($u[0])->post("/m/spending/events/{$event->id}/spends", validSpend($u[0]))->assertRedirect();

    actingAs($u[1])->get("/m/spending/events/{$event->id}/stats")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('spending/stats')->has('byCategory')->has('byPayer')->has('facts'));

    $event->setVisibility('private');
    actingAs($u[1])->get("/m/spending/events/{$event->id}/stats")->assertForbidden();
});

it('surfaces accessible spend events as dashboard items and hides private ones', function () {
    [, $u] = sharingFamily();
    $event = makeEvent($u[0]);

    // family visibility: the event shows for another member
    actingAs($u[1])->get('/dashboard')->assertInertia(fn ($p) => $p->component('dashboard')->has('items', 1)->where('items.0.title', 'Trip'));

    // private: gone for the other member, still there for the owner
    $event->setVisibility('private');
    actingAs($u[1])->get('/dashboard')->assertInertia(fn ($p) => $p->has('items', 0));
    actingAs($u[0])->get('/dashboard')->assertInertia(fn ($p) => $p->has('items', 1));
});
