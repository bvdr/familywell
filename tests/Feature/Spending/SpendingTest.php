<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Spending\Spend;
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

    // The event page renders receipt URLs keyed by the stored path (browser cache buster). Regression:
    // the eager load once selected only id,spend_id, so `path` was null and the page 500'd in prod.
    $key = substr(md5($receipt->path), 0, 8);
    actingAs($users[0])->get("/m/spending/events/{$event->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('spends.0.receipts.0.thumbUrl', "http://localhost/m/spending/spends/receipts/{$receipt->id}?v=thumb&k={$key}"));
    // Keyed URL is browser-cacheable for a year; the key changes with the file so it never goes stale.
    actingAs($users[0])->get("/m/spending/spends/receipts/{$receipt->id}?k={$key}")
        ->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
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

    // Freeze "now" so the year-forcing on the scanned date is deterministic (07-19 stays in this year).
    Carbon::setTestNow('2026-08-31');

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
                    'store' => '  Beach Bar  ', // merchant off the header, trimmed
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
            'store' => 'Beach Bar', // prefills the shop picker
        ]);

    Carbon::setTestNow();
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

it('forces the scanned date onto the current year, stepping back only to stay in the past', function () {
    [$family, $users] = spendingFamily();
    config(['services.gemini.key' => 'test-key']);

    // Freeze "now" so today is deterministic regardless of when the suite runs.
    Carbon::setTestNow('2026-08-31');

    $body = fn (string $date) => [
        'candidates' => [['content' => ['parts' => [['text' => json_encode([
            'amount' => 10, 'has_printed_total' => true, 'currency' => 'EUR', 'date' => $date, 'category' => 'food',
        ])]]]]],
    ];

    // One stub sequence so each scan below gets its own scripted response (Http::fake merges rather
    // than replaces, so re-faking the same URL would keep returning the first stub).
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push($body('2023-07-19'))   // wrong (mis-read) year, month/day earlier in the year
        ->push($body('2020-03-05'))   // wrong year, another past month/day
        ->push($body('2026-12-27'))   // December read while we're "in" August — future if kept in this year
        ->push($body('2026-08-19'))   // already this year, plainly past — passes through unchanged
        ->push($body('not-a-date')),  // no usable month/day — falls back to today
    ]);

    $scan = fn () => actingAs($users[0])
        ->post('/m/spending/scan-receipt', ['photo' => UploadedFile::fake()->create('r.jpg', 200, 'image/jpeg')]);

    // Wrong year gets rewritten to the current year, keeping the same month/day.
    $scan()->assertOk()->assertJsonPath('date', '2026-07-19');
    $scan()->assertOk()->assertJsonPath('date', '2026-03-05');
    // A month/day that would land in the future under the current year steps back to the previous
    // year so it stays a past date — a future date is never emitted.
    $scan()->assertOk()->assertJsonPath('date', '2025-12-27');
    // A current-year past date is honoured as-is.
    $scan()->assertOk()->assertJsonPath('date', '2026-08-19');
    // Unparseable date falls back to today.
    $scan()->assertOk()->assertJsonPath('date', '2026-08-31');

    Carbon::setTestNow();
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
    $spend = Spend::withoutGlobalScopes()->first();
    expect($spend->spend_event_id)->toBe($a->id)->and($spend->shares()->count())->toBe(1);

    // Moving to the same list is rejected (with a visible validation error, not a silent no-op).
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/move", ['target_event_id' => $a->id])
        ->assertSessionHasErrors('target_event_id');

    // Move to B: lands there, split reset (no per-item share rows).
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/move", ['target_event_id' => $b->id])->assertRedirect();
    expect($spend->fresh()->spend_event_id)->toBe($b->id)
        ->and($spend->fresh()->shares()->count())->toBe(0);
});

