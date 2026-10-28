<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Calendar\CalendarEvent;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Spending\Spend;
use Modules\Tasks\Task;

use function Pest\Laravel\actingAs;

// "Just say it" (Add sheet, design XMkKm H2): AddReader + the kinds the modules register, the ai_usage ledger and
// the family's monthly AI points. Cloudflare (Clef + Clairic) and Gemini are faked; the shapes are the real ones.

function anythingFamily(): array
{
    $users = User::factory()->count(2)->sequence(['name' => 'Ana Rivera'], ['name' => 'Lucia Rivera'])->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach (['calendar', 'tasks', 'spending', 'shopping', 'vehicles'] as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }
    config(['services.workers_ai.account' => 'acc', 'services.workers_ai.token' => 'tok', 'services.stripe.key' => null]);

    return [$family, $users];
}

// The school email of design H2b (amounts are stored in cents): an event, a to-do and an expense; unsure which kid the trip is for.
function fakeCloudflare(array $kinds = ['event' => 0.98, 'todo' => 0.93, 'expense' => 0.95, 'shopping_item' => 0.02, 'vehicle' => 0.02], ?array $fields = null, ?array $llm = null): void
{
    $fields ??= [
        'event_title' => ['value' => 'Field trip', 'confidence' => 0.84, 'p_null' => 0.01],
        'event_start' => ['value' => '2026-10-30T08:15:00', 'confidence' => 1.0, 'p_null' => 0],
        'event_end' => ['value' => '2026-10-30T14:30:00', 'confidence' => 1.0, 'p_null' => 0],
        'event_who' => ['value' => 'Lucia', 'confidence' => 0.28, 'p_null' => 0],
        'todo_title' => ['value' => 'Sign the permission slip', 'confidence' => 0.67, 'p_null' => 0.01],
        'todo_due' => ['value' => '2026-10-28', 'confidence' => 1.0, 'p_null' => 0],
        'expense_amount' => ['value' => 12, 'confidence' => 1.0, 'p_null' => 0],
        'expense_currency' => ['value' => null, 'confidence' => 0.6, 'p_null' => 0.96],
        'expense_note' => ['value' => 'Field trip fee', 'confidence' => 0.8, 'p_null' => 0.02],
    ];
    Http::fake([
        '*/ai/run/@cf/cloudflare/clef' => Http::response(['success' => true, 'result' => [
            'answers' => array_map(fn ($p) => ['type' => 'noul', 'noul' => $p], $kinds), 'usage' => ['input_tokens' => 300],
        ]]),
        '*/ai/run/@cf/cloudflare/clairic' => Http::response(['success' => true, 'result' => [
            'answers' => $fields, 'usage' => ['input_tokens' => 900],
        ]]),
        // Workers AI's Llama 4 Scout, which reads every input next to Clairic (here: a car photo's details).
        '*/ai/run/@cf/meta/*' => Http::response(['success' => true, 'result' => [
            'response' => $llm ?? ['vehicle_make' => 'Skoda', 'vehicle_model' => 'Octavia', 'vehicle_color' => 'blue'],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 20],
        ]]),
    ]);
}

beforeEach(fn () => Carbon::setTestNow('2026-10-28 09:00'));

it('reads one message into an event, a to-do and an expense, asking who the event is for', function () {
    [, $users] = anythingFamily();
    fakeCloudflare();

    $items = actingAs($users[0])->postJson('/add/read', ['text' => 'Dear parents, field trip…', 'path' => '/dashboard'])
        ->assertOk()->json('items');

    expect(collect($items)->pluck('kind')->all())->toBe(['event', 'expense', 'todo'])
        ->and($items[0]['fields']['start']['value'])->toBe('2026-10-30T08:15:00')
        ->and($items[0]['ask']['who'])->toBe(['Ana', 'Lucia']) // 0.28 sure: a question, not a guess
        ->and($items[1]['fields'])->not->toHaveKey('currency') // the reader said it isn't there
        ->and(DB::table('ai_usage')->where('feature', 'add_read')->pluck('model')->sort()->values()->all())->toBe(['clairic', 'clef', 'llama-4-scout-17b-16e-instruct'])
        ->and((int) DB::table('ai_usage')->sum('points'))->toBe(0); // reading is free
});

