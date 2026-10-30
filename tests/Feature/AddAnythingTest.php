<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Add\AddContext;
use App\Support\Ai\AiGateway;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Calendar\CalendarEvent;
use Modules\Projects\Project;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;
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

it('offers the reader the person\'s lists, and files several to-dos into the list it named', function () {
    [, $users] = anythingFamily();
    foreach (['Household', 'Oana'] as $name) {
        actingAs($users[0])->post('/m/tasks/lists', ['name' => $name]);
    }
    // A photographed note headed "Oana" with jotted tasks: four to-do cards, each naming the existing list.
    $card = fn (int $n, string $title) => ['id' => "todo-{$n}", 'kind' => 'todo', 'module' => 'tasks', 'p' => 0.84, 'sure' => true,
        'fields' => ['title' => ['value' => $title, 'confidence' => 0.8], 'list' => ['value' => 'Oana', 'confidence' => 0.8]],
        'ask' => [], 'missing' => [], 'types' => ['title' => 'text', 'due' => 'date', 'who' => 'text', 'list' => 'text']];
    fakeReader([$card(0, 'reactualizat CV-ul model nou'), $card(1, 'site făcut')]);

    $items = actingAs($users[0])->postJson('/add/read', ['text' => '', 'images' => ['data:image/jpeg;base64,/9j/AAAA']])->assertOk()->json('items');

    expect(collect($items)->pluck('target')->all())->toBe(['Oana', 'Oana'])
        ->and($items[0]['hidden'])->toBe(['list']);
    Http::assertSent(fn ($r) => $r['kinds']['todo']['fields']['list'] === ['type' => 'text', 'options' => ['Household', 'Oana']]);

    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'todo', 'values' => ['title' => 'reactualizat CV-ul model nou', 'list' => 'Oana']],
        ['kind' => 'todo', 'values' => ['title' => 'site făcut', 'list' => 'oana']], // any case
    ]])->assertOk();
    $oana = TaskList::withoutGlobalScopes()->where('name', 'Oana')->firstOrFail();
    expect(Task::withoutGlobalScopes()->where('task_list_id', $oana->id)->pluck('title')->all())->toBe(['reactualizat CV-ul model nou', 'site făcut']);
});

it('saves a period read as whole days as an all-day event across those days', function () {
    [, $users] = anythingFamily();
    // "Vacanța copiilor săptămâna asta": the card shows dates only and sends them as such.
    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'event', 'values' => ['title' => 'Vacanța copiilor', 'start' => '2026-10-26', 'end' => '2026-11-01']],
    ]])->assertOk();

    $e = CalendarEvent::withoutGlobalScopes()->firstOrFail();
    expect($e->start_date->toDateString())->toBe('2026-10-26')->and($e->end_date->toDateString())->toBe('2026-11-01')
        ->and($e->start_time)->toBeNull()->and($e->end_time)->toBeNull();
});

