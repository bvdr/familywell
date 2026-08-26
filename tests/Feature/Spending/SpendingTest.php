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
use Modules\Spending\SpendSource;

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

it('archives and unarchives an event', function () {
    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Old Trip', 'currency' => 'EUR']);
    $event = SpendEvent::withoutGlobalScopes()->first();
    expect($event->archived_at)->toBeNull();

    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['archived' => true])->assertRedirect();
    expect($event->fresh()->archived_at)->not->toBeNull();

    // Renaming still works and leaves the archived state untouched.
    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['name' => 'Old Trip 2023'])->assertRedirect();
    expect($event->fresh()->name)->toBe('Old Trip 2023')
        ->and($event->fresh()->archived_at)->not->toBeNull();

    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['archived' => false])->assertRedirect();
    expect($event->fresh()->archived_at)->toBeNull();
});

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
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
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
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
    ]);

    actingAs($users[0])->post("/m/spending/events/{$event->id}/settle", [
        'from_key' => 'u'.($users[1]->id), 'to_key' => 'u'.($users[0]->id), 'amount' => 1000,
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
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'transport', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-06-15',
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->amount)->toBe(10000)          // original RON minor units
        ->and($spend->currency)->toBe('RON')
        ->and($spend->base_amount)->toBe(2000);  // frozen EUR base

    // Balances are in the base currency (EUR): payer is owed 2/3 of 20.00.
    actingAs($users[0])->get("/m/spending/events/{$event->id}")
        ->assertInertia(fn ($page) => $page->where('event.total', 2000)->where('balances.0.net', 1333));

    // The index card total must be the base (EUR) sum too — 2000, NOT the raw RON amount 10000.
    // Otherwise a foreign-currency spend inflates the list card vs the event's real "Total spent".
    actingAs($users[0])->get('/m/spending')
        ->assertInertia(fn ($page) => $page->where('events.0.total', 2000));
});

it('edits a spend and re-freezes the conversion', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.25]])]);
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    // Change amount + currency + payer -> re-validated and re-converted.
    actingAs($users[0])->patch("/m/spending/spends/{$spend->id}", [
        'amount' => '40.00', 'currency' => 'RON', 'category' => 'transport', 'payer_key' => 'u'.($users[1]->id), 'spent_at' => '2026-07-16',
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
        'amount' => '12.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
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
        'amount' => '12.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
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
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($usersA[0]->id), 'spent_at' => '2026-07-16',
    ]);
    $spendA = $eventA->spends()->first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->post("/m/spending/spends/{$spendA->id}/duplicate")->assertNotFound();
    actingAs($usersB[0])->patch("/m/spending/spends/{$spendA->id}", [
        'amount' => '99.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($usersB[0]->id), 'spent_at' => '2026-07-16',
    ])->assertNotFound();
});

it('attaches multiple receipt images and serves them back to the family', function () {
    Storage::fake('r2');
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '12.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'receipts' => [
            UploadedFile::fake()->create('a.jpg', 200, 'image/jpeg'),
            UploadedFile::fake()->create('b.jpg', 200, 'image/jpeg'),
        ],
    ])->assertRedirect();

    $spend = $event->spends()->first();
    expect($spend->receipts()->count())->toBe(2);
    $receipt = $spend->receipts()->first();
    Storage::disk('r2')->assertExists($receipt->path);

    actingAs($users[0])->get("/m/spending/spends/receipts/{$receipt->id}")->assertOk();
});

it('does not serve a receipt to another family', function () {
    Storage::fake('r2');
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/events', ['name' => 'A', 'currency' => 'EUR']);
    $eventA = SpendEvent::first();
    actingAs($usersA[0])->post("/m/spending/events/{$eventA->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($usersA[0]->id), 'spent_at' => '2026-07-16',
        'receipts' => [UploadedFile::fake()->create('r.jpg', 200, 'image/jpeg')],
    ]);
    $receiptA = $eventA->spends()->first()->receipts()->first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->get("/m/spending/spends/receipts/{$receiptA->id}")->assertNotFound();
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
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
    ])->assertRedirect();

    expect($event->spends()->count())->toBe(1);
});

it('adds a receipt on edit via POST method-spoofing (the file-edit path)', function () {
    Storage::fake('r2');
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    // The frontend edits with files as POST + _method=patch, since PHP can't parse a multipart PATCH.
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}", [
        '_method' => 'patch',
        'amount' => '22.00', 'currency' => 'EUR', 'category' => 'transport', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'receipts' => [UploadedFile::fake()->create('r.jpg', 100, 'image/jpeg')],
        'receipts_thumb' => [UploadedFile::fake()->create('r.thumb.jpg', 10, 'image/jpeg')],
    ])->assertRedirect();

    $spend->refresh();
    expect($spend->amount)->toBe(2200)->and($spend->receipts()->count())->toBe(1);
    $receipt = $spend->receipts()->first();
    Storage::disk('r2')->assertExists($receipt->path.'.thumb.jpg'); // thumbnail stored alongside the receipt
    actingAs($users[0])->get("/m/spending/spends/receipts/{$receipt->id}?v=thumb")->assertOk(); // serves the thumb
});

