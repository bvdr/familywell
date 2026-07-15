# Familywell Module System (backend) Implementation Plan (P2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A WordPress/HA-style backend module system: modules are folders with a manifest that register routes, hook listeners, and their own data; the core discovers them, runs their migrations at install (DDL), and gates access per family via an enable flag (no DDL). Dogfooded by a first-party `hello` module.

**Architecture:** Modules live in `modules/<id>/` (PSR-4 `Modules\\`). A `ModuleManifest` reads `module.json`. `ModuleRegistry` tracks installed modules (global, one `modules` row per module = its migrations ran) and enablement (per-family `family_modules` rows, a flip, never DDL). A core provider registers every discovered module's service provider at boot so routes are static (Octane-safe); an `EnsureModuleEnabled` middleware returns 404 when the module is not enabled for the active family. A `Hooks` bus (listen/fire/filter) is the actions/filters API. `modules:migrate` runs module migrations and validates that a module only creates tables inside its `<id>_` namespace.

**Tech Stack:** Same as P1 (Laravel 12, Pest). Executed via the `fwdev` Docker container.

## Global Constraints

- Inherits all P1 Global Constraints (one image, stateless, `family_id` scoping, Context tenancy, no design values hardcoded, no em-dash, sqlite in-memory tests).
- **Installed != enabled (spec 12.2):** module migrations (DDL) run only via `modules:migrate` (deploy/install). `enable`/`disable` for a family only flip a `family_modules` row and MUST never run DDL.
- **Namespace (spec 12.3):** a module `<id>` owns the prefix `<id>_` for tables and `/m/<id>` for routes and `<id>.` for hooks. Validated at migrate time.
- Module ids in P2 are single tokens (e.g. `hello`); a `vendor/name` id is just a longer token and flattens the same way. Full vendor-registry naming is P7.

---

### Task 1: Module autoload, manifest reader, and the hello skeleton

**Files:**
- Modify: `composer.json` (add `Modules\\` PSR-4 autoload)
- Create: `app/Support/Modules/ModuleManifest.php`
- Create: `modules/hello/module.json`
- Test: `tests/Feature/Modules/ModuleManifestTest.php`