it('turns a recording into words through the reader, priced per second of audio', function () {
    [$family, $users] = anythingFamily();
    Http::fake(['reader.test/transcribe' => Http::response(['text' => 'Cumpără lapte și pâine', 'language' => 'ro', 'usage' => [
        ['provider' => 'workers-ai', 'model' => 'whisper-large-v3-turbo', 'input_tokens' => 6, 'output_tokens' => 0, 'ms' => 2000, 'ok' => true],
    ]])]);

    actingAs($users[0])->post('/add/transcribe', ['audio' => UploadedFile::fake()->create('voice.webm', 40, 'audio/webm'), 'path' => '/dashboard'], ['Accept' => 'application/json'])
        ->assertOk()->assertExactJson(['text' => 'Cumpără lapte și pâine']);

    Http::assertSent(fn ($r) => $r->url() === 'https://reader.test/transcribe' && $r->header('Authorization') === ['Bearer sec']
        && is_string($r['audio']) && $r['meta'] === ['family' => $family->id, 'user' => $users[0]->id]);
    expect(DB::table('ai_usage')->where('feature', 'add_voice')->first())
        ->model->toBe('whisper-large-v3-turbo')->cost_micros->toBe(51) // 6 s × $8.55 per million seconds
        ->points->toBe(0); // listening is free; the save is the AI action

    // Not audio: refused before it reaches the reader.
    actingAs($users[0])->post('/add/transcribe', ['audio' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

    config(['services.add_reader.url' => null]); // self-hosted: no voice either
    actingAs($users[0])->post('/add/transcribe', ['audio' => UploadedFile::fake()->create('voice.webm', 40, 'audio/webm')], ['Accept' => 'application/json'])->assertNotFound();
});

it('starts a project with its first tasks, and files a task into the project it named', function () {
    [$family, $users] = anythingFamily();
    app(ModuleRegistry::class)->enable('projects', $family->id);

    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'project', 'values' => ['name' => 'Gard Pietroșani', 'tasks' => [['name' => 'cumpără stâlpi', 'quantity' => null], ['name' => 'sună la constructor']]]],
    ]])->assertOk();
    $gard = Project::withoutGlobalScopes()->where('name', 'Gard Pietroșani')->firstOrFail();
    $titles = fn () => Task::withoutGlobalScopes()->whereIn('id', $gard->taskIds())->orderBy('id');
    expect($titles()->pluck('title')->all())->toBe(['cumpără stâlpi', 'sună la constructor']);

    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'project_task', 'values' => ['title' => 'comandă plasă', 'project' => 'gard pietroșani', 'who' => 'Lucia']],
    ]])->assertOk();
    expect($titles()->where('title', 'comandă plasă')->value('assigned_to'))->toBe($users[1]->id);

    // Neither named nor on a project's page: no guess, the card asks for the project.
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'project_task', 'values' => ['title' => 'ceva']]]])->assertUnprocessable();
});

it('tells the reader which project is on screen and the shopping lists attached to it', function () {
    [$family, $users] = anythingFamily();
    app(ModuleRegistry::class)->enable('projects', $family->id);
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Gard materials']);
    $project = Project::createFor($users[0], ['name' => 'Gard']);
    $project->links()->create(['linkable_type' => 'shopping_list', 'linkable_id' => ShoppingList::withoutGlobalScopes()->value('id')]);
    fakeReader([['kind' => 'project_task', 'module' => 'projects', 'p' => 0.9, 'sure' => true, 'fields' => ['title' => ['value' => 'Call the builder', 'confidence' => 0.9]], 'ask' => [], 'missing' => [], 'types' => ['title' => 'text']]]);

    $items = actingAs($users[0])->postJson('/add/read', ['text' => 'call the builder', 'path' => "/m/projects/{$project->id}"])->assertOk()->json('items');

    expect($items[0]['target'])->toBe('Gard'); // the project on screen
    Http::assertSent(fn ($r) => $r['context']['screen']['about'] === 'the project Gard, its shopping lists: Gard materials'
        && $r['kinds']['project_task']['fields']['project'] === ['type' => 'text', 'options' => ['Gard']]);
});

it('buys things at the shop it read (keeping a known shop\'s spelling) and adds a shop on its own', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->post('/m/shopping/shops', ['name' => 'Lidl']);

    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'shopping_item', 'values' => ['items' => [['name' => 'milk']], 'shop' => 'lidl']],
        ['kind' => 'shopping_shop', 'values' => ['name' => 'Kaufland']],
    ]])->assertOk();

    expect(ShoppingItem::withoutGlobalScopes()->firstOrFail()->store)->toBe('Lidl')
        ->and(ShoppingShop::withoutGlobalScopes()->orderBy('name')->pluck('name')->all())->toBe(['Kaufland', 'Lidl']);
});

it('reads a shop\'s page as the Shopping screen, not as the shopping list with the same id', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->firstOrFail();

    expect(AddContext::for($users[0], "/m/shopping/shops/{$list->id}")->entityId)->toBeNull()
        ->and(AddContext::for($users[0], "/m/shopping/lists/{$list->id}")->entityId)->toBe($list->id);
});