it('moves a member-paid expense into an invited list the payer was not shared into', function () {
    [$family, $users] = spendingFamily();
    // Two family-visible lists to start, so u1 is a participant of the source and can be the payer.
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Shared', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Private-ish', 'currency' => 'EUR']);
    $src = SpendEvent::withoutGlobalScopes()->where('name', 'Shared')->first();
    $dst = SpendEvent::withoutGlobalScopes()->where('name', 'Private-ish')->first();

    // A spend paid by u1 (not the creator).
    actingAs($users[0])->post("/m/spending/events/{$src->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'food',
        'payer_key' => 'u'.$users[1]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = Spend::withoutGlobalScopes()->where('payer_id', $users[1]->id)->first();

    // Narrow the target to "invited": now only the owner (u0) is shared; u1 is NOT a participant of it.
    $dst->setVisibility('invited');
    expect(array_keys($dst->fresh()->participantNames()))->not->toContain('u'.$users[1]->id);

    // The move must still succeed — u1 becomes a participant of the target via their moved spend.
    actingAs($users[0])->post("/m/spending/spends/{$spend->id}/move", ['target_event_id' => $dst->id])->assertRedirect();
    expect($spend->fresh()->spend_event_id)->toBe($dst->id)
        ->and(array_keys($dst->fresh()->participantNames()))->toContain('u'.$users[1]->id);
});

it('notifies the family when a spend is edited and when it is removed', function () {
    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '30.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    $spend = $event->spends()->first();

    // Edit -> a descriptive "edited" notification reaches another member (not the actor).
    $users[1]->notifications()->delete();
    actingAs($users[0])->patch("/m/spending/spends/{$spend->id}", [
        'amount' => '45.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => '2026-07-16',
    ]);
    expect($users[1]->fresh()->notifications()->latest()->first()->data['body'])->toContain('edited')
        ->and($users[0]->fresh()->notifications()->count())->toBe(0); // actor is never notified

    // Delete -> a "removed" notification.
    $users[1]->notifications()->delete();
    actingAs($users[0])->delete("/m/spending/spends/{$spend->id}");
    expect($users[1]->fresh()->notifications()->latest()->first()->data['body'])->toContain('removed');
});

it('persists the list display currency so the header selector sticks', function () {
    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Medical', 'currency' => 'RON']);
    $event = SpendEvent::first();
    expect($event->display_currency)->toBeNull(); // defaults to the base currency

    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['display_currency' => 'EUR'])->assertRedirect();
    expect($event->fresh()->display_currency)->toBe('EUR');

    // The page exposes the saved value so the selector seeds from it on reload (base stays RON).
    actingAs($users[0])->get("/m/spending/events/{$event->id}")
        ->assertInertia(fn ($p) => $p->where('event.displayCurrency', 'EUR')->where('event.currency', 'RON'));
});

it('shows the list overview total in the chosen display currency', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['RON' => 5.0]]),
        'open.er-api.com/*' => Http::response(['rates' => ['RON' => 5.0]]),
    ]);

    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Medical', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-08-01',
    ])->assertRedirect();

    // Base sum is 20.00 EUR. With display currency RON at rate 5.0, the overview card must read
    // 100.00 RON, not the base €20.00 (the bug: the list ignored the display currency selector).
    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['display_currency' => 'RON'])->assertRedirect();
    actingAs($users[0])->get('/m/spending')
        ->assertInertia(fn ($p) => $p->where('events.0.total', 10000)->where('events.0.currency', 'RON'));
});

it('shows the home dashboard recent card in the chosen display currency', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['RON' => 5.0]]),
        'open.er-api.com/*' => Http::response(['rates' => ['RON' => 5.0]]),
    ]);

    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Medical', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($users[0])->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.($users[0]->id), 'spent_at' => '2026-08-01',
    ])->assertRedirect();
    actingAs($users[0])->patch("/m/spending/events/{$event->id}", ['display_currency' => 'RON'])->assertRedirect();
    // Opening the event registers the "recently visited" entry the dashboard reads.
    actingAs($users[0])->get("/m/spending/events/{$event->id}");

    // The "Pick up where you left off" card must read the RON display total (lei 100.00), not €20.00.
    actingAs($users[0])->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('recent.0.subtitle', 'lei 100.00'));
});