**Interfaces:**
- Produces: `ModuleManifest` with `id, name, version, provider, requires[], path`; `::fromPath(string $dir): self`; `migrationsPath(): string`; `tablePrefix(): string` (returns `"{id}_"`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Modules/ModuleManifestTest.php
use App\Support\Modules\ModuleManifest;

it('reads a module manifest from disk', function () {
    $m = ModuleManifest::fromPath(base_path('modules/hello'));

    expect($m->id)->toBe('hello')
        ->and($m->version)->toBe('1.0.0')
        ->and($m->provider)->toBe('Modules\\Hello\\HelloServiceProvider')
        ->and($m->tablePrefix())->toBe('hello_');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=ModuleManifestTest`
Expected: FAIL (class not found / file missing).

- [ ] **Step 3: Create the hello manifest**

```json
// modules/hello/module.json
{
    "id": "hello",
    "name": "Hello",
    "version": "1.0.0",
    "provider": "Modules\\Hello\\HelloServiceProvider",
    "requires": []
}
```

- [ ] **Step 4: Write ModuleManifest**

```php
<?php
// app/Support/Modules/ModuleManifest.php
namespace App\Support\Modules;

class ModuleManifest
{
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $provider,
        public array $requires,
        public string $path,
    ) {}

    public static function fromPath(string $dir): self
    {
        $data = json_decode(file_get_contents($dir.'/module.json'), true);

        return new self(
            id: $data['id'],
            name: $data['name'] ?? $data['id'],
            version: $data['version'],
            provider: $data['provider'],
            requires: $data['requires'] ?? [],
            path: $dir,
        );
    }

    public function migrationsPath(): string
    {
        return $this->path.'/database/migrations';
    }

    public function tablePrefix(): string
    {
        return $this->id.'_';
    }
}
```

- [ ] **Step 5: Add PSR-4 autoload for modules and dump**

Edit `composer.json` `autoload.psr-4` to add `"Modules\\": "modules/"`, then:

Run: `docker exec fwdev composer dump-autoload`

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=ModuleManifestTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add composer.json app/Support/Modules/ModuleManifest.php modules/hello/module.json tests/Feature/Modules/ModuleManifestTest.php
git commit -m "feat(modules): manifest reader, PSR-4 autoload, hello skeleton"
```

---

### Task 2: Registry schema and ModuleRegistry (install + enable per family)

**Files:**
- Create: `database/migrations/2026_07_15_000001_create_module_tables.php`
- Create: `app/Support/Modules/ModuleRegistry.php`
- Test: `tests/Feature/Modules/ModuleRegistryTest.php`

**Interfaces:**
- Produces `ModuleRegistry`:
  - `discover(): array` (id => ModuleManifest, scans `modules/*/module.json`)
  - `manifest(string $id): ?ModuleManifest`
  - `isInstalled(string $id): bool`
  - `markInstalled(string $id, string $version): void`
  - `isEnabled(string $id, int $familyId): bool`
  - `enable(string $id, int $familyId): void` (throws if not installed; only flips a row)
  - `disable(string $id, int $familyId): void` (only flips a row)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Modules/ModuleRegistryTest.php
use App\Support\Modules\ModuleRegistry;
use App\Models\Family;

beforeEach(fn () => $this->registry = app(ModuleRegistry::class));

it('discovers the hello module', function () {
    expect($this->registry->discover())->toHaveKey('hello');
});

it('cannot enable a module that is not installed', function () {
    $family = Family::factory()->create();

    expect(fn () => $this->registry->enable('hello', $family->id))
        ->toThrow(RuntimeException::class);
});

it('installs, then enables and disables per family without touching schema', function () {
    $family = Family::factory()->create();

    $this->registry->markInstalled('hello', '1.0.0');
    expect($this->registry->isInstalled('hello'))->toBeTrue()
        ->and($this->registry->isEnabled('hello', $family->id))->toBeFalse();

    $this->registry->enable('hello', $family->id);
    expect($this->registry->isEnabled('hello', $family->id))->toBeTrue();

    $this->registry->disable('hello', $family->id);
    expect($this->registry->isEnabled('hello', $family->id))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=ModuleRegistryTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_07_15_000001_create_module_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Global: a row here means the module's migrations have run (installed).
        Schema::create('modules', function (Blueprint $table) {
            $table->string('module_id')->primary();
            $table->string('version');
        });

        // Per-family enablement. A flip here is never DDL (spec 12.2).
        Schema::create('family_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('module_id');
            $table->boolean('enabled')->default(true);
            $table->unique(['family_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_modules');
        Schema::dropIfExists('modules');
    }
};
```

- [ ] **Step 4: Write ModuleRegistry**

```php
<?php
// app/Support/Modules/ModuleRegistry.php
namespace App\Support\Modules;

use Illuminate\Support\Facades\DB;

class ModuleRegistry
{
    public function __construct(protected string $modulesPath) {}

    public function discover(): array
    {
        $out = [];
        foreach (glob($this->modulesPath.'/*/module.json') as $file) {
            $manifest = ModuleManifest::fromPath(dirname($file));
            $out[$manifest->id] = $manifest;
        }

        return $out;
    }

    public function manifest(string $id): ?ModuleManifest
    {
        return $this->discover()[$id] ?? null;
    }

    public function isInstalled(string $id): bool
    {
        return DB::table('modules')->where('module_id', $id)->exists();
    }

    public function markInstalled(string $id, string $version): void
    {
        DB::table('modules')->updateOrInsert(['module_id' => $id], ['version' => $version]);
    }

    public function isEnabled(string $id, int $familyId): bool
    {
        return DB::table('family_modules')
            ->where(['family_id' => $familyId, 'module_id' => $id, 'enabled' => true])
            ->exists();
    }

    public function enable(string $id, int $familyId): void
    {
        if (! $this->isInstalled($id)) {
            throw new \RuntimeException("Module [{$id}] is not installed.");
        }
        // Only a row flip. Never DDL (spec 12.2).
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => true],
        );
    }

    public function disable(string $id, int $familyId): void
    {
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => false],
        );
    }
}
```

- [ ] **Step 5: Bind ModuleRegistry with the modules path**

In `app/Providers/AppServiceProvider.php` `register()`:

```php
$this->app->singleton(\App\Support\Modules\ModuleRegistry::class, function () {
    return new \App\Support\Modules\ModuleRegistry(base_path('modules'));
});
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=ModuleRegistryTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_15_000001_create_module_tables.php app/Support/Modules/ModuleRegistry.php app/Providers/AppServiceProvider.php tests/Feature/Modules/ModuleRegistryTest.php
git commit -m "feat(modules): registry schema and install/enable-per-family"
```

---

### Task 3: The hook bus

**Files:**
- Create: `app/Support/Modules/Hooks.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind singleton)
- Test: `tests/Feature/Modules/HooksTest.php`

