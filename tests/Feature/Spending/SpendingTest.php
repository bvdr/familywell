<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

// A family with three members, the spending module installed and enabled, and it active in session.
function spendingFamily(int $members = 3): array
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

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/spending')->assertNotFound();
});

it('lists the spending page when enabled', function () {
    [$family, $users] = spendingFamily();

    actingAs($users[0])->get('/m/spending')->assertOk();
});

it('creates an event with all family members as participants', function () {
    [$family, $users] = spendingFamily();

    actingAs($users[0])->post('/m/spending/events', [
        'name' => 'Greece 2026', 'currency' => 'EUR',
    ])->assertRedirect();

    $event = SpendEvent::first();
    expect($event->name)->toBe('Greece 2026')
        ->and($event->members)->toHaveCount(3);
});

it('records spends and reports balances and settle-up suggestions', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    // user[0] pays 30.00, split three ways.
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ])->assertRedirect();

    expect($event->spends()->first()->amount)->toBe(3000); // stored as cents

    actingAs($users[0])->get("/m/spending/events/{$event->id}")
        ->assertInertia(fn ($page) => $page
            ->component('spending/event')
            ->where('event.total', 3000)
            ->where('balances.0.net', 2000)      // payer is owed 20.00
            ->where('suggestions.0.amount', 1000) // each other owes 10.00
        );
});

it('shifts balances after a settlement is marked paid', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);

    actingAs($users[0])->post("/m/spending/events/{$event->id}/settle", [
        'from_user_id' => $users[1]->id, 'to_user_id' => $users[0]->id, 'amount' => 1000,
    ])->assertRedirect();

    expect($event->settlements()->count())->toBe(1);
});

it('records a foreign-currency spend converted to the event currency and frozen', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    // 100.00 RON at 0.2 -> 20.00 EUR base.
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'transport', 'payer_id' => $users[0]->id, 'spent_at' => '2026-06-15',
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->amount)->toBe(10000)          // original RON minor units
        ->and($spend->currency)->toBe('RON')
        ->and($spend->base_amount)->toBe(2000);  // frozen EUR base

    // Balances are in the base currency (EUR): payer is owed 2/3 of 20.00.
    actingAs($users[0])->get("/m/spending/events/{$event->id}")
        ->assertInertia(fn ($page) => $page->where('event.total', 2000)->where('balances.0.net', 1333));
});

it('edits a spend and re-freezes the conversion', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.25]])]);
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    // Change amount + currency + payer -> re-validated and re-converted.
    actingAs($users[0])->patch("/m/spending/spends/{$spend->id}", [
        'amount' => '40.00', 'currency' => 'RON', 'category' => 'transport', 'payer_id' => $users[1]->id, 'spent_at' => '2026-07-16',
    ])->assertRedirect();

    $spend->refresh();
    expect($spend->amount)->toBe(4000)
        ->and($spend->currency)->toBe('RON')
        ->and($spend->base_amount)->toBe(1000)   // 40.00 RON * 0.25 = 10.00 EUR
        ->and($spend->payer_id)->toBe($users[1]->id)
        ->and($spend->category)->toBe('transport');
});

it('duplicates a spend keeping its currency and frozen base amount', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '12.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/duplicate")->assertRedirect();

    expect($event->spends()->count())->toBe(2);
    $copy = $event->spends()->latest('id')->first();
    expect($copy->amount)->toBe(1200)->and($copy->base_amount)->toBe($spend->base_amount)->and($copy->id)->not->toBe($spend->id);
});

it('deletes a spend', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '12.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    actingAs($users[0])->delete("/m/spending/spends/{$spend->id}")->assertRedirect();
    expect($event->spends()->count())->toBe(0);
});

it('does not let another family edit or duplicate a spend', function () {
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/events', ['name' => 'A', 'currency' => 'EUR']);
    $eventA = SpendEvent::first();
    actingAs($usersA[0])->post("/m/spending/events/{$eventA->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $usersA[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spendA = $eventA->spends()->first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->post("/m/spending/spends/{$spendA->id}/duplicate")->assertNotFound();
    actingAs($usersB[0])->patch("/m/spending/spends/{$spendA->id}", [
        'amount' => '99.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $usersB[0]->id, 'spent_at' => '2026-07-16',
    ])->assertNotFound();
});

it('attaches a receipt image and serves it back to the family', function () {
    Storage::fake('r2');
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '12.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
        'receipt' => UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg'),
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->receipt_path)->not->toBeNull();
    Storage::disk('r2')->assertExists($spend->receipt_path);

    actingAs($users[0])->get("/m/spending/spends/{$spend->id}/receipt")->assertOk();
});

it('does not serve a receipt to another family', function () {
    Storage::fake('r2');
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/events', ['name' => 'A', 'currency' => 'EUR']);
    $eventA = SpendEvent::first();
    actingAs($usersA[0])->post("/m/spending/events/{$eventA->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $usersA[0]->id, 'spent_at' => '2026-07-16',
        'receipt' => UploadedFile::fake()->create('r.jpg', 200, 'image/jpeg'),
    ]);
    $spendA = $eventA->spends()->first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->get("/m/spending/spends/{$spendA->id}/receipt")->assertNotFound();
});

it('still records a spend when broadcasting is down', function () {
    // A broadcaster that always throws, simulating an unreachable Reverb.
    Broadcast::extend('boom', fn () => new class implements Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('reverb down');
        }
    });
    config()->set('broadcasting.default', 'boom');
    config()->set('broadcasting.connections.boom', ['driver' => 'boom']);

    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    // The write succeeds (redirect, not a 500) even though every broadcast throws.
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ])->assertRedirect();

    expect($event->spends()->count())->toBe(1);
});

it('updates a spend with a receipt via POST method-spoofing (the file-edit path)', function () {
    Storage::fake('r2');
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    // The frontend edits with a file as POST + _method=patch, since PHP can't parse a multipart PATCH.
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}", [
        '_method' => 'patch',
        'amount' => '22.00', 'currency' => 'EUR', 'category' => 'transport', 'payer_id' => $users[0]->id, 'spent_at' => '2026-07-16',
        'receipt' => UploadedFile::fake()->create('r.jpg', 100, 'image/jpeg'),
    ])->assertRedirect();

    $spend->refresh();
    expect($spend->amount)->toBe(2200)->and($spend->receipt_path)->not->toBeNull();
});

it('does not leak events across families', function () {
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/events', ['name' => 'A trip', 'currency' => 'EUR']);
    $eventA = SpendEvent::first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->get("/m/spending/events/{$eventA->id}")->assertNotFound();
});
