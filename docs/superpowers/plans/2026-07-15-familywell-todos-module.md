# Familywell Todos Module Implementation Plan (P5)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Prove-it rule: logic steps are TDD (FAIL then PASS with real output); viewable steps capture a real Playwright screenshot into `~/familywell-proof/` and print `PROOF-SHOT: <path>`. Always wrap potentially-blocking shell commands in `timeout`.

**Goal:** A first-party **Todos** module, built entirely on the module API (P2), the slot UI (P3), and realtime (P4). Family-scoped todo items with shared/private visibility, full CRUD, and live sync across family sessions. Proves the platform carries a real feature.

**Architecture:** `modules/todos/` (namespace `Modules\Todos`, loaded by the P2 runtime PSR-4). `TodoItem` uses the P1 `BelongsToFamily` scope (auto family isolation). Shared items are visible to all members; private items only to their creator. The module registers gated routes (`module:todos`) and a pre-built dashboard widget (P3 slot). Mutations broadcast `TodosChanged` on the family private channel (P4); the widget refetches on that event, so all family sessions stay in sync.

**Tech Stack:** P1-P4 stack. Executed via the `fwdev` container; live proof via Playwright.

## Global Constraints

- Inherits P1-P4 constraints (tenancy via Context, null test broadcaster, namespacing, no design values hardcoded, no em-dash).
- Module `todos` owns the `todos_` table prefix, `/m/todos` routes, `todos.` hooks (spec 12.3).
- Visibility is enforced server-side on every read and mutation; never trust the client.

---

### Task 1: Todos module skeleton, data, tenancy, and visibility scope

**Files:**
- Create: `modules/todos/module.json`
- Create: `modules/todos/TodosServiceProvider.php`
- Create: `modules/todos/TodoItem.php`
- Create: `modules/todos/database/migrations/2026_07_15_200001_create_todos_items_table.php`
- Test: `tests/Feature/Todos/TodoItemTest.php`

**Interfaces:**
- `Modules\Todos\TodoItem` (table `todos_items`), `BelongsToFamily`, fillable `title,done,visibility,created_by`, cast `done:boolean`, scope `visibleTo(int $userId)` (shared items OR items created by that user).
- Empty `TodosServiceProvider` for now (routes added in Task 2).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Todos/TodoItemTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Todos\TodoItem;

beforeEach(function () {
    $this->artisan('modules:migrate')->assertExitCode(0); // creates todos_items
});

it('scopes items to the active family and by visibility', function () {
    $famA = Family::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    CurrentFamily::set($famA->id);
    TodoItem::create(['title' => 'shared task', 'visibility' => 'shared', 'created_by' => $alice->id]);
    TodoItem::create(['title' => 'alice private', 'visibility' => 'private', 'created_by' => $alice->id]);
    TodoItem::create(['title' => 'bob private', 'visibility' => 'private', 'created_by' => $bob->id]);

    // Bob sees shared + his own private, not alice's private.
    $titles = TodoItem::visibleTo($bob->id)->pluck('title')->all();
    expect($titles)->toContain('shared task', 'bob private')
        ->not->toContain('alice private');
});