it('rolls up global analytics across lists into one display currency for the period', function () {
    Http::fake([
        // RON -> EUR for the roll-up conversion; EUR -> EUR short-circuits (no HTTP).
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [, $users] = spendingFamily();
    // Two lists with different base currencies.
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'RON']);
    $eur = SpendEvent::where('currency', 'EUR')->first();
    $ron = SpendEvent::where('currency', 'RON')->first();

    // Month-to-date safe: near the start of a month, startOfMonth()+3 would be in the future and fall
    // outside the mtd window. Cap at today so this spend is always inside the current mtd period.
    $thisMonth = now()->startOfMonth()->addDays(3)->min(now())->toDateString();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();

    // This month: 20.00 EUR (base 2000 EUR, "other") + 100.00 RON (base 10000 RON -> 2000 EUR, "food").
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$ron->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    // Last month: must be excluded from the month-to-date period.
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $lastMonth,
    ])->assertRedirect();

    // Display in EUR, month-to-date: total = 2000 + 2000 = 4000 (the last-month 500 is excluded).
    actingAs($users[0])->get('/m/spending/analytics?period=mtd&currency=EUR')
        ->assertInertia(fn ($p) => $p
            ->component('spending/analytics')
            ->where('currency', 'EUR')
            ->where('period', 'mtd')
            ->where('total', 4000)
            ->where('count', 2)
            // A month-to-date range is <= 31 days, so the series stays daily.
            ->where('granularity', 'day')
            ->where('byCategory', fn ($rows) => collect($rows)->firstWhere('key', 'food')['amount'] === 2000
                && collect($rows)->firstWhere('key', 'other')['amount'] === 2000)
            ->where('activeCategory', null)
            ->where('entries', [])
            ->etc());

    // Add a second food spend so the drill-down list has two rows to order.
    actingAs($users[0])->post("/m/spending/events/{$ron->id}/spends", [
        'amount' => '50.00', 'currency' => 'RON', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect(); // base 5000 RON -> 1000 EUR

    // Filtered to food: total/count/entries reflect only food (2000 + 1000 = 3000, two spends), entries are
    // sorted by converted amount descending, and byCategory STILL lists every category so the user can switch.
    actingAs($users[0])->get('/m/spending/analytics?period=mtd&currency=EUR&category=food')
        ->assertInertia(fn ($p) => $p
            ->component('spending/analytics')
            ->where('activeCategory', 'food')
            ->where('total', 3000)
            ->where('count', 2)
            ->where('entries', fn ($rows) => collect($rows)->count() === 2
                && collect($rows)->pluck('amount')->all() === [2000, 1000] // biggest first
                && collect($rows)->every(fn ($r) => $r['category'] === 'food')
                // Each entry carries its parent SpendEvent id so the row can link to that list (both food
                // spends live in the RON list).
                && collect($rows)->every(fn ($r) => $r['list_id'] === $ron->id))
            // byCategory is never filtered: "other" is still present alongside food.
            ->where('byCategory', fn ($rows) => collect($rows)->firstWhere('key', 'other')['amount'] === 2000
                && collect($rows)->firstWhere('key', 'food')['amount'] === 3000)
            ->etc());
});

it('aggregates the analytics series by month when the range spans more than 120 days', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $eur = SpendEvent::where('currency', 'EUR')->first();

    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id,
        'spent_at' => now()->subDays(100)->toDateString(),
    ])->assertRedirect();

    // A 200-day custom range (> 120 days) flips the series to monthly buckets — every point is the first
    // of a month. (Custom rather than ytd so the test doesn't depend on how far into the year it runs.)
    $from = now()->subDays(200)->toDateString();
    actingAs($users[0])->get("/m/spending/analytics?period=custom&from={$from}&to=".now()->toDateString().'&currency=EUR')
        ->assertInertia(fn ($p) => $p
            ->component('spending/analytics')
            ->where('period', 'custom')
            ->where('count', 1)
            ->where('granularity', 'month')
            ->where('daily', fn ($rows) => collect($rows)->every(fn ($r) => str_ends_with($r['date'], '-01')))
            ->etc());
});

it('spans the full history (min..max spent_at) for the all_time period', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $eur = SpendEvent::where('currency', 'EUR')->first();

    // Two spends far apart in time; all_time must bound the range to exactly these (oldest..newest),
    // regardless of what "this year" would include, so the whole history is reachable.
    foreach (['2023-02-10', '2026-08-19'] as $on) {
        actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
            'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id,
            'spent_at' => $on,
        ])->assertRedirect();
    }

    actingAs($users[0])->get('/m/spending/analytics?period=all_time&currency=EUR')
        ->assertInertia(fn ($p) => $p
            ->component('spending/analytics')
            ->where('period', 'all_time')
            ->where('range.from', '2023-02-10')
            ->where('range.to', '2026-08-19')
            ->where('granularity', 'month') // > 120 days => monthly
            ->where('count', 2)
            ->etc());
});

