# Familywell Shopping Module Implementation Plan (P6)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Prove-it rule: logic steps are TDD (FAIL then PASS with real output); viewable steps capture a real Playwright screenshot into `~/familywell-proof/` and print `PROOF-SHOT: <path>`. Always wrap potentially-blocking shell commands in `timeout`.

**Goal:** A first-party **Shopping** module: family-scoped shopping list items with shared/private visibility, quantity, a bought toggle, full CRUD, and live sync. Mirrors the Todos module (P5) on the same module API (P2), slot UI (P3), realtime (P4).

**Architecture:** `modules/shopping/` (namespace `Modules\Shopping`, loaded by the P2 runtime PSR-4). `ShoppingItem` uses `BelongsToFamily`; shared items visible to all members, private only to the creator. Gated routes (`module:shopping`) + a pre-built dashboard widget. Mutations broadcast `ShoppingChanged` on the family channel; the widget refetches on it.

**Tech Stack:** P1-P5 stack. Executed via `fwdev`; live proof via Playwright (same-context tabs for realtime).

## Global Constraints

- Inherits all prior constraints (tenancy via Context, null test broadcaster, namespacing `shopping_*` / `/m/shopping` / `shopping.`, no design values hardcoded, no em-dash).
- Visibility enforced server-side on every read/mutation.

---

### Task 1: Module skeleton, data, tenancy, visibility

**Files:**
- Create: `modules/shopping/module.json`, `modules/shopping/ShoppingServiceProvider.php`, `modules/shopping/ShoppingItem.php`
- Create: `modules/shopping/database/migrations/2026_07_16_000001_create_shopping_items_table.php`
- Test: `tests/Feature/Shopping/ShoppingItemTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
// tests/Feature/Shopping/ShoppingItemTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;

beforeEach(fn () => $this->artisan('modules:migrate')->assertExitCode(0));

it('scopes items by family and visibility', function () {
    $fam = Family::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    CurrentFamily::set($fam->id);
    ShoppingItem::create(['name' => 'Milk', 'visibility' => 'shared', 'created_by' => $alice->id]);
    ShoppingItem::create(['name' => 'Gift', 'visibility' => 'private', 'created_by' => $alice->id]);

    $names = ShoppingItem::visibleTo($bob->id)->pluck('name')->all();
    expect($names)->toContain('Milk')->not->toContain('Gift');
});

it('installs the shopping module', function () {
    expect(app(ModuleRegistry::class)->isInstalled('shopping'))->toBeTrue();
});
```

- [ ] **Step 2: Run, expect FAIL** - `timeout 60 docker exec fwdev php artisan test --filter=ShoppingItemTest`

- [ ] **Step 3: Manifest** `modules/shopping/module.json`

```json
{
    "id": "shopping",
    "name": "Shopping",
    "version": "1.0.0",
    "provider": "Modules\\Shopping\\ShoppingServiceProvider",
    "frontend": "resources/dist/shopping.js",
    "requires": []
}
```

- [ ] **Step 4: Migration**

```php
<?php
// modules/shopping/database/migrations/2026_07_16_000001_create_shopping_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('quantity')->nullable();
            $table->boolean('bought')->default(false);
            $table->string('visibility')->default('shared');
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
    }
};
```

- [ ] **Step 5: Model**

```php
<?php
// modules/shopping/ShoppingItem.php
namespace Modules\Shopping;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShoppingItem extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_items';

    protected $fillable = ['name', 'quantity', 'bought', 'visibility', 'created_by'];

    protected $casts = ['bought' => 'boolean'];

    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by', $userId));
    }
}
```

- [ ] **Step 6: Provider (empty, routes in Task 2)**

```php
<?php
// modules/shopping/ShoppingServiceProvider.php
namespace Modules\Shopping;

use Illuminate\Support\ServiceProvider;

class ShoppingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Routes registered in Task 2.
    }
}
```

- [ ] **Step 7: Run, expect PASS** - same filter.

- [ ] **Step 8: Commit** - `git add modules/shopping tests/Feature/Shopping/ShoppingItemTest.php && git commit -m "feat(shopping): module skeleton, data, tenancy + visibility"`

---

### Task 2: CRUD routes, visibility, gating