it('puts an event\'s to-dos on the event, and files a to-do into the event it named or the one on screen', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->postJson('/add/save', ['items' => [
        ['kind' => 'event', 'values' => ['title' => 'School trip', 'start' => '2026-10-30T00:00:00', 'tasks' => [['name' => 'pack lunch'], ['name' => 'sign the form']]]],
    ]])->assertOk();
    $trip = CalendarEvent::withoutGlobalScopes()->firstOrFail();
    $todos = fn () => Task::withoutGlobalScopes()->whereIn('id', $trip->taskIds())->orderBy('id')->pluck('title')->all();
    expect($todos())->toBe(['pack lunch', 'sign the form']);

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'event_task', 'values' => ['title' => 'charge the camera', 'event' => 'school trip']]]])->assertOk();
    actingAs($users[0])->postJson('/add/save', ['path' => "/m/calendar/events/{$trip->id}", 'items' => [['kind' => 'event_task', 'values' => ['title' => 'pack a raincoat']]]])->assertOk();
    expect($todos())->toBe(['pack lunch', 'sign the form', 'charge the camera', 'pack a raincoat']);

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'event_task', 'values' => ['title' => 'ceva']]]])->assertUnprocessable();
});

// "Add details" on a card (design H2f): fields the reader never reads, filled by hand, saved through the module's own
// create path and the add-ons' task.saved hooks like a Classic add.

function detailsFamily(): array
{
    [$family, $users] = anythingFamily();
    foreach (['important_todos', 'recurring_todos'] as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }
    foreach (['Household', 'School'] as $name) {
        actingAs($users[0])->post('/m/tasks/lists', ['name' => $name]);
    }

    return [$family, $users, TaskList::withoutGlobalScopes()->where('name', 'School')->firstOrFail()];
}

it('keeps details fields from the reader and hands the sheet their form, choices and the family', function () {
    [, $users, $school] = detailsFamily();
    fakeReader([['id' => 'todo-0', 'kind' => 'todo', 'module' => 'tasks', 'p' => 0.9, 'sure' => true, 'fields' => ['title' => ['value' => 'Fix the bedroom door', 'confidence' => 0.9]],
        'ask' => [], 'missing' => [], 'types' => ['title' => 'text', 'due' => 'date', 'who' => 'text', 'list' => 'text']]]);

    $res = actingAs($users[0])->postJson('/add/read', ['text' => 'fix the bedroom door'])->assertOk();

    Http::assertSent(fn ($r) => array_keys($r['kinds']['todo']['fields']) === ['title', 'due', 'who', 'list']
        && ! isset($r['kinds']['expense']['fields']['category']) && ! isset($r['kinds']['shopping_item']['fields']['note']));
    expect($res->json('items.0.details'))->toMatchArray([
        'note' => ['type' => 'note'],
        'list_id' => ['type' => 'choice', 'choices' => [['value' => (string) ($school->id - 1), 'label' => 'Household'], ['value' => (string) $school->id, 'label' => 'School']]],
        'ext' => ['type' => 'slot', 'slot' => 'task.editor.fields', 'badges' => 'task.row.badges'],
    ])->and($res->json('items.0.people'))->toBe(['who'])
        ->and($res->json('members'))->toBe([['id' => $users[0]->id, 'name' => 'Ana Rivera'], ['id' => $users[1]->id, 'name' => 'Lucia Rivera']]);
});

it('saves a to-do with its details like the Classic editor: note, date, who, list, and the add-ons\' Important and Repeat', function () {
    [, $users, $school] = detailsFamily();

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'todo', 'values' => [
        'title' => 'Fix the bedroom door', 'note' => 'the hinge squeaks', 'due' => '2026-10-31', 'who' => 'Lucia',
        'list_id' => (string) $school->id, 'ext' => ['important' => true, 'recurrence' => 'weekly'],
    ]]]])->assertOk();

    $task = Task::withoutGlobalScopes()->firstOrFail();
    expect($task->only(['title', 'note', 'task_list_id', 'assigned_to']))->toBe([
        'title' => 'Fix the bedroom door', 'note' => 'the hinge squeaks', 'task_list_id' => $school->id, 'assigned_to' => $users[1]->id,
    ])->and($task->due_date->toDateString())->toBe('2026-10-31')
        ->and((bool) DB::table('important_todos_flags')->where('task_id', $task->id)->value('important'))->toBeTrue()
        ->and(DB::table('recurring_todos_rules')->where('task_id', $task->id)->value('interval'))->toBe('weekly');
});

