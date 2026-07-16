# Familywell Marketplace / Module Management Implementation Plan (P7)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Prove-it rule: logic steps are TDD (FAIL then PASS with real output); viewable steps capture a real Playwright screenshot into `~/familywell-proof/` and print `PROOF-SHOT: <path>`. Always wrap potentially-blocking shell commands in `timeout`.

**Goal:** A family admin can browse the installed (vetted) modules and enable/disable them for their family from a UI, replacing the CLI/tinker enable path. This is the in-app marketplace of installed modules.

**Architecture:** A `family/modules` Inertia page lists every installed module (from `ModuleRegistry::discover` filtered by `isInstalled`) with its per-family enabled state. Admins toggle enable/disable through gated endpoints backed by `ModuleRegistry::enable`/`disable` (a per-family row flip, never DDL, spec 12.2). Only installed modules appear, which is the cloud vetting gate (spec 9). Non-admins may view but not manage.

**Tech Stack:** P1-P5 stack. Executed via `fwdev`; live proof via Playwright.

## Global Constraints

- Inherits all prior constraints. Enable/disable is a registry row flip only, never DDL. Only installed modules are listed.
- Admin-only management via the existing `FamilyPolicy` admin check; members may view.

## Deferred (honest)

- External module registry + downloading/installing new module code (this P7 manages already-installed, vetted modules). Marketplace of remote modules is a later phase.

---

### Task 1: Backend, list + enable/disable, admin-gated

**Files:**
- Create: `app/Http/Controllers/FamilyModuleController.php`
- Modify: `app/Policies/FamilyPolicy.php` (add `manageModules`)
- Modify: `routes/web.php`
- Test: `tests/Feature/Modules/ModuleManagementTest.php`

**Interfaces:**
- `GET /family/modules` (name `family.modules.index`): Inertia `family/modules` with `modules: [{id,name,version,enabled}]`, `canManage: bool`.
- `POST /family/modules/{module}/enable` (`family.modules.enable`): admin only, 404 if not installed.
- `DELETE /family/modules/{module}` (`family.modules.disable`): admin only.

- [ ] **Step 1: Failing test**

```php
<?php
// tests/Feature/Modules/ModuleManagementTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Inertia\Testing\AssertableInertia as Assert;

function moduleAdmin(): array {
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    return [$admin, $family];
}

it('lists installed modules with enabled state', function () {
    [$admin] = moduleAdmin();
    $this->actingAs($admin)->get('/family/modules')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('family/modules')
            ->where('canManage', true)
            ->has('modules'));
});

it('lets an admin enable and disable a module', function () {
    [$admin, $family] = moduleAdmin();
    $registry = app(ModuleRegistry::class);

    $this->actingAs($admin)->post('/family/modules/todos/enable')->assertRedirect();
    expect($registry->isEnabled('todos', $family->id))->toBeTrue();

    $this->actingAs($admin)->delete('/family/modules/todos')->assertRedirect();
    expect($registry->isEnabled('todos', $family->id))->toBeFalse();
});

it('forbids a non-admin from managing modules', function () {
    [, $family] = moduleAdmin();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)->post('/family/modules/todos/enable')->assertForbidden();
});

it('404s enabling a module that is not installed', function () {
    [$admin] = moduleAdmin();
    $this->actingAs($admin)->post('/family/modules/nope/enable')->assertNotFound();
});
```

- [ ] **Step 2: Run, expect FAIL** - `timeout 60 docker exec fwdev php artisan test --filter=ModuleManagementTest`

- [ ] **Step 3: Policy ability**

Add to `app/Policies/FamilyPolicy.php`:

```php
public function manageModules(User $user, Family $family): bool
{
    return $this->manageMembers($user, $family);
}
```

- [ ] **Step 4: Controller**