**Interfaces:**
- Produces `Hooks`: `listen(string $hook, callable $cb): void`, `fire(string $hook, mixed ...$args): void`, `filter(string $hook, mixed $value, mixed ...$args): mixed` (chains each listener's return value).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Modules/HooksTest.php
use App\Support\Modules\Hooks;

it('fires actions to all listeners', function () {
    $hooks = new Hooks();
    $seen = [];
    $hooks->listen('hello.pinged', function ($who) use (&$seen) { $seen[] = $who; });

    $hooks->fire('hello.pinged', 'ada');

    expect($seen)->toBe(['ada']);
});

it('chains filters through each listener', function () {
    $hooks = new Hooks();
    $hooks->listen('hello.greeting', fn ($v) => $v.' there');
    $hooks->listen('hello.greeting', fn ($v) => $v.'!');

    expect($hooks->filter('hello.greeting', 'Hello'))->toBe('Hello there!');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=HooksTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write Hooks**

```php
<?php
// app/Support/Modules/Hooks.php
namespace App\Support\Modules;

// The actions/filters bus (WordPress-style). Modules register listeners in their service provider.
class Hooks
{
    /** @var array<string, list<callable>> */
    protected array $listeners = [];

    public function listen(string $hook, callable $cb): void
    {
        $this->listeners[$hook][] = $cb;
    }

    public function fire(string $hook, mixed ...$args): void
    {
        foreach ($this->listeners[$hook] ?? [] as $cb) {
            $cb(...$args);
        }
    }

    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->listeners[$hook] ?? [] as $cb) {
            $value = $cb($value, ...$args);
        }

        return $value;
    }
}
```

- [ ] **Step 4: Bind Hooks as a singleton**

In `app/Providers/AppServiceProvider.php` `register()`:

```php
$this->app->singleton(\App\Support\Modules\Hooks::class);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=HooksTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Support/Modules/Hooks.php app/Providers/AppServiceProvider.php tests/Feature/Modules/HooksTest.php
git commit -m "feat(modules): hook bus (actions and filters)"
```

---

### Task 4: Module provider boot and per-family enable gating

**Files:**
- Create: `app/Providers/ModuleServiceProvider.php`
- Modify: `bootstrap/providers.php` (register it)
- Create: `app/Http/Middleware/EnsureModuleEnabled.php`
- Modify: `bootstrap/app.php` (alias `module`)
- Create: `modules/hello/HelloServiceProvider.php`
- Test: `tests/Feature/Modules/ModuleGatingTest.php`

**Interfaces:**
- Produces: every discovered module's service provider is registered at boot (routes static, Octane-safe). `EnsureModuleEnabled` (alias `module`) aborts 404 unless the module is enabled for `CurrentFamily::id()`. `HelloServiceProvider` registers `GET /m/hello` (name `hello.index`) behind `module:hello`, which returns `{"message": <filtered greeting>}` and fires `hello.pinged`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Modules/ModuleGatingTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

function familyUser(): array {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    return [$user, $family];
}

it('404s a module route when the module is not enabled', function () {
    [$user] = familyUser();
    app(ModuleRegistry::class)->markInstalled('hello', '1.0.0');

    $this->actingAs($user)->get('/m/hello')->assertNotFound();
});

it('serves a module route when enabled for the active family', function () {
    [$user, $family] = familyUser();
    $registry = app(ModuleRegistry::class);
    $registry->markInstalled('hello', '1.0.0');
    $registry->enable('hello', $family->id);

    $this->actingAs($user)->get('/m/hello')
        ->assertOk()
        ->assertJson(['message' => 'Hello']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=ModuleGatingTest`
Expected: FAIL (route not found for both, or class missing).

- [ ] **Step 3: Write the EnsureModuleEnabled middleware**

```php
<?php
// app/Http/Middleware/EnsureModuleEnabled.php
namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;

class EnsureModuleEnabled
{
    public function __construct(protected ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $moduleId)
    {
        abort_unless(
            CurrentFamily::id() && $this->registry->isEnabled($moduleId, (int) CurrentFamily::id()),
            404,
        );

        return $next($request);
    }
}
```

- [ ] **Step 4: Alias the middleware**

In `bootstrap/app.php` inside `withMiddleware(function (Middleware $middleware) { ... })`, add:

```php
$middleware->alias([
    'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
]);
```

- [ ] **Step 5: Write the core ModuleServiceProvider**

```php
<?php
// app/Providers/ModuleServiceProvider.php
namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        // Register every discovered module's provider so its routes/hooks load once, at boot.
        // Routes are static (Octane-safe); per-family enablement is enforced by the `module` middleware.
        foreach ($registry->discover() as $manifest) {
            if (class_exists($manifest->provider)) {
                $this->app->register($manifest->provider);
            }
        }
    }
}
```

- [ ] **Step 6: Register the core provider**

Add to `bootstrap/providers.php`:

```php
App\Providers\ModuleServiceProvider::class,
```

- [ ] **Step 7: Write the hello module provider**

```php
<?php
// modules/hello/HelloServiceProvider.php
namespace Modules\Hello;

use App\Support\Modules\Hooks;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HelloServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        Route::middleware(['web', 'auth', 'module:hello'])->group(function () use ($hooks) {
            Route::get('/m/hello', function () use ($hooks) {
                $hooks->fire('hello.pinged');

                return response()->json([
                    'message' => $hooks->filter('hello.greeting', 'Hello'),
                ]);
            })->name('hello.index');
        });
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=ModuleGatingTest`
Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Providers/ModuleServiceProvider.php app/Http/Middleware/EnsureModuleEnabled.php bootstrap/app.php bootstrap/providers.php modules/hello/HelloServiceProvider.php tests/Feature/Modules/ModuleGatingTest.php
git commit -m "feat(modules): boot module providers and gate routes per family"
```

---

### Task 5: Per-module migration runner, namespace validation, hello data, end-to-end

**Files:**
- Create: `app/Support/Modules/ModuleNamespace.php`
- Create: `app/Console/Commands/ModulesMigrate.php`
- Create: `modules/hello/database/migrations/2026_07_15_100001_create_hello_pings_table.php`
- Modify: `modules/hello/HelloServiceProvider.php` (listener records a ping)
- Test: `tests/Feature/Modules/ModuleNamespaceTest.php`, `tests/Feature/Modules/ModulesMigrateTest.php`

**Interfaces:**
- Produces: `ModuleNamespace::assertTable(string $moduleId, string $table): void` (throws if `$table` not prefixed `"{id}_"`) and `ModuleNamespace::tablesInMigration(string $file): array`. `modules:migrate` runs each discovered module's migrations (validating namespace first) and marks it installed. Hello gains a `hello_pings` table and its `hello.pinged` listener inserts a family-scoped row.

- [ ] **Step 1: Write the namespace test**

```php
<?php
// tests/Feature/Modules/ModuleNamespaceTest.php
use App\Support\Modules\ModuleNamespace;

it('allows a table inside the module namespace', function () {
    ModuleNamespace::assertTable('hello', 'hello_pings');
})->throwsNoExceptions();

it('rejects a table outside the module namespace', function () {
    expect(fn () => ModuleNamespace::assertTable('hello', 'users'))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=ModuleNamespaceTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write ModuleNamespace**

```php
<?php
// app/Support/Modules/ModuleNamespace.php
namespace App\Support\Modules;

class ModuleNamespace
{
    public static function assertTable(string $moduleId, string $table): void
    {
        $prefix = $moduleId.'_';
        if (! str_starts_with($table, $prefix)) {
            throw new \RuntimeException(
                "Module [{$moduleId}] may not touch table [{$table}] outside its namespace [{$prefix}]."
            );
        }
    }

    /** @return list<string> table names created in a migration file */
    public static function tablesInMigration(string $file): array
    {
        $src = file_get_contents($file);
        preg_match_all('/Schema::create\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $src, $m);

        return $m[1] ?? [];
    }
}
```

- [ ] **Step 4: Run the namespace test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=ModuleNamespaceTest`
Expected: PASS.

- [ ] **Step 5: Write the hello migration**

```php
<?php
// modules/hello/database/migrations/2026_07_15_100001_create_hello_pings_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hello_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hello_pings');
    }
};
```

- [ ] **Step 6: Write the modules:migrate command**

```php
<?php
// app/Console/Commands/ModulesMigrate.php
namespace App\Console\Commands;

use App\Support\Modules\ModuleNamespace;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class ModulesMigrate extends Command
{
    protected $signature = 'modules:migrate';

    protected $description = 'Run migrations for all discovered modules and mark them installed';

    public function handle(ModuleRegistry $registry): int
    {
        foreach ($registry->discover() as $manifest) {
            $path = $manifest->migrationsPath();
            if (is_dir($path)) {
                // Namespace validation before any DDL (spec 12.3).
                foreach (glob($path.'/*.php') as $file) {
                    foreach (ModuleNamespace::tablesInMigration($file) as $table) {
                        ModuleNamespace::assertTable($manifest->id, $table);
                    }
                }
                $this->call('migrate', [
                    '--path' => str_replace(base_path().'/', '', $path),
                    '--force' => true,
                ]);
            }
            $registry->markInstalled($manifest->id, $manifest->version);
            $this->info("Installed {$manifest->id} {$manifest->version}");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Make the hello listener record a family-scoped ping**

Replace `modules/hello/HelloServiceProvider.php` `boot()` body so it also registers a listener:

```php
    public function boot(Hooks $hooks): void
    {
        // Backend logic reacting to a hook: record a family-scoped ping (uses the P1 tenant scope).
        $hooks->listen('hello.pinged', function () {
            \Illuminate\Support\Facades\DB::table('hello_pings')->insert([
                'family_id' => \App\Support\Tenancy\CurrentFamily::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Route::middleware(['web', 'auth', 'module:hello'])->group(function () use ($hooks) {
            Route::get('/m/hello', function () use ($hooks) {
                $hooks->fire('hello.pinged');

                return response()->json([
                    'message' => $hooks->filter('hello.greeting', 'Hello'),
                ]);
            })->name('hello.index');
        });
    }
```

- [ ] **Step 8: Write the end-to-end migrate + ping test**

```php
<?php
// tests/Feature/Modules/ModulesMigrateTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

it('migrates modules, installs them, and records a family-scoped ping end to end', function () {
    $this->artisan('modules:migrate')->assertExitCode(0);

    $registry = app(ModuleRegistry::class);
    expect($registry->isInstalled('hello'))->toBeTrue();

    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $registry->enable('hello', $family->id);

    $this->actingAs($user)->get('/m/hello')->assertOk();

    expect(DB::table('hello_pings')->where('family_id', $family->id)->count())->toBe(1);
});
```

- [ ] **Step 9: Run the migrate/e2e test**

Run: `docker exec fwdev php artisan test --filter=ModulesMigrateTest`
Expected: PASS.

Note: the migration lives under `modules/`, which is outside the default migrate paths, so it does not run during `RefreshDatabase`. The `modules:migrate` call inside the test creates `hello_pings` within the test transaction.

- [ ] **Step 10: Full suite green**

Run: `docker exec fwdev php artisan test`
Expected: all P1 + P2 tests PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Support/Modules/ModuleNamespace.php app/Console/Commands/ModulesMigrate.php modules/hello tests/Feature/Modules/ModuleNamespaceTest.php tests/Feature/Modules/ModulesMigrateTest.php
git commit -m "feat(modules): migration runner, namespace validation, hello dogfood e2e"
```

---

## Self-Review

- Module discovery + manifest (spec 4) -> Tasks 1, 2. Backend logic/routes/hooks (spec 4) -> Tasks 3, 4. Own data/migrations (spec 4) -> Task 5. Install != enable (spec 12.2) -> Tasks 2, 5. Namespace validation (spec 12.3) -> Task 5. First-party dogfood -> `hello` across Tasks 1-5.
- No placeholders: every step has complete code.
- Type consistency: `ModuleManifest`, `ModuleRegistry` (discover/isInstalled/markInstalled/isEnabled/enable/disable), `Hooks` (listen/fire/filter), `EnsureModuleEnabled` alias `module`, `ModuleNamespace::assertTable/tablesInMigration`, command `modules:migrate` are used consistently.