it('tells the reader where the + was pressed and what the person usually adds', function () {
    [, $users] = anythingFamily();
    fakeCloudflare(['event' => 0.01, 'todo' => 0.01, 'expense' => 0.01, 'shopping_item' => 0.01, 'vehicle' => 0.6]);

    $items = actingAs($users[0])->postJson('/add/read', ['images' => ['data:image/jpeg;base64,/9j/AAAA'], 'path' => '/m/vehicles'])->json('items');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/clef')
        && str_contains($r['state'], 'Vehicles screen: the family has no vehicles yet')
        && str_contains($r['state'], 'Family members: Ana Rivera, Lucia Rivera')
        && $r['images'] === ['data:image/jpeg;base64,/9j/AAAA']); // Workers AI wants the data: URI itself
    expect($items[0])->toMatchArray(['kind' => 'vehicle', 'sure' => false]) // 0.6: "Add this as a new vehicle?"
        ->and($items[0]['fields']['model']['value'])->toBe('Octavia') // the vision model read the photo
        ->and(DB::table('ai_usage')->where('model', 'llama-4-scout-17b-16e-instruct')->value('cost_micros'))->toBe(341); // 1200 × 0.27 + 20 × 0.85
});

it('fills what Clairic is unsure of from Scout, but keeps a value Clairic is confident about', function () {
    [, $users] = anythingFamily();
    // Romanian: Clairic is English-first ("cump" as an item, 0.15; the todo title is the whole sentence at 0.5).
    fakeCloudflare(['shopping_item' => 0.95, 'todo' => 0.9], [
        'shopping_item_items' => ['value' => ['cump', 'lapte'], 'confidence' => 0.15, 'p_null' => 0],
        'todo_title' => ['value' => 'cumpără lapte și pâine, și sună la instalator mâine', 'confidence' => 0.45, 'p_null' => 0],
        'todo_due' => ['value' => '2026-10-29', 'confidence' => 0.99, 'p_null' => 0],
    ], ['shopping_item_items' => ['lapte', 'pâine'], 'todo_title' => 'sună la instalator', 'todo_due' => '2026-10-30']);

    $items = collect(actingAs($users[0])->postJson('/add/read', ['text' => 'cumpără lapte și pâine, și sună la instalator mâine'])->json('items'))->keyBy('kind');

    expect($items['shopping_item']['fields']['items']['value'])->toBe(['lapte', 'pâine'])
        ->and($items['todo']['fields']['title']['value'])->toBe('sună la instalator')
        ->and($items['todo']['fields']['due']['value'])->toBe('2026-10-29'); // Clairic was sure of the date: kept
});

it('saves the confirmed cards through each module and charges one point for the lot', function () {
    [$family, $users] = anythingFamily();
    config(['services.stripe.key' => 'sk_test']); // hosted: points count

    actingAs($users[0])->postJson('/add/save', ['path' => '/dashboard', 'items' => [
        ['kind' => 'event', 'values' => ['title' => 'Field trip', 'start' => '2026-10-30T08:15:00', 'end' => '2026-10-30T14:30:00', 'who' => 'Lucia']],
        ['kind' => 'todo', 'values' => ['title' => 'Sign the permission slip', 'due' => '2026-10-28']],
        ['kind' => 'expense', 'values' => ['amount' => 12, 'note' => 'Field trip fee']],
        ['kind' => 'shopping_item', 'values' => ['items' => ['Bananas', 'Milk']]],
    ]])->assertOk()->assertJsonPath('pointsLeft', config('familywell.tiers.basic.ai_points') - 1);

    $event = CalendarEvent::withoutGlobalScopes()->firstOrFail();
    expect($event->start_time)->toStartWith('08:15')->and($event->end_date->toDateString())->toBe('2026-10-30')
        ->and(Task::withoutGlobalScopes()->value('title'))->toBe('Sign the permission slip')
        ->and(Spend::withoutGlobalScopes()->first())->amount->toEqual(1200)->note->toBe('Field trip fee')
        ->and(ShoppingItem::withoutGlobalScopes()->pluck('name')->all())->toBe(['Bananas', 'Milk'])
        ->and((int) DB::table('ai_usage')->where('family_id', $family->id)->sum('points'))->toBe(1);

    // Bananas again: merged into the open one, not added twice.
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'shopping_item', 'values' => ['items' => ['bananas']]]]])->assertOk();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(2);
});

