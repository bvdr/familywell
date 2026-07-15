# Familywell Frontend Module Loading Implementation Plan (P3)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox syntax. Prove-it rule: every logic step is TDD (show FAIL then PASS); every viewable step captures a real Playwright screenshot into `~/familywell-proof/`.

**Goal:** Modules contribute frontend UI at runtime. A module ships a pre-built ESM asset that registers React components into named slots in the core shell. The shell loads only the enabled modules' assets for the active family. No Node/Vite build on the host to add a module UI (spec 12.1).

**Architecture:** The shell exposes a global runtime `window.Familywell = { React, registerSlot }` and a `<Slot name>` component backed by a small registry. A module's pre-built JS uses that global to register a widget (no bundler, no second React). The backend shares the list of enabled modules' frontend URLs per request (`window.__familywellModules`) and serves each module's asset behind an enabled-check. The shell dynamically `import()`s those URLs on boot; `<Slot>` re-renders as modules register. Dogfooded by `hello` contributing a `dashboard.widgets` slot.

**Tech Stack:** P1/P2 stack + Playwright (already installed in scratchpad) for viewable proof.

## Global Constraints

- Inherits P1 + P2 constraints.
- **No host build to add a module UI (spec 12.1):** module assets are pre-built plain ESM served as-is; the shell never compiles them.
- **Enabled-gated:** only modules enabled for the active family load their frontend (reuses P2 registry).
- Module frontend uses the shell's React via `window.Familywell.React` (single React instance, no import maps).

---

### Task 1: Backend - frontend manifest, per-family boot list, gated asset serving

**Files:**
- Modify: `app/Support/Modules/ModuleManifest.php` (add `frontend`, `frontendFile()`)
- Create: `app/Http/Controllers/ModuleAssetController.php`
- Modify: `routes/web.php` (asset route)
- Create: `app/Http/Middleware/ShareModules.php`
- Modify: `bootstrap/app.php` (append ShareModules)
- Modify: `resources/views/app.blade.php` (inject `window.__familywellModules`)
- Modify: `modules/hello/module.json` (declare `frontend`)
- Test: `tests/Feature/Modules/ModuleFrontendTest.php`

**Interfaces:**
- `ModuleManifest->frontend: ?string`; `frontendFile(): ?string` (absolute path or null).
- `GET /m/{module}/frontend.js` (name `module.frontend`) serves the module's pre-built asset with `Content-Type: text/javascript`, 404 unless the module is enabled for the active family.
- `ShareModules` shares `moduleFrontends` (list of asset URLs for enabled modules) to the Blade root.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Modules/ModuleFrontendTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

function frontendFamilyUser(): array {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    return [$user, $family];
}

it('404s a module asset when the module is not enabled', function () {
    [$user] = frontendFamilyUser();
    app(ModuleRegistry::class)->markInstalled('hello', '1.0.0');
    $this->actingAs($user)->get('/m/hello/frontend.js')->assertNotFound();
});

