<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Http;
use Modules\Spending\Spend;
use Modules\Spending\SpendCategorizer;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

function recatFamily(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);
    config(['services.typesafe.key' => 'test-key']);

    return [$family, $admin, $member];
}

function recatSpend(SpendEvent $event, int $payer, string $note, string $category = 'other'): Spend
{
    return Spend::create(['spend_event_id' => $event->id, 'payer_id' => $payer, 'amount' => 1250, 'currency' => 'EUR',
        'base_amount' => 1250, 'rate' => 1, 'category' => $category, 'note' => $note, 'spent_at' => '2026-09-01', 'created_by' => $payer]);
}

it('is admin-only and 404s without a key', function () {
    [, $admin, $member] = recatFamily();
    actingAs($member)->postJson('/m/spending/settings/recategorize', ['after' => 0])->assertForbidden();

    config(['services.typesafe.key' => null]);
    actingAs($admin)->postJson('/m/spending/settings/recategorize', ['after' => 0])->assertNotFound();
});

it('moves Other spends to the category Jev picks, one request per spend, and leaves the rest alone', function () {
    [$family, $admin] = recatFamily();
    $event = SpendEvent::create(['name' => 'Greece 2026', 'currency' => 'EUR', 'created_by' => $admin->id]);
    $taxi = recatSpend($event, $admin->id, 'Taxi from airport');
    $mystery = recatSpend($event, $admin->id, '???');
    $unsure = recatSpend($event, $admin->id, 'Profi');
    $food = recatSpend($event, $admin->id, 'Dinner', 'food');

    // One answer per request, matched on the note the request carries.
    Http::fake(['api.typesafe.ai/*' => function ($req) {
        $pick = match ($req->data()['state']['expense']['note']) {
            'Taxi from airport' => ['transport', 0.9],
            'Profi' => ['shopping', 0.45], // below MIN_PROBABILITY => stays in other
            default => ['other', 0.8],
        };

        return Http::response(['answers' => ['category' => ['type' => 'choice', 'choice' => $pick[0], 'probabilities' => [$pick[0] => $pick[1]], 'confidence' => $pick[1]]]]);
    }]);

    $first = actingAs($admin)->postJson('/m/spending/settings/recategorize', ['after' => 0])
        ->assertOk()->assertJson(['processed' => 3, 'updated' => 1, 'next' => $unsure->id, 'remaining' => 0])->json();

    Http::assertSentCount(3); // only the three Other spends, never the one already on food
    Http::assertSent(function ($req) {
        $q = $req->data()['questions']['category'];
        $state = $req->data()['state']['expense'];

        return $state['note'] === 'Taxi from airport' && $state['list'] === 'Greece 2026' && $state['amount'] === '12.50 EUR'
            && str_starts_with($q['criteria']['transport'], 'Transport: ')
            && array_key_last($q['criteria']) === 'other';
    });
    expect($taxi->fresh()->category)->toBe('transport')
        ->and($mystery->fresh()->category)->toBe('other')
        ->and($unsure->fresh()->category)->toBe('other')
        ->and($food->fresh()->category)->toBe('food');

    // The client keeps calling with the cursor until `next` is null.
    actingAs($admin)->postJson('/m/spending/settings/recategorize', ['after' => $first['next']])
        ->assertOk()->assertJson(['processed' => 0, 'updated' => 0, 'next' => null, 'remaining' => 0]);
});

it('caps a batch at BATCH spends and reports the remainder', function () {
    [, $admin] = recatFamily();
    $event = SpendEvent::create(['name' => 'Home', 'currency' => 'EUR', 'created_by' => $admin->id]);
    foreach (range(1, SpendCategorizer::BATCH + 3) as $i) {
        recatSpend($event, $admin->id, "Thing {$i}");
    }
    Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => []])]);

    actingAs($admin)->postJson('/m/spending/settings/recategorize', ['after' => 0])
        ->assertOk()->assertJson(['processed' => SpendCategorizer::BATCH, 'updated' => 0, 'remaining' => 3]);
});