**Files:** Create `modules/shopping/ShoppingController.php`; modify provider; Test `tests/Feature/Shopping/ShoppingCrudTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php
// tests/Feature/Shopping/ShoppingCrudTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Shopping\ShoppingItem;

function shoppingFamily(): array {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    return [$user, $family];
}

it('404s when not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');
    $this->actingAs($user)->getJson('/m/shopping/items')->assertNotFound();
});

it('creates, lists, toggles bought, deletes', function () {
    [$user] = shoppingFamily();
    $this->actingAs($user)->postJson('/m/shopping/items', ['name' => 'Eggs', 'visibility' => 'shared'])
        ->assertCreated()->assertJsonPath('name', 'Eggs');
    $item = ShoppingItem::withoutGlobalScopes()->first();
    $this->actingAs($user)->getJson('/m/shopping/items')->assertOk()->assertJsonFragment(['name' => 'Eggs']);
    $this->actingAs($user)->patchJson("/m/shopping/items/{$item->id}")->assertOk()->assertJsonPath('bought', true);
    $this->actingAs($user)->deleteJson("/m/shopping/items/{$item->id}")->assertOk();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(0);
});

it('hides another member private item', function () {
    [$alice, $family] = shoppingFamily();
    $bob = User::factory()->create();
    $family->members()->attach($bob, ['role' => 'member']);
    $this->actingAs($bob)->postJson('/m/shopping/items', ['name' => 'secret', 'visibility' => 'private'])->assertCreated();
    $this->actingAs($alice)->getJson('/m/shopping/items')->assertOk()->assertJsonMissing(['name' => 'secret']);
});
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Controller**

```php
<?php
// modules/shopping/ShoppingController.php
namespace Modules\Shopping;

use App\Events\ShoppingChanged;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;

class ShoppingController extends Controller
{
    public function index(Request $request)
    {
        return ShoppingItem::visibleTo($request->user()->id)->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:50'],
            'visibility' => ['required', 'in:shared,private'],
        ]);
        $item = ShoppingItem::create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'visibility' => $data['visibility'],
            'created_by' => $request->user()->id,
        ]);
        ShoppingChanged::dispatch((int) CurrentFamily::id());

        return $item;
    }

    public function update(Request $request, ShoppingItem $item)
    {
        abort_unless($item->visibility === 'shared' || $item->created_by === $request->user()->id, 403);
        $item->update(['bought' => ! $item->bought]);
        ShoppingChanged::dispatch((int) CurrentFamily::id());

        return $item;
    }

    public function destroy(Request $request, ShoppingItem $item)
    {
        abort_unless($item->created_by === $request->user()->id, 403);
        $item->delete();
        ShoppingChanged::dispatch((int) CurrentFamily::id());

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Provider routes**

```php
<?php
// modules/shopping/ShoppingServiceProvider.php
namespace Modules\Shopping;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ShoppingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'module:shopping'])->group(function () {
            Route::get('/m/shopping/items', [ShoppingController::class, 'index'])->name('shopping.index');
            Route::post('/m/shopping/items', [ShoppingController::class, 'store'])->name('shopping.store');
            Route::patch('/m/shopping/items/{item}', [ShoppingController::class, 'update'])->name('shopping.update');
            Route::delete('/m/shopping/items/{item}', [ShoppingController::class, 'destroy'])->name('shopping.destroy');
        });
    }
}
```

- [ ] **Step 5: Run, expect PASS. Commit** - `feat(shopping): CRUD routes with visibility and gating`

---

### Task 3: ShoppingChanged broadcast

**Files:** Create `app/Events/ShoppingChanged.php`; Test `tests/Feature/Shopping/ShoppingChangedTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php
// tests/Feature/Shopping/ShoppingChangedTest.php
use App\Events\ShoppingChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts on the family channel', function () {
    $e = new ShoppingChanged(4);
    expect($e->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($e->broadcastOn()[0]->name)->toBe('private-family.4')
        ->and($e->broadcastAs())->toBe('ShoppingChanged');
});

it('dispatches on create', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $this->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('shopping', $family->id);

    Event::fake([ShoppingChanged::class]);
    $this->actingAs($user)->postJson('/m/shopping/items', ['name' => 'x', 'visibility' => 'shared'])->assertCreated();
    Event::assertDispatched(ShoppingChanged::class, fn ($e) => $e->familyId === $family->id);
});
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Event** (copy `app/Events/TodosChanged.php`, rename class + `broadcastAs` to `ShoppingChanged`)

```php
<?php
// app/Events/ShoppingChanged.php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShoppingChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $familyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("family.{$this->familyId}")];
    }

    public function broadcastAs(): string
    {
        return 'ShoppingChanged';
    }
}
```

- [ ] **Step 4: Run, expect PASS. Full suite (`timeout 180 docker exec fwdev php artisan test`). Commit** - `feat(shopping): broadcast ShoppingChanged on mutations`

---

### Task 4: Shopping widget + live proof

**Files:** Create `modules/shopping/resources/dist/shopping.js`. Proof: Playwright screenshots.

- [ ] **Step 1: Widget** (mirrors todos.js; endpoints `/m/shopping/items`, event `.ShoppingChanged`, shows name + quantity, toggle bought)

```js
// modules/shopping/resources/dist/shopping.js
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

