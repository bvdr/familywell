<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Ai\AiGateway;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Calendar\CalendarEvent;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Spending\Spend;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

// "Just say it" (Add sheet, design XMkKm H2): the app's side. What it sends the reader worker (kinds, context), what
// it does with the answer (targets, usage ledger, points) and how cards are saved. The reading itself is the worker's.

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
    // Known config whatever the local .env says (a developer's real gateway / Gemini keys must not leak in), and no
    // request may reach the network: every AI call here is faked.
    config(['services.add_reader.url' => 'https://reader.test', 'services.add_reader.secret' => 'sec', 'services.stripe.key' => null,
        'services.ai_gateway.id' => null, 'services.gemini.key' => null, 'services.gemini.via_gateway' => false]);
    Http::preventStrayRequests();

    return [$family, $users];
}

// What the reader worker answers (its own shape; how it decides is tested by its eval in bvdr/familywell-reader).
function fakeReader(array $items = [], int $status = 200): void
{
    Http::fake(['reader.test/read' => Http::response($status === 200 ? ['items' => $items, 'usage' => [
        ['provider' => 'workers-ai', 'model' => 'clef', 'input_tokens' => 300, 'output_tokens' => 0, 'ms' => 200, 'ok' => true],
        ['provider' => 'google-ai-studio', 'model' => 'gemini-3-flash-preview', 'input_tokens' => 400, 'output_tokens' => 30, 'ms' => 900, 'ok' => true],
    ], 'ms' => 950] : ['error' => 'unavailable'], $status)]);
}

beforeEach(fn () => Carbon::setTestNow('2026-10-28 09:00'));

it('asks the reader worker with the kinds, the family, the screen and the habits, and records what it used', function () {
    [$family, $users] = anythingFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Household']);
    fakeReader([['kind' => 'todo', 'module' => 'tasks', 'p' => 0.9, 'sure' => true, 'fields' => ['title' => ['value' => 'Call grandma', 'confidence' => 0.9]], 'ask' => ['who' => ['Ana', 'Lucia']], 'missing' => [], 'types' => ['title' => 'text']]]);

    $items = actingAs($users[0])->postJson('/add/read', ['text' => 'call grandma', 'path' => '/m/vehicles'])->assertOk()->json('items');

    expect($items[0])->toMatchArray(['kind' => 'todo', 'target' => 'Household']); // where it lands: the app's to say
    Http::assertSent(function ($r) use ($family, $users) {
        $kinds = $r['kinds'];

        return $r->url() === 'https://reader.test/read' && $r->header('Authorization') === ['Bearer sec']
            && $r['text'] === 'call grandma'
            && $kinds['shopping_item']['fields']['items'] === ['type' => 'list', 'required' => true, 'of' => ['name' => 'text', 'quantity' => 'text']]
            && ! isset($kinds['event']['save']) && ! isset($kinds['event']['question']) // only WHAT can be added; the wording is the worker's
            && $r['context']['today'] === '2026-10-28'
            && $r['context']['members'] === [['id' => $users[0]->id, 'name' => 'Ana Rivera'], ['id' => $users[1]->id, 'name' => 'Lucia Rivera']]
            && $r['context']['screen'] === ['module' => 'vehicles', 'label' => 'Vehicles', 'about' => 'the family has no vehicles yet']
            && $r['context']['habits']['counts']['tasks'] === 0
            && $r['meta'] === ['family' => $family->id, 'user' => $users[0]->id];
    });
    expect(DB::table('ai_usage')->where('feature', 'add_read')->orderBy('id')->get(['provider', 'model', 'cost_micros'])->map(fn ($r) => (array) $r)->all())->toBe([
        ['provider' => 'cloudflare', 'model' => 'clef', 'cost_micros' => 72],
        ['provider' => 'gemini', 'model' => 'gemini-3-flash-preview', 'cost_micros' => 290],
    ])->and((int) DB::table('ai_usage')->sum('points'))->toBe(0); // reading is free
});

it('says the reader is unavailable when the worker fails', function () {
    [, $users] = anythingFamily();
    fakeReader(status: 503);
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertStatus(503);
});