it('shows the current-month total on the overview, rolled up like analytics and scoped to visible lists', function () {
    Http::fake([
        // RON -> EUR for the roll-up conversion; EUR -> EUR short-circuits (no HTTP).
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [, $users] = spendingFamily();
    // Family default currency = EUR, so the widget reads in EUR regardless of each list's base.
    actingAs($users[0])->patch('/m/spending/settings', ['default_currency' => 'EUR'])->assertRedirect();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'RON']);
    $eur = SpendEvent::where('currency', 'EUR')->first();
    $ron = SpendEvent::where('currency', 'RON')->first();

    // Same mtd-safe dates as the analytics test: never in the future, always inside this month.
    $thisMonth = now()->startOfMonth()->addDays(3)->min(now())->toDateString();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();

    // Zero spends yet: the widget still renders a 0 total in the display currency.
    actingAs($users[0])->get('/m/spending')
        ->assertInertia(fn ($p) => $p->component('spending/events')->where('thisMonth', ['total' => 0, 'currency' => 'EUR'])->etc());

    // This month: 20.00 EUR (2000) + 100.00 RON (10000 RON -> 2000 EUR). Last month: 5.00 EUR, excluded.
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$ron->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'food', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $lastMonth,
    ])->assertRedirect();

    actingAs($users[0])->get('/m/spending')
        ->assertInertia(fn ($p) => $p->component('spending/events')->where('thisMonth', ['total' => 4000, 'currency' => 'EUR'])->etc());

    // Visibility: the RON list goes private (only its owner keeps a share), so a sibling member's widget
    // drops its 2000 and only counts the family-visible EUR list.
    $ron->setVisibility('private');
    actingAs($users[1])->get('/m/spending')
        ->assertInertia(fn ($p) => $p->where('thisMonth.total', 2000)->etc());
    actingAs($users[0])->get('/m/spending')
        ->assertInertia(fn ($p) => $p->where('thisMonth.total', 4000)->etc());

    // Family scoping: a member of another family sees none of these spends.
    $outsider = User::factory()->create();
    $other = Family::factory()->create(['owner_id' => $outsider->id]);
    $other->members()->attach($outsider, ['role' => 'admin']);
    session(['active_family_id' => $other->id]);
    app(ModuleRegistry::class)->enable('spending', $other->id);
    actingAs($outsider)->get('/m/spending')
        ->assertInertia(fn ($p) => $p->where('thisMonth.total', 0)->etc());
});

it('breaks analytics down by shop and filters the view to one shop', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);

    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Home', 'currency' => 'RON']);
    $eur = SpendEvent::where('currency', 'EUR')->first();
    $ron = SpendEvent::where('currency', 'RON')->first();
    $thisMonth = now()->startOfMonth()->addDays(3)->min(now())->toDateString();
    $u = 'u'.$users[0]->id;

    // Lidl: 20 EUR (other) in the EUR list + 100 RON (food, -> 2000 EUR cents) in the RON list. The shop
    // name is trimmed on save so "  Lidl " and "Lidl" land in the same bucket.
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '20.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => $u, 'spent_at' => $thisMonth, 'store' => '  Lidl ',
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$ron->id}/spends", [
        'amount' => '100.00', 'currency' => 'RON', 'category' => 'food', 'payer_key' => $u, 'spent_at' => $thisMonth, 'store' => 'Lidl',
    ])->assertRedirect();
    // Kaufland: 10 EUR; and one spend with no shop at all (5 EUR).
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => $u, 'spent_at' => $thisMonth, 'store' => 'Kaufland',
    ])->assertRedirect();
    actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'other', 'payer_key' => $u, 'spent_at' => $thisMonth,
    ])->assertRedirect();
    expect(Spend::where('store', 'Lidl')->count())->toBe(2);

    // Unfiltered: byShop sums per store in the display currency, biggest first, with the no-shop spend in
    // its own "__none" bucket; pct is relative to the grand total (5500).
    actingAs($users[0])->get('/m/spending/analytics?period=mtd&currency=EUR')
        ->assertInertia(fn ($p) => $p
            ->component('spending/analytics')
            ->where('total', 5500)
            ->where('activeShop', null)
            ->where('byShop', [
                ['key' => 'Lidl', 'amount' => 4000, 'pct' => 73],
                ['key' => 'Kaufland', 'amount' => 1000, 'pct' => 18],
                ['key' => '__none', 'amount' => 500, 'pct' => 9],
            ])
            // The event page offers every shop name used so far as a picker suggestion.
            ->etc());
    actingAs($users[0])->get("/m/spending/events/{$eur->id}")
        ->assertInertia(fn ($p) => $p->where('event.shops', ['Kaufland', 'Lidl'])->etc());

    // Filtered to Lidl: total/count/entries reflect only Lidl, while byShop AND byCategory stay complete.
    actingAs($users[0])->get('/m/spending/analytics?period=mtd&currency=EUR&shop=Lidl')
        ->assertInertia(fn ($p) => $p
            ->where('activeShop', 'Lidl')
            ->where('total', 4000)
            ->where('count', 2)
            ->where('entries', fn ($rows) => collect($rows)->count() === 2
                && collect($rows)->every(fn ($r) => $r['store'] === 'Lidl'))
            ->where('byShop', fn ($rows) => collect($rows)->pluck('key')->all() === ['Lidl', 'Kaufland', '__none'])
            ->where('byCategory', fn ($rows) => collect($rows)->firstWhere('key', 'other')['amount'] === 2500)
            ->etc());

    // The "no shop" bucket is filterable too.
    actingAs($users[0])->get('/m/spending/analytics?period=mtd&currency=EUR&shop=__none')
        ->assertInertia(fn ($p) => $p
            ->where('total', 500)
            ->where('count', 1)
            ->where('entries.0.store', null)
            ->etc());

    // Shop and category filters combine: Lidl + food is just the RON spend.
    actingAs($users[0])->get('/m/spending/analytics?period=mtd&currency=EUR&shop=Lidl&category=food')
        ->assertInertia(fn ($p) => $p->where('total', 2000)->where('count', 1)->etc());
});