```php
<?php
// app/Http/Controllers/FamilyModuleController.php
namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class FamilyModuleController extends Controller
{
    public function index(ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());

        $modules = collect($registry->discover())
            ->filter(fn ($m) => $registry->isInstalled($m->id))
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'version' => $m->version,
                'enabled' => $registry->isEnabled($m->id, $family->id),
            ])->values();

        return Inertia::render('family/modules', [
            'modules' => $modules,
            'canManage' => Gate::allows('manageModules', $family),
        ]);
    }

    public function enable(string $module, ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageModules', $family);
        abort_unless($registry->isInstalled($module), 404);
        $registry->enable($module, $family->id);

        return back();
    }

    public function disable(string $module, ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageModules', $family);
        $registry->disable($module, $family->id);

        return back();
    }
}
```

- [ ] **Step 5: Routes** (auth group, `routes/web.php`)

```php
use App\Http\Controllers\FamilyModuleController;

Route::get('/family/modules', [FamilyModuleController::class, 'index'])->name('family.modules.index');
Route::post('/family/modules/{module}/enable', [FamilyModuleController::class, 'enable'])->name('family.modules.enable');
Route::delete('/family/modules/{module}', [FamilyModuleController::class, 'disable'])->name('family.modules.disable');
```

- [ ] **Step 6: Run, expect PASS. Commit** - `feat(marketplace): admin module enable/disable backend`

---

### Task 2: Modules management page + nav, live proof

**Files:**
- Create: `resources/js/pages/family/modules.tsx`
- Modify: `resources/js/components/app-sidebar.tsx` (nav link)
- Test: covered by Task 1 Inertia assertion + Playwright.

- [ ] **Step 1: Page**

```tsx
// resources/js/pages/family/modules.tsx
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

type Module = { id: string; name: string; version: string; enabled: boolean };
type Props = { modules: Module[]; canManage: boolean };

export default function Modules({ modules, canManage }: Props) {
    const toggle = (m: Module) => {
        if (m.enabled) router.delete(`/family/modules/${m.id}`, { preserveScroll: true });
        else router.post(`/family/modules/${m.id}/enable`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Modules" />
            <div className="p-6">
                <h1 className="mb-4 text-xl font-semibold">Modules</h1>
                <ul className="divide-y" data-testid="modules-list">
                    {modules.map((m) => (
                        <li key={m.id} className="flex items-center justify-between py-3" data-testid={`module-${m.id}`}>
                            <span>
                                {m.name} <span className="text-muted-foreground text-sm">v{m.version}</span>
                                <span className="ml-2 text-xs uppercase" data-testid={`state-${m.id}`}>
                                    {m.enabled ? 'enabled' : 'disabled'}
                                </span>
                            </span>
                            {canManage && (
                                <button
                                    onClick={() => toggle(m)}
                                    data-testid={`toggle-${m.id}`}
                                    className="bg-primary text-primary-foreground rounded px-3 py-1 text-sm"
                                >
                                    {m.enabled ? 'Disable' : 'Enable'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 2: Nav link** in `resources/js/components/app-sidebar.tsx` (add a `Modules` item with a lucide icon, e.g. `Blocks`).

- [ ] **Step 3: Build assets** (`timeout 150 npm run build`), then prettier/eslint (`timeout 60 npm run format`; `timeout 120 npm run lint`).

- [ ] **Step 4: Rebuild image + boot + migrate.**

- [ ] **Step 5: Playwright proof:** register an admin, go to `/family/modules`, screenshot the list (todos/hello disabled) -> `p7-modules-list.png`. Click Enable on todos, assert `state-todos` reads `enabled`, screenshot -> `p7-modules-enabled.png`. Go to `/dashboard`, assert the todos widget is present (enable took effect end to end), screenshot -> `p7-dashboard-after-enable.png`. Print `RESULT` + `PROOF-SHOT:` lines.

- [ ] **Step 6: Commit** - `feat(marketplace): module management page and nav`

---

## Self-Review

- Browse installed/vetted modules + per-family enable/disable via UI, admin-gated (spec 9, 12.2) -> Tasks 1-2. Row-flip only, never DDL.
- Prove-it: Task 1 Pest TDD (list, enable/disable, non-admin 403, not-installed 404); Task 2 live Playwright (enable via UI -> widget appears on dashboard) + screenshots.
- Deferred: remote registry + downloading new module code.