it('installs the todos module', function () {
    expect(app(ModuleRegistry::class)->isInstalled('todos'))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `timeout 60 docker exec fwdev php artisan test --filter=TodoItemTest`
Expected: FAIL (module/class/table missing).

- [ ] **Step 3: Create the manifest**

```json
// modules/todos/module.json
{
    "id": "todos",
    "name": "Todos",
    "version": "1.0.0",
    "provider": "Modules\\Todos\\TodosServiceProvider",
    "frontend": "resources/dist/todos.js",
    "requires": []
}
```

- [ ] **Step 4: Create the migration**

```php
<?php
// modules/todos/database/migrations/2026_07_15_200001_create_todos_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('todos_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->string('visibility')->default('shared'); // 'shared' | 'private'
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos_items');
    }
};
```

- [ ] **Step 5: Create the model**

```php
<?php
// modules/todos/TodoItem.php
namespace Modules\Todos;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TodoItem extends Model
{
    use BelongsToFamily;

    protected $table = 'todos_items';

    protected $fillable = ['title', 'done', 'visibility', 'created_by'];

    protected $casts = ['done' => 'boolean'];

    // Shared items are visible to everyone in the family; private items only to their creator.
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by', $userId));
    }
}
```

- [ ] **Step 6: Create the (empty) service provider**

```php
<?php
// modules/todos/TodosServiceProvider.php
namespace Modules\Todos;

use Illuminate\Support\ServiceProvider;

class TodosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Routes are registered in Task 2.
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `timeout 60 docker exec fwdev php artisan test --filter=TodoItemTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add modules/todos/module.json modules/todos/TodosServiceProvider.php modules/todos/TodoItem.php modules/todos/database tests/Feature/Todos/TodoItemTest.php
git commit -m "feat(todos): module skeleton, todos_items data, tenancy + visibility scope"
```

---

### Task 2: CRUD routes, visibility enforcement, and per-family gating

**Files:**
- Create: `modules/todos/TodosController.php`
- Modify: `modules/todos/TodosServiceProvider.php` (register routes)
- Test: `tests/Feature/Todos/TodosCrudTest.php`

**Interfaces:**
- Routes behind `['web','auth','module:todos']`:
  - `GET /m/todos/items` (name `todos.index`) -> visible items for the current user.
  - `POST /m/todos/items` (`todos.store`) -> create `{title, visibility}`, `created_by` = auth id.
  - `PATCH /m/todos/items/{item}` (`todos.update`) -> toggle `done` (authorized: shared or owner).
  - `DELETE /m/todos/items/{item}` (`todos.destroy`) -> delete (authorized: owner only).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Todos/TodosCrudTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Todos\TodoItem;

function todosFamily(): array {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('todos', $family->id);
    return [$user, $family];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    $this->actingAs($user)->getJson('/m/todos/items')->assertNotFound();
});

it('creates, lists, toggles, and deletes an item', function () {
    [$user, $family] = todosFamily();

    $this->actingAs($user)->postJson('/m/todos/items', ['title' => 'Buy milk', 'visibility' => 'shared'])
        ->assertOk()->assertJsonPath('title', 'Buy milk');

    $item = TodoItem::withoutGlobalScopes()->first();
    $this->actingAs($user)->getJson('/m/todos/items')->assertOk()->assertJsonFragment(['title' => 'Buy milk']);

    $this->actingAs($user)->patchJson("/m/todos/items/{$item->id}")->assertOk()->assertJsonPath('done', true);
    $this->actingAs($user)->deleteJson("/m/todos/items/{$item->id}")->assertOk();

    expect(TodoItem::withoutGlobalScopes()->count())->toBe(0);
});

it('hides another member private item', function () {
    [$alice, $family] = todosFamily();
    $bob = User::factory()->create();
    $family->members()->attach($bob, ['role' => 'member']);

    $this->actingAs($bob)->postJson('/m/todos/items', ['title' => 'bob secret', 'visibility' => 'private'])->assertOk();

    // Alice cannot see bob's private item.
    $this->actingAs($alice)->getJson('/m/todos/items')->assertOk()->assertJsonMissing(['title' => 'bob secret']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `timeout 60 docker exec fwdev php artisan test --filter=TodosCrudTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Write the controller**

```php
<?php
// modules/todos/TodosController.php
namespace Modules\Todos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TodosController extends Controller
{
    public function index(Request $request)
    {
        return TodoItem::visibleTo($request->user()->id)->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'visibility' => ['required', 'in:shared,private'],
        ]);

        return TodoItem::create([
            'title' => $data['title'],
            'visibility' => $data['visibility'],
            'created_by' => $request->user()->id,
        ]);
    }

    public function update(Request $request, TodoItem $item)
    {
        abort_unless($item->visibility === 'shared' || $item->created_by === $request->user()->id, 403);
        $item->update(['done' => ! $item->done]);

        return $item;
    }

    public function destroy(Request $request, TodoItem $item)
    {
        abort_unless($item->created_by === $request->user()->id, 403);
        $item->delete();

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Register the routes in the provider**

```php
<?php
// modules/todos/TodosServiceProvider.php
namespace Modules\Todos;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TodosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'module:todos'])->group(function () {
            Route::get('/m/todos/items', [TodosController::class, 'index'])->name('todos.index');
            Route::post('/m/todos/items', [TodosController::class, 'store'])->name('todos.store');
            Route::patch('/m/todos/items/{item}', [TodosController::class, 'update'])->name('todos.update');
            Route::delete('/m/todos/items/{item}', [TodosController::class, 'destroy'])->name('todos.destroy');
        });
    }
}
```

Note: route-model binding for `{item}` resolves `TodoItem` via the `todos` route param name mismatch. Bind explicitly by using `{item}` and type-hint `TodoItem $item`; Laravel resolves it and the `BelongsToFamily` global scope keeps it family-scoped automatically.

- [ ] **Step 5: Run the test to verify it passes**

Run: `timeout 60 docker exec fwdev php artisan test --filter=TodosCrudTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add modules/todos/TodosController.php modules/todos/TodosServiceProvider.php tests/Feature/Todos/TodosCrudTest.php
git commit -m "feat(todos): CRUD routes with visibility enforcement and module gating"
```

---

### Task 3: Broadcast TodosChanged on mutations

**Files:**
- Create: `app/Events/TodosChanged.php`
- Modify: `modules/todos/TodosController.php` (broadcast after store/update/destroy)
- Test: `tests/Feature/Todos/TodosChangedTest.php`

**Interfaces:**
- `TodosChanged implements ShouldBroadcastNow`, `broadcastOn` -> `PrivateChannel("family.{id}")`, `broadcastAs` -> `'TodosChanged'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Todos/TodosChangedTest.php
use App\Events\TodosChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts on the family channel', function () {
    $e = new TodosChanged(9);
    expect($e->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($e->broadcastOn()[0]->name)->toBe('private-family.9')
        ->and($e->broadcastAs())->toBe('TodosChanged');
});