it('serves the module asset as javascript when enabled', function () {
    [$user, $family] = frontendFamilyUser();
    $registry = app(ModuleRegistry::class);
    $registry->markInstalled('hello', '1.0.0');
    $registry->enable('hello', $family->id);

    $res = $this->actingAs($user)->get('/m/hello/frontend.js');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('javascript');
    expect($res->getContent())->toContain('registerSlot');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=ModuleFrontendTest`
Expected: FAIL.

- [ ] **Step 3: Add frontend to ModuleManifest**

Add to the constructor promoted properties and `fromPath`:

```php
// constructor: add parameter
public ?string $frontend = null,
```
```php
// in fromPath(): pass
frontend: $data['frontend'] ?? null,
```
```php
// new method
public function frontendFile(): ?string
{
    return $this->frontend ? $this->path.'/'.$this->frontend : null;
}
```

- [ ] **Step 4: Declare hello frontend in the manifest**

Add `"frontend": "resources/dist/hello.js"` to `modules/hello/module.json`.

- [ ] **Step 5: Write the asset controller**

```php
<?php
// app/Http/Controllers/ModuleAssetController.php
namespace App\Http\Controllers;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;

class ModuleAssetController extends Controller
{
    public function frontend(string $module, ModuleRegistry $registry)
    {
        $manifest = $registry->manifest($module);
        abort_unless(
            $manifest
            && CurrentFamily::id()
            && $registry->isEnabled($module, (int) CurrentFamily::id())
            && $manifest->frontendFile()
            && is_file($manifest->frontendFile()),
            404,
        );

        return response(file_get_contents($manifest->frontendFile()), 200, [
            'Content-Type' => 'text/javascript',
        ]);
    }
}
```

- [ ] **Step 6: Register the asset route**

In `routes/web.php` inside the auth group:

```php
use App\Http\Controllers\ModuleAssetController;

Route::get('/m/{module}/frontend.js', [ModuleAssetController::class, 'frontend'])->name('module.frontend');
```

- [ ] **Step 7: Write ShareModules middleware**

```php
<?php
// app/Http/Middleware/ShareModules.php
namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareModules
{
    public function __construct(protected ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next)
    {
        $urls = [];
        if ($familyId = CurrentFamily::id()) {
            foreach ($this->registry->discover() as $manifest) {
                if ($manifest->frontend && $this->registry->isEnabled($manifest->id, (int) $familyId)) {
                    $urls[] = route('module.frontend', ['module' => $manifest->id]);
                }
            }
        }
        View::share('moduleFrontends', $urls);

        return $next($request);
    }
}
```

- [ ] **Step 8: Register the middleware (after SetActiveFamily so the family is resolved)**

In `bootstrap/app.php` web append list, add after `ShareTheme::class`:

```php
\App\Http\Middleware\ShareModules::class,
```

- [ ] **Step 9: Inject the boot list into the document**

In `resources/views/app.blade.php`, before `@routes`:

```blade
<script>window.__familywellModules = @json($moduleFrontends ?? []);</script>
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=ModuleFrontendTest`
Expected: PASS (2 tests). (Requires `modules/hello/resources/dist/hello.js` to exist and contain `registerSlot`; create it now if Task 3 has not run yet, or run Task 3 Step 1 first.)

- [ ] **Step 11: Commit**

```bash
git add app/Support/Modules/ModuleManifest.php app/Http/Controllers/ModuleAssetController.php app/Http/Middleware/ShareModules.php routes/web.php bootstrap/app.php resources/views/app.blade.php modules/hello/module.json tests/Feature/Modules/ModuleFrontendTest.php
git commit -m "feat(modules): serve enabled module frontends and share boot list"
```

---

### Task 2: Frontend slot registry, runtime API, dynamic loader, dashboard slot

**Files:**
- Create: `resources/js/lib/modules.ts`
- Modify: `resources/js/app.tsx`
- Modify: `resources/js/pages/dashboard.tsx`

**Interfaces:**
- `registerSlot(name, Component)`, `<Slot name />` (renders all registered components, re-renders on registration), `loadModules(urls: string[]): Promise<void>`.
- `window.Familywell = { React, registerSlot }` set before modules load.

- [ ] **Step 1: Write the slot registry**

```tsx
// resources/js/lib/modules.ts
import * as React from 'react';

type Comp = React.ComponentType<Record<string, unknown>>;
const EMPTY: Comp[] = [];
const slots = new Map<string, Comp[]>();
const listeners = new Set<() => void>();

export function registerSlot(name: string, comp: Comp): void {
    slots.set(name, [...(slots.get(name) ?? []), comp]);
    listeners.forEach((l) => l());
}

function subscribe(l: () => void): () => void {
    listeners.add(l);
    return () => listeners.delete(l);
}

export function Slot({ name }: { name: string }) {
    const comps = React.useSyncExternalStore(subscribe, () => slots.get(name) ?? EMPTY);
    return React.createElement(React.Fragment, null, comps.map((C, i) => React.createElement(C, { key: i })));
}

export async function loadModules(urls: string[]): Promise<void> {
    for (const url of urls) {
        try {
            await import(/* @vite-ignore */ url);
        } catch (e) {
            console.error('module frontend failed to load', url, e);
        }
    }
}
```

- [ ] **Step 2: Wire the runtime API and loader in app.tsx**

Add near the top of `resources/js/app.tsx` (after React import, before/around `createInertiaApp`):

```tsx
import * as React from 'react';
import { registerSlot, loadModules } from './lib/modules';

// Expose the shell runtime so pre-built module assets can register UI without a bundler or a second React.
(window as unknown as { Familywell: unknown }).Familywell = { React, registerSlot };
loadModules((window as unknown as { __familywellModules?: string[] }).__familywellModules ?? []);
```

- [ ] **Step 3: Render the slot on the dashboard**

In `resources/js/pages/dashboard.tsx`, import `Slot` and render `<Slot name="dashboard.widgets" />` inside the page content.

```tsx
import { Slot } from '@/lib/modules';
// ...inside the returned JSX, in a visible container:
<Slot name="dashboard.widgets" />
```

- [ ] **Step 4: Build assets (proof they compile)**

Run (host): `npm run build`
Expected: build succeeds.

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/modules.ts resources/js/app.tsx resources/js/pages/dashboard.tsx
git commit -m "feat(frontend): slot registry, runtime API, and dynamic module loader"
```

---

### Task 3: Hello frontend bundle + end-to-end screenshot proof

**Files:**
- Create: `modules/hello/resources/dist/hello.js`
- Proof: Playwright screenshots (enabled shows widget, disabled hides it)

**Interfaces:**
- `modules/hello/resources/dist/hello.js` is a pre-built ESM that calls `window.Familywell.registerSlot('dashboard.widgets', HelloWidget)`.

- [ ] **Step 1: Write the hello frontend bundle**

```js
// modules/hello/resources/dist/hello.js
// Pre-built module frontend: uses the shell runtime, no bundler, no second React.
const { React, registerSlot } = window.Familywell;

function HelloWidget() {
    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'hello-widget' },
        React.createElement('h3', { className: 'font-semibold' }, 'Hello module'),
        React.createElement('p', { className: 'text-sm text-muted-foreground' },
            'This widget was loaded at runtime from the hello module.'),
    );
}

registerSlot('dashboard.widgets', HelloWidget);
```

- [ ] **Step 2: Rebuild the image and boot (module assets are served from modules/, not built)**

```bash
docker compose up -d --build
docker compose exec -T app php artisan migrate:fresh --force
docker compose exec -T app php artisan modules:migrate
```

- [ ] **Step 3: Playwright proof - widget hidden when hello disabled, shown when enabled**

Drive: register a user (creates family), screenshot dashboard (no widget) -> `p3-dashboard-module-disabled.png`; enable hello for the family; reload dashboard, assert `[data-testid=hello-widget]` visible, screenshot -> `p3-dashboard-module-enabled.png`. Assert the widget text is absent in the first and present in the second (this is the automated red/green).

- [ ] **Step 4: Print PROOF-SHOT lines and verify the assertion output**

Expected: assertion prints `DISABLED_WIDGET=absent ENABLED_WIDGET=present`, and both PNGs exist.

- [ ] **Step 5: Commit**

```bash
git add modules/hello/resources/dist/hello.js
git commit -m "feat(modules): hello module contributes a runtime-loaded dashboard widget"
```

---

## Self-Review

- Pre-built module asset served without host build (spec 12.1) -> Tasks 1, 3. Slot registry + runtime load -> Task 2. Per-family gating of frontend -> Task 1 (controller + ShareModules). Dogfood -> hello widget across Tasks 1-3.
- Prove-it: Task 1 is Pest TDD; Task 3 is Playwright with an absent/present assertion plus screenshots.
- Deferred (honest): Vite-level per-module code-splitting and full module-contributed Inertia *pages* (spec 12.1 cloud optimization) are follow-on; P3 proves the slot/runtime-load primitive with a widget.