it('saves nothing when one card does not hold up', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'todo', 'values' => ['title' => 'Call the plumber']],
        ['kind' => 'vehicle', 'values' => ['color' => 'red']], // no make / model
    ]])->assertUnprocessable();
    expect(Task::withoutGlobalScopes()->count())->toBe(0);
});

it('never adds into someone else\'s private list, even from its URL', function () {
    [, $users] = anythingFamily();
    actingAs($users[1])->post('/m/shopping/lists', ['name' => 'Secret']);
    $secret = ShoppingList::withoutGlobalScopes()->firstOrFail();
    $secret->setVisibility('private');

    actingAs($users[0])->postJson('/add/save', ['path' => "/m/shopping/lists/{$secret->id}", 'items' => [
        ['kind' => 'shopping_item', 'values' => ['items' => ['Milk']]],
    ]])->assertOk();

    expect(ShoppingItem::withoutGlobalScopes()->firstOrFail()->shopping_list_id)->not->toBe($secret->id);
});

it('stops reading when the family has used its monthly points, and never limits a self-hosted install', function () {
    [$family, $users] = anythingFamily();
    fakeCloudflare();
    DB::table('ai_usage')->insert(['family_id' => $family->id, 'feature' => 'add_saved', 'provider' => 'familywell', 'model' => 'points', 'points' => 50, 'created_at' => now()]);

    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertOk(); // self-hosted (no Stripe): unlimited

    config(['services.stripe.key' => 'sk_test']);
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertStatus(409)->assertJsonPath('outOfPoints', true);
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('ai.pointsLeft', 0)->assertJsonPath('ai.available', true);

    Carbon::setTestNow('2026-11-01 00:01'); // a new month, a new allowance
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertOk();
});

it('falls back to Gemini structured output when Cloudflare is not configured', function () {
    [, $users] = anythingFamily();
    config(['services.workers_ai.token' => null, 'services.gemini.key' => 'g']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode([
            'shopping_item_likelihood' => 0.95, 'shopping_item_items' => ['Bananas', 'Milk'], 'event_likelihood' => 0.05,
        ])]]]]],
        'usageMetadata' => ['promptTokenCount' => 400, 'candidatesTokenCount' => 30],
    ])]);

    $items = actingAs($users[0])->postJson('/add/read', ['text' => 'bananas and milk'])->assertOk()->json('items');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['kind' => 'shopping_item', 'sure' => true])
        ->and($items[0]['fields']['items']['value'])->toBe(['Bananas', 'Milk'])
        ->and(DB::table('ai_usage')->where('provider', 'gemini')->value('cost_micros'))->toBe(290); // 400 × 0.50 + 30 × 3.00
});

it('is off without any reader configured', function () {
    [, $users] = anythingFamily();
    config(['services.workers_ai.token' => null, 'services.gemini.key' => null]);
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertNotFound();
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('ai.available', false);
});

it('names where a card will land, creates whole lists on request, and drops an unsure card with nothing in it', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Household']);
    // "Lista nouă pentru Bogdan de todouri": a new to-do list; shopping only half-likely and nothing to buy read.
    fakeCloudflare(['task_list' => 0.9, 'todo' => 0.4, 'shopping_item' => 0.5], [
        'task_list_name' => ['value' => 'Bogdan', 'confidence' => 0.7, 'p_null' => 0],
        'todo_title' => ['value' => 'Clean the gutters', 'confidence' => 0.8, 'p_null' => 0],
        'shopping_item_items' => ['value' => [], 'confidence' => 0.5, 'p_null' => 0.9],
    ]);

    $items = actingAs($users[0])->postJson('/add/read', ['text' => 'Lista nouă pentru Bogdan de todouri'])->json('items');

    expect(collect($items)->pluck('kind')->all())->toBe(['task_list', 'todo']) // no empty shopping card
        ->and($items[1]['target'])->toBe('Household'); // the to-do says which list it goes into

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'task_list', 'values' => ['name' => 'Bogdan']]]])->assertOk();
    expect(Modules\Tasks\TaskList::withoutGlobalScopes()->pluck('name')->all())->toBe(['Household', 'Bogdan']);
});