it('refuses details that do not hold up, and a list the person cannot add to', function () {
    [, $users] = detailsFamily();
    actingAs($users[1])->post('/m/tasks/lists', ['name' => 'Lucia only']);
    $private = TaskList::withoutGlobalScopes()->where('name', 'Lucia only')->firstOrFail();
    $private->setVisibility('private');

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'todo', 'values' => ['title' => 'x', 'note' => str_repeat('a', 256)]]]])
        ->assertUnprocessable()->assertJsonValidationErrors('note');
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'todo', 'values' => ['title' => 'x', 'list_id' => (string) $private->id]]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['list_id' => "That list isn't there any more"]);
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'expense', 'values' => ['amount' => 5, 'category' => 'not-a-category']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('category');
    expect(Task::withoutGlobalScopes()->count())->toBe(0)->and(Spend::withoutGlobalScopes()->count())->toBe(0);
});

it('says why a 4th important to-do is refused, and saves none of that batch', function () {
    [, $users] = detailsFamily();
    $todo = fn (string $title) => ['kind' => 'todo', 'values' => ['title' => $title, 'ext' => ['important' => true]]];
    actingAs($users[0])->postJson('/add/save', ['items' => [$todo('one'), $todo('two'), $todo('three')]])->assertOk();

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'todo', 'values' => ['title' => 'plain']], $todo('four')]])
        ->assertUnprocessable()->assertJsonPath('message', 'You already have 3 important to-dos open. Finish or unstar one first.');
    expect(Task::withoutGlobalScopes()->pluck('title')->all())->toBe(['one', 'two', 'three']);
});

it('saves an expense into the list and category picked in its details', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $trip = SpendEvent::withoutGlobalScopes()->where('name', 'Trip')->firstOrFail();

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'expense', 'values' => ['amount' => 30, 'note' => 'Museum', 'category' => 'activities', 'list_id' => (string) $trip->id]]]])->assertOk();

    expect(Spend::withoutGlobalScopes()->firstOrFail()->only(['spend_event_id', 'category']))->toBe(['spend_event_id' => $trip->id, 'category' => 'activities']);
});

it('saves shopping things with the note, person, date and list from their details', function () {
    [, $users] = anythingFamily();
    foreach (['Weekly', 'Party'] as $name) {
        actingAs($users[0])->post('/m/shopping/lists', ['name' => $name]);
    }
    $party = ShoppingList::withoutGlobalScopes()->where('name', 'Party')->firstOrFail();

    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'shopping_item', 'values' => [
        'items' => [['name' => 'Cheese'], ['name' => 'Eggs', 'quantity' => '10']], 'shop' => 'Lidl',
        'note' => 'the good kind', 'who' => 'Lucia', 'due' => '2026-10-30', 'list_id' => (string) $party->id,
    ]]]])->assertOk();

    $items = ShoppingItem::withoutGlobalScopes()->orderBy('id')->get();
    expect($items->pluck('shopping_list_id')->unique()->all())->toBe([$party->id])
        ->and($items->pluck('note')->unique()->all())->toBe(['the good kind'])
        ->and($items->pluck('assigned_to')->unique()->all())->toBe([$users[1]->id])
        ->and($items[0]->due_date->toDateString())->toBe('2026-10-30')
        ->and($items->pluck('store')->unique()->all())->toBe(['Lidl']);
});

it('saves an event with the notes and repeat from its details', function () {
    [, $users] = anythingFamily();
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'event', 'values' => ['title' => 'Piano', 'start' => '2026-10-30T17:00', 'notes' => 'bring the book', 'repeat' => 'weekly']]]])->assertOk();
    actingAs($users[0])->postJson('/add/save', ['items' => [['kind' => 'event', 'values' => ['title' => 'x', 'start' => '2026-10-30', 'repeat' => 'hourly']]]])->assertUnprocessable();

    expect(CalendarEvent::withoutGlobalScopes()->firstOrFail()->only(['notes', 'repeat']))->toBe(['notes' => 'bring the book', 'repeat' => 'weekly']);
});