it('removes only the selected receipts on edit', function () {
    Storage::fake('r2');
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'receipts' => [
            UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg'),
            UploadedFile::fake()->create('b.jpg', 100, 'image/jpeg'),
        ],
    ]);
    $spend = $event->spends()->first();
    $toRemove = $spend->receipts()->first();
    $path = $toRemove->path;

    actingAs($users[0])->post("/m/spending/spends/{$spend->id}", [
        '_method' => 'patch',
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'remove_receipt_ids' => [$toRemove->id],
    ])->assertRedirect();

    expect($spend->receipts()->count())->toBe(1);
    Storage::disk('r2')->assertMissing($path);
});

it('does not leak events across families', function () {
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/events', ['name' => 'A trip', 'currency' => 'EUR']);
    $eventA = SpendEvent::first();

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->get("/m/spending/events/{$eventA->id}")->assertNotFound();
});

it('creates a payment source and records a spend paid with it', function () {
    [$family, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    actingAs($users[0])->post('/m/spending/sources', ['name' => 'Wise', 'type' => 'card'])->assertRedirect();
    $source = SpendSource::first();
    expect($source->name)->toBe('Wise')->and($source->type)->toBe('card');

    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-07-16',
        'source_id' => $source->id,
    ])->assertRedirect();

    expect($event->spends()->first()->source_id)->toBe($source->id);
});

it('rejects a payment source from another family', function () {
    [$familyA, $usersA] = spendingFamily();
    actingAs($usersA[0])->post('/m/spending/sources', ['name' => 'A card', 'type' => 'card']);
    $sourceAId = SpendSource::first()->id;

    [$familyB, $usersB] = spendingFamily();
    actingAs($usersB[0])->post('/m/spending/events', ['name' => 'B', 'currency' => 'EUR']);
    $eventB = SpendEvent::first();

    actingAs($usersB[0])->post("/m/spending/events/{$eventB->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.($usersB[0]->id), 'spent_at' => '2026-07-16',
        'source_id' => $sourceAId,
    ])->assertSessionHasErrors('source_id');
});

it('scans a receipt into prefill fields, summing line items when no total is printed', function () {
    [$family, $users] = spendingFamily();
    config(['services.gemini.key' => 'test-key']);

    // Model returns a wrong total but correct line items and no printed total: we must sum the items.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['parts' => [[
                'text' => json_encode([
                    'amount' => 999,
                    'has_printed_total' => false,
                    'currency' => 'eur',
                    'date' => '2026-07-19',
                    'category' => 'FOOD',
                    'note' => 'Beach bar',
                    'line_items' => [
                        ['name' => 'Coffee', 'price' => 5],
                        ['name' => 'Coffee', 'price' => 5],
                        ['name' => 'Drinks', 'price' => 6],
                    ],
                ]),
            ]]],
        ]],
    ])]);

    actingAs($users[0])
        ->post('/m/spending/scan-receipt', ['photo' => UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')])
        ->assertOk()
        ->assertExactJson([
            'amount' => 16,       // 5 + 5 + 6, not the model's bogus 999
            'currency' => 'EUR',  // upper-cased + validated against the app's list
            'date' => '2026-07-19',
            'category' => 'food', // lower-cased + validated
            'note' => 'Beach bar',
        ]);
});

it('trusts the printed total instead of summing when the receipt prints one', function () {
    [$family, $users] = spendingFamily();
    config(['services.gemini.key' => 'test-key']);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode([
            'amount' => 42.5,
            'has_printed_total' => true,
            'currency' => 'EUR',
            'date' => '2026-07-19',
            'category' => 'food',
            'line_items' => [['name' => 'x', 'price' => 1]], // present but must be ignored
        ])]]]]],
    ])]);

    actingAs($users[0])
        ->post('/m/spending/scan-receipt', ['photo' => UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')])
        ->assertOk()
        ->assertJsonPath('amount', 42.5);
});

it('404s receipt scanning when no Gemini key is configured', function () {
    [$family, $users] = spendingFamily();
    config(['services.gemini.key' => null]);

    actingAs($users[0])
        ->post('/m/spending/scan-receipt', ['photo' => UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')])
        ->assertNotFound();
});

it('moves an expense to another list and resets its split', function () {
    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip A', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip B', 'currency' => 'EUR']);
    $a = SpendEvent::withoutGlobalScopes()->where('name', 'Trip A')->first();
    $b = SpendEvent::withoutGlobalScopes()->where('name', 'Trip B')->first();

    // A spend in A with a custom "some" split.
    actingAs($users[0])->post("/m/spending/events/{$a->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food',
        'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-07-16',
        'split' => 'some', 'shares' => [['key' => 'u'.$users[0]->id]],
    ]);
    $spend = Modules\Spending\Spend::withoutGlobalScopes()->first();
    expect($spend->spend_event_id)->toBe($a->id)->and($spend->shares()->count())->toBe(1);

    // Moving to the same list is rejected.
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/move", ['target_event_id' => $a->id])->assertStatus(422);

    // Move to B: lands there, split reset (no per-item share rows).
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/move", ['target_event_id' => $b->id])->assertRedirect();
    expect($spend->fresh()->spend_event_id)->toBe($b->id)
        ->and($spend->fresh()->shares()->count())->toBe(0);
});