it('buckets the analytics series by ISO week for ranges between a month and a quarter', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2]]),
    ]);
    Carbon::setTestNow('2026-09-22'); // a Tuesday

    [, $users] = spendingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $eur = SpendEvent::first();

    // Wed 12 Aug + Sun 16 Aug share the ISO week starting Mon 10 Aug (a Sunday belongs to the week
    // BEFORE, Monday-first); Mon 17 Aug starts the next one.
    foreach (['2026-08-12' => '20.00', '2026-08-16' => '5.00', '2026-08-17' => '3.00'] as $on => $amount) {
        actingAs($users[0])->post("/m/spending/events/{$eur->id}/spends", [
            'amount' => $amount, 'currency' => 'EUR', 'category' => 'other', 'payer_key' => 'u'.$users[0]->id, 'spent_at' => $on,
        ])->assertRedirect();
    }

    // 1 Aug .. 20 Sep = 50 days: more than a month, less than ~a quarter => weekly points, each keyed by
    // its Monday. The first point is the Monday of the week containing the range start (27 Jul).
    actingAs($users[0])->get('/m/spending/analytics?period=custom&from=2026-08-01&to=2026-09-20&currency=EUR')
        ->assertInertia(fn ($p) => $p
            ->component('spending/analytics')
            ->where('granularity', 'week')
            ->where('count', 3)
            ->where('daily.0.date', '2026-07-27')
            ->where('daily', fn ($rows) => collect($rows)->every(fn ($r) => Carbon::parse($r['date'])->isMonday())
                && collect($rows)->firstWhere('date', '2026-08-10')['amount'] === 2500
                && collect($rows)->firstWhere('date', '2026-08-17')['amount'] === 300
                && collect($rows)->last()['date'] === '2026-09-14')
            ->etc());

    Carbon::setTestNow();
});

it('resolves the this_week and last_week periods to ISO weeks', function () {
    Http::fake();
    Carbon::setTestNow('2026-09-22'); // a Tuesday
    [, $users] = spendingFamily();

    // This week: Monday to today. Last week: the full previous Monday..Sunday.
    actingAs($users[0])->get('/m/spending/analytics?period=this_week')
        ->assertInertia(fn ($p) => $p->where('period', 'this_week')->where('range', ['from' => '2026-09-21', 'to' => '2026-09-22'])->where('granularity', 'day')->etc());
    actingAs($users[0])->get('/m/spending/analytics?period=last_week')
        ->assertInertia(fn ($p) => $p->where('period', 'last_week')->where('range', ['from' => '2026-09-14', 'to' => '2026-09-20'])->etc());

    Carbon::setTestNow();
});