it('dispatches TodosChanged when an item is created', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $this->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('todos', $family->id);

    Event::fake([TodosChanged::class]);
    $this->actingAs($user)->postJson('/m/todos/items', ['title' => 'x', 'visibility' => 'shared'])->assertOk();
    Event::assertDispatched(TodosChanged::class, fn ($e) => $e->familyId === $family->id);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `timeout 60 docker exec fwdev php artisan test --filter=TodosChangedTest`
Expected: FAIL (class missing).

- [ ] **Step 3: Write the event**

```php
<?php
// app/Events/TodosChanged.php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TodosChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $familyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("family.{$this->familyId}")];
    }

    public function broadcastAs(): string
    {
        return 'TodosChanged';
    }
}
```

- [ ] **Step 4: Broadcast from the controller**

Add a private helper and call it after each mutation in `TodosController`:

```php
// add import at top: use App\Events\TodosChanged; use App\Support\Tenancy\CurrentFamily;
// after create in store(), after update in update(), after delete in destroy(), call:
TodosChanged::dispatch((int) CurrentFamily::id());
```

Concretely, `store` returns after dispatch:

```php
$item = TodoItem::create([...]);
TodosChanged::dispatch((int) CurrentFamily::id());
return $item;
```
and similarly a `TodosChanged::dispatch((int) CurrentFamily::id());` line before the return in `update` and `destroy`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `timeout 60 docker exec fwdev php artisan test --filter=TodosChangedTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Full suite (no hang, null broadcaster)**

Run: `timeout 180 docker exec fwdev php artisan test`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Events/TodosChanged.php modules/todos/TodosController.php tests/Feature/Todos/TodosChangedTest.php
git commit -m "feat(todos): broadcast TodosChanged on mutations for live sync"
```

---

### Task 4: Todos widget frontend, live sync, and screenshot proof

**Files:**
- Create: `modules/todos/resources/dist/todos.js`
- Proof: Playwright screenshots (add item, toggle, live sync across two sessions)

**Interfaces:**
- `modules/todos/resources/dist/todos.js` is a pre-built ESM that registers a `dashboard.widgets` component: lists visible todos, an add form, toggle checkboxes, and refetches on the `.TodosChanged` Echo event.

- [ ] **Step 1: Write the todos widget**

```js
// modules/todos/resources/dist/todos.js
// Pre-built module frontend: shell runtime + fetch (CSRF via XSRF-TOKEN cookie), no bundler.
const { React, registerSlot } = window.Familywell;

function csrf() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}
async function api(method, url, body) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.ok ? res.json() : null;
}

function TodosWidget() {
    const [items, setItems] = React.useState([]);
    const [title, setTitle] = React.useState('');

    const load = React.useCallback(() => {
        api('GET', '/m/todos/items').then((d) => d && setItems(d));
    }, []);

    React.useEffect(() => {
        load();
        const fid = window.__familywellFamilyId;
        if (window.Echo && fid) {
            window.Echo.private('family.' + fid).listen('.TodosChanged', load);
            return () => window.Echo.leave('family.' + fid);
        }
    }, [load]);

    async function add(e) {
        e.preventDefault();
        if (!title.trim()) return;
        await api('POST', '/m/todos/items', { title, visibility: 'shared' });
        setTitle('');
        load();
    }
    async function toggle(id) {
        await api('PATCH', '/m/todos/items/' + id);
        load();
    }

    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'todos-widget' },
        React.createElement('h3', { className: 'font-semibold mb-2' }, 'Family Todos'),
        React.createElement(
            'form',
            { onSubmit: add, className: 'flex gap-2 mb-3' },
            React.createElement('input', {
                value: title,
                onChange: (e) => setTitle(e.target.value),
                placeholder: 'Add a todo',
                'data-testid': 'todo-input',
                className: 'flex-1 rounded border px-2 py-1 text-sm',
            }),
            React.createElement('button', { type: 'submit', className: 'rounded border px-2 py-1 text-sm' }, 'Add'),
        ),
        React.createElement(
            'ul',
            { 'data-testid': 'todo-list', className: 'space-y-1' },
            items.map((it) =>
                React.createElement(
                    'li',
                    { key: it.id, className: 'flex items-center gap-2 text-sm' },
                    React.createElement('input', {
                        type: 'checkbox',
                        checked: !!it.done,
                        onChange: () => toggle(it.id),
                    }),
                    React.createElement('span', { className: it.done ? 'line-through text-muted-foreground' : '' }, it.title),
                ),
            ),
        ),
    );
}

registerSlot('dashboard.widgets', TodosWidget);
```

- [ ] **Step 2: Rebuild image, boot the full stack, install + migrate**

```bash
timeout 300 docker compose up -d --build
sleep 12
timeout 40 docker compose exec -T app php artisan migrate:fresh --force
timeout 40 docker compose exec -T app php artisan modules:migrate
timeout 10 curl -s -o /dev/null -w "login %{http_code}\n" http://localhost:8000/login
```

- [ ] **Step 3: Playwright proof (add, toggle, live cross-session)**

Two browser contexts sharing one family:
1. Register user, enable `todos`, open dashboard in context A. Screenshot empty -> `p5-todos-empty.png`.
2. In A: add "Buy milk" -> appears in the list. Screenshot -> `p5-todos-added.png`. Assert the item text is present.
3. Open the dashboard in a second context B (same user session cookies copied, or log in as the same user). Add "Call dentist" in A; assert B's list updates live (via `.TodosChanged`) within 15s. Screenshot B -> `p5-todos-live-sync.png`. Assert "Call dentist" appears in B.
4. Toggle "Buy milk" done in A; assert it renders struck-through. Screenshot -> `p5-todos-toggled.png`.

Print `RESULT ADDED=<bool> LIVE_SYNC=<bool> TOGGLED=<bool>` and `PROOF-SHOT:` lines.

- [ ] **Step 4: Verify results and view a screenshot**

Expected: `ADDED=true LIVE_SYNC=true TOGGLED=true`, all PNGs exist and render the widget.

- [ ] **Step 5: Commit**

```bash
git add modules/todos/resources/dist/todos.js
git commit -m "feat(todos): dashboard widget with add/toggle and live cross-session sync"
```

---

## Self-Review

- Real feature module on the module API (spec 4): manifest/provider/data/routes/UI -> Tasks 1-4. Tenancy (family scope) + shared/private visibility (spec 6, 7) -> Tasks 1, 2. Realtime live sync (spec 5) -> Tasks 3, 4. Dogfood -> `todos` end to end.
- Prove-it: Tasks 1-3 are Pest TDD; Task 4 is a live Playwright assertion (add/live-sync/toggle) plus screenshots.
- Deferred (honest): multiple named lists, due dates/assignment, a dedicated full-page module route (P3 slots cover the widget; module pages are follow-on). P5 proves a real, family-scoped, realtime feature module.