it('stops reading when the family has used its monthly points, and never limits a self-hosted install', function () {
    [$family, $users] = anythingFamily();
    fakeReader();
    DB::table('ai_usage')->insert(['family_id' => $family->id, 'feature' => 'add_saved', 'provider' => 'familywell', 'model' => 'points', 'points' => 50, 'created_at' => now()]);

    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertOk(); // billing off: unlimited

    config(['services.stripe.key' => 'sk_test']);
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertStatus(409)->assertJsonPath('outOfPoints', true);
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('ai.pointsLeft', 0)->assertJsonPath('ai.available', true);

    Carbon::setTestNow('2026-11-01 00:01'); // a new month, a new allowance
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertOk();
});

it('is Classic only without the reader (a self-hosted install)', function () {
    [, $users] = anythingFamily();
    config(['services.add_reader.url' => null]);
    actingAs($users[0])->postJson('/add/read', ['text' => 'milk'])->assertNotFound();
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('ai.available', false);
    actingAs($users[0])->get('/dashboard')->assertInertia(fn ($p) => $p->where('aiAdd', false)->etc());
});

it('creates a whole list on request', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'task_list', 'values' => ['name' => 'Bogdan']]]])->assertOk();
    expect(TaskList::withoutGlobalScopes()->pluck('name')->all())->toBe(['Bogdan']);
});

it('calls Gemini through the AI Gateway with the key stored there (BYOK), tagged per family and user', function () {
    [$family, $users] = anythingFamily();
    config(['services.ai_gateway' => ['account' => 'acc', 'id' => 'familywell', 'token' => 'gw'], 'services.gemini.via_gateway' => true]);
    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 2]])]);
    $this->actingAs($users[0]);
    CurrentFamily::set($family->id);

    expect(AiGateway::gemini())->toBeTrue(); // no key in the app, still on
    AiGateway::run('receipt_scan', ['scan' => ['gemini-3-flash-preview', ['contents' => []]]]);

    Http::assertSent(fn ($r) => $r->url() === 'https://gateway.ai.cloudflare.com/v1/acc/familywell/google-ai-studio/v1beta/models/gemini-3-flash-preview:generateContent'
        && ! $r->hasHeader('x-goog-api-key')
        && $r->header('cf-aig-authorization') === ['Bearer gw']
        && json_decode($r->header('cf-aig-metadata')[0], true) === ['family' => $family->id, 'user' => $users[0]->id, 'feature' => 'receipt_scan', 'env' => 'testing']);
});

it('saves the confirmed cards through each module and charges one point for the lot', function () {
    [$family, $users] = anythingFamily();
    config(['services.stripe.key' => 'sk_test']); // hosted: points count

    actingAs($users[0])->postJson('/add/save', ['path' => '/dashboard', 'items' => [
        ['kind' => 'event', 'values' => ['title' => 'Field trip', 'start' => '2026-10-30T08:15:00', 'end' => '2026-10-30T14:30:00', 'who' => 'Lucia']],
        ['kind' => 'todo', 'values' => ['title' => 'Sign the permission slip', 'due' => '2026-10-28']],
        ['kind' => 'expense', 'values' => ['amount' => 12, 'note' => 'Field trip fee']],
        ['kind' => 'shopping_item', 'values' => ['items' => [['name' => 'Bananas'], ['name' => 'Schweppes Lămâie și Zmeură', 'quantity' => 'six pack']]]],
    ]])->assertOk()->assertJsonPath('pointsLeft', config('familywell.tiers.basic.ai_points') - 1);

    $event = CalendarEvent::withoutGlobalScopes()->firstOrFail();
    expect($event->start_time)->toStartWith('08:15')->and($event->end_date->toDateString())->toBe('2026-10-30')
        ->and(Task::withoutGlobalScopes()->value('title'))->toBe('Sign the permission slip')
        ->and(Spend::withoutGlobalScopes()->first())->amount->toEqual(1200)->note->toBe('Field trip fee')
        ->and(ShoppingItem::withoutGlobalScopes()->pluck('quantity', 'name')->all())->toBe(['Bananas' => null, 'Schweppes Lămâie și Zmeură' => 'six pack'])
        ->and((int) DB::table('ai_usage')->where('family_id', $family->id)->sum('points'))->toBe(1);

    // Bananas again: merged into the open one, not added twice.
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'shopping_item', 'values' => ['items' => [['name' => 'bananas']]]]]])->assertOk();
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
        ['kind' => 'shopping_item', 'values' => ['items' => [['name' => 'Milk']]]],
    ]])->assertOk();

    expect(ShoppingItem::withoutGlobalScopes()->firstOrFail()->shopping_list_id)->not->toBe($secret->id);
});