function ShoppingWidget() {
    const [items, setItems] = React.useState([]);
    const [name, setName] = React.useState('');
    const load = React.useCallback(() => { api('GET', '/m/shopping/items').then((d) => d && setItems(d)); }, []);
    React.useEffect(() => {
        load();
        const fid = window.__familywellFamilyId;
        if (window.Echo && fid) {
            window.Echo.private('family.' + fid).listen('.ShoppingChanged', load);
            return () => window.Echo.leave('family.' + fid);
        }
    }, [load]);
    async function add(e) {
        e.preventDefault();
        if (!name.trim()) return;
        await api('POST', '/m/shopping/items', { name, visibility: 'shared' });
        setName('');
        load();
    }
    async function toggle(id) { await api('PATCH', '/m/shopping/items/' + id); load(); }
    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'shopping-widget' },
        React.createElement('h3', { className: 'font-semibold mb-2' }, 'Shopping List'),
        React.createElement(
            'form',
            { onSubmit: add, className: 'flex gap-2 mb-3' },
            React.createElement('input', {
                value: name, onChange: (e) => setName(e.target.value), placeholder: 'Add an item',
                'data-testid': 'shopping-input', className: 'flex-1 rounded border px-2 py-1 text-sm',
            }),
            React.createElement('button', { type: 'submit', className: 'rounded bg-primary text-primary-foreground px-3 py-1 text-sm' }, 'Add'),
        ),
        items.length === 0
            ? React.createElement('p', { className: 'text-sm text-muted-foreground', 'data-testid': 'shopping-empty' }, 'Nothing on the list.')
            : React.createElement(
                  'ul',
                  { 'data-testid': 'shopping-list', className: 'space-y-1' },
                  items.map((it) =>
                      React.createElement(
                          'li',
                          { key: it.id, className: 'flex items-center gap-2 text-sm' },
                          React.createElement('input', { type: 'checkbox', checked: !!it.bought, onChange: () => toggle(it.id) }),
                          React.createElement('span', { className: it.bought ? 'line-through text-muted-foreground' : '' }, it.name),
                      ),
                  ),
              ),
    );
}
registerSlot('dashboard.widgets', ShoppingWidget);
```

- [ ] **Step 2: Rebuild image + boot + migrate** (`timeout 300 docker compose up -d --build`; `migrate:fresh`; `modules:migrate`).

- [ ] **Step 3: Playwright proof** (adapt the P5 script: register user, enable `shopping`, dashboard; screenshot `p6-shopping-empty`; add 2 items + toggle one; screenshot `p6-shopping-items`; second tab in same context; add in tab A, assert live in tab B; screenshot `p6-shopping-live-sync`). Print `RESULT` + `PROOF-SHOT:` lines.

- [ ] **Step 4: Commit** - `feat(shopping): dashboard widget with add/toggle and live sync`

---

## Self-Review

- Distinct feature module on the module API: data/tenancy/visibility (T1), CRUD/gating (T2), realtime (T3), widget + live sync (T4). Dogfood `shopping` end to end.
- Prove-it: T1-T3 Pest TDD; T4 live Playwright + screenshots.
- Deferred: quantity editing UI, categories, per-store lists.
