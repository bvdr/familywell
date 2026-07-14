# Familywell Core Foundation Implementation Plan (P1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Familywell core: a Dockerized Laravel + Inertia + React app with authentication, a family/member domain, per-family roles, and `family_id` tenant scoping, on top of which every future module hooks.

**Architecture:** Start from the official Laravel React starter kit (gives Inertia 2 + React 19 + TypeScript + shadcn/ui + Tailwind 4 + auth scaffolding). Add three domain concepts - `Family`, family membership (a `family_user` pivot carrying a `role`), and an "active family" context resolved per request. A `BelongsToFamily` trait applies a global Eloquent scope so tenant-owned rows are automatically filtered to the active family. This is the tenancy primitive the whole app relies on.

**Tech Stack:** PHP 8.3, Laravel 12, Inertia 2, React 19 + TypeScript, shadcn/ui, Tailwind 4, Pest 3 (tests), PostgreSQL (target) / SQLite (test), Docker.

## Global Constraints

- **One Docker image** runs identically self-hosted and in cloud (spec §2). No host-specific code paths in the app.
- **Stateless app** - all state in Postgres + object storage; nothing on local disk that must persist (spec §3, §6).
- **Every tenant-owned row is scoped by `family_id`**, enforced by a global scope so a query cannot cross families (spec §6, §10).
- **Active family lives in Laravel `Context`**, never in a raw static. Context propagates to queued jobs automatically and is request-scoped, so tenant context is correct in background jobs and does not leak between requests under Octane.
- **Octane/FrankenPHP keeps the app in memory between requests.** Never hold request or tenant state in static properties or long-lived singletons that are not reset per request. Use `Context` or scoped container bindings.
- **Namespacing:** core tables use no vendor prefix; module tables will later be `vendor_module_*`. Core must not squat on names a module would want (spec §12.3).
- **Secrets never committed** - config via `.env`; `.env` is gitignored (spec §10, user rule).
- **No em-dash characters anywhere** - code, comments, commits (user rule).
- Test DB is SQLite in-memory; production/dev DB is Postgres. Migrations must run on both.

---

### Task 1: Scaffold the app and boot it in Docker

**Files:**
- Create: whole Laravel React starter kit tree (via installer)
- Create: `Dockerfile`
- Create: `docker-compose.yml`
- Create: `.dockerignore`
- Test: `tests/Feature/SmokeTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: a booting Laravel app with default auth routes (`/login`, `/register`, `/dashboard`), a `User` model, and a working `php artisan test` harness using Pest.

- [ ] **Step 1: Scaffold the starter kit**

```bash
# From an empty repo working dir. The React starter kit bundles Inertia+React+TS+shadcn+Tailwind+auth.
composer create-project laravel/laravel:^12.0 familywell-tmp
# Move generated files into the repo root (repo already has docs/ and .git)
rsync -a --exclude='.git' familywell-tmp/ ./ && rm -rf familywell-tmp
php artisan key:generate
composer require laravel/breeze --dev
php artisan breeze:install react --typescript --pest
composer require laravel/octane
php artisan octane:install --server=frankenphp
npm install
```

Expected: `resources/js/` contains React/TSX pages, `tests/` uses Pest, `routes/auth.php` exists.

- [ ] **Step 2: Write the failing smoke test**

```php
<?php
// tests/Feature/SmokeTest.php
use function Pest\Laravel\get;

it('serves the welcome page', function () {
    get('/')->assertOk();
});

it('serves the login page', function () {
    get('/login')->assertOk();
});
```

- [ ] **Step 3: Run it to verify it passes on the default scaffold**

Run: `php artisan test --filter=SmokeTest`
Expected: PASS (the starter kit ships these routes). If FAIL, the scaffold is incomplete - fix before proceeding.

- [ ] **Step 4: Write the Dockerfile**

```dockerfile
# Dockerfile
# Single image: FrankenPHP (runs Octane) + built frontend assets. Same image self-host and cloud (Global Constraint).
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.3 AS app
RUN install-php-extensions pdo_pgsql pdo_sqlite intl bcmath opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction
# One production-grade image: FrankenPHP serves Octane (multi-threaded), no nginx+php-fpm to orchestrate.
# Octane keeps the app in memory between requests, so tenant state must never live in a raw static (see Task 3).
EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
```

- [ ] **Step 5: Write docker-compose for local dev (Postgres alongside)**

```yaml
# docker-compose.yml
services:
  app:
    build: .
    ports: ["8000:8000"]
    env_file: .env
    depends_on: [db]
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: familywell
      POSTGRES_USER: familywell
      POSTGRES_PASSWORD: familywell
    ports: ["5432:5432"]
    volumes: ["dbdata:/var/lib/postgresql/data"]
volumes:
  dbdata:
```

- [ ] **Step 6: Add `.dockerignore`**

```
# .dockerignore
node_modules
vendor
.git
.env
public/build
docs
```

- [ ] **Step 7: Build the image to confirm it compiles**

Run: `docker build -t familywell:dev .`
Expected: build completes without error.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: scaffold Laravel React starter kit with Docker"
```

---

### Task 2: Family model, migration, and factory

**Files:**
- Create: `app/Models/Family.php`
- Create: `database/migrations/2026_07_14_000001_create_families_table.php`
- Create: `database/factories/FamilyFactory.php`
- Test: `tests/Feature/FamilyModelTest.php`

**Interfaces:**
- Consumes: `User` model (from Task 1).
- Produces: `Family` model with `name`, `owner_id`; relationship `Family::members(): BelongsToMany` and `User::families(): BelongsToMany` via `family_user` pivot carrying `role`. `FamilyFactory` for tests.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/FamilyModelTest.php
use App\Models\Family;
use App\Models\User;

it('creates a family owned by a user', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);

    expect($family->name)->not->toBeEmpty()
        ->and($family->owner_id)->toBe($user->id);
});

it('relates members through the pivot with a role', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    expect($family->members)->toHaveCount(1)
        ->and($family->members->first()->pivot->role)->toBe('member');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=FamilyModelTest`
Expected: FAIL with "Class App\Models\Family not found".

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_07_14_000001_create_families_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('family_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // 'admin' | 'member' - per-family role (spec §7)
            $table->timestamps();
            $table->unique(['family_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_user');
        Schema::dropIfExists('families');
    }
};
```

- [ ] **Step 4: Write the Family model**

```php
<?php
// app/Models/Family.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Family extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'owner_id'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
```

- [ ] **Step 5: Add the inverse relation on User**

```php
// app/Models/User.php - add this method to the existing class
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function families(): BelongsToMany
{
    return $this->belongsToMany(Family::class)
        ->withPivot('role')
        ->withTimestamps();
}
```

- [ ] **Step 6: Write the factory**

```php
<?php
// database/factories/FamilyFactory.php
namespace Database\Factories;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->lastName() . ' Family',
            'owner_id' => User::factory(),
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=FamilyModelTest`
Expected: PASS (both tests).

- [ ] **Step 8: Commit**

```bash
git add app/Models/Family.php app/Models/User.php database/migrations/2026_07_14_000001_create_families_table.php database/factories/FamilyFactory.php tests/Feature/FamilyModelTest.php
git commit -m "feat: add Family model, membership pivot, and factory"
```

---

### Task 3: Active-family context and the BelongsToFamily global scope

**Files:**
- Create: `app/Support/Tenancy/CurrentFamily.php`
- Create: `app/Support/Tenancy/BelongsToFamily.php`
- Create: `app/Http/Middleware/SetActiveFamily.php`
- Modify: `bootstrap/app.php` (register middleware)
- Test: `tests/Feature/TenancyScopeTest.php`

**Interfaces:**
- Consumes: `Family` model (Task 2), authenticated `User`.
- Produces:
  - `CurrentFamily::id(): ?int`, `CurrentFamily::set(int $familyId): void`, `CurrentFamily::forget(): void` - active-family accessor backed by Laravel `Context` (propagates to queued jobs automatically; request-scoped and Octane-safe).
  - `BelongsToFamily` trait - any model using it gets a global scope filtering `where family_id = CurrentFamily::id()`, and auto-fills `family_id` on create. Models using it MUST have a `family_id` column.
  - `SetActiveFamily` middleware - resolves the active family from the session (`active_family_id`), falling back to the user's first family, and calls `CurrentFamily::set()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/TenancyScopeTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

// A throwaway tenant-owned model for the test.
beforeEach(function () {
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('family_id');
        $table->string('body');
        $table->timestamps();
    });
});

it('hides rows from other families and auto-fills family_id', function () {
    $famA = Family::factory()->create();
    $famB = Family::factory()->create();

    $model = new class extends Model {
        protected $table = 'notes';
        protected $guarded = [];
        use \App\Support\Tenancy\BelongsToFamily;
    };

    CurrentFamily::set($famA->id);
    $model->newInstance(['body' => 'A-note'])->save();

    CurrentFamily::set($famB->id);
    $model->newInstance(['body' => 'B-note'])->save();

    // Scoped to B now: only B-note visible, and it was auto-stamped with famB.
    expect($model->newQuery()->pluck('body')->all())->toBe(['B-note']);
    expect($model->newQuery()->first()->family_id)->toBe($famB->id);

    CurrentFamily::set($famA->id);
    expect($model->newQuery()->pluck('body')->all())->toBe(['A-note']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=TenancyScopeTest`
Expected: FAIL with "Class App\Support\Tenancy\CurrentFamily not found".

- [ ] **Step 3: Write CurrentFamily**

```php
<?php
// app/Support/Tenancy/CurrentFamily.php
namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Context;

// Active-family accessor backed by Laravel Context. Context is request-scoped (Octane-safe: no static
// leaks between requests) AND is automatically serialized into queued jobs and restored in the worker,
// so CurrentFamily::id() resolves correctly inside background jobs with zero per-job wiring.
class CurrentFamily
{
    public static function set(int $familyId): void { Context::add('family_id', $familyId); }
    public static function id(): ?int { return Context::get('family_id'); }
    public static function forget(): void { Context::forget('family_id'); }
}
```

- [ ] **Step 4: Write the BelongsToFamily trait**

```php
<?php
// app/Support/Tenancy/BelongsToFamily.php
namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Any tenant-owned model uses this. Requires a `family_id` column.
trait BelongsToFamily
{
    protected static function bootBelongsToFamily(): void
    {
        static::addGlobalScope('family', function (Builder $builder) {
            if ($id = CurrentFamily::id()) {
                $builder->where($builder->getModel()->getTable() . '.family_id', $id);
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->family_id) && $id = CurrentFamily::id()) {
                $model->family_id = $id;
            }
        });
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=TenancyScopeTest`
Expected: PASS.

- [ ] **Step 6: Write the SetActiveFamily middleware**

```php
<?php
// app/Http/Middleware/SetActiveFamily.php
namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;

class SetActiveFamily
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $familyId = $user
            ? ($request->session()->get('active_family_id') ?? $user->families()->first()?->id)
            : null;

        if ($familyId) {
            CurrentFamily::set($familyId);
            $request->session()->put('active_family_id', $familyId);
        } else {
            CurrentFamily::forget(); // never inherit a prior request's context under Octane
        }

        return $next($request);
    }
}
```

- [ ] **Step 7: Register the middleware on the web group**

```php
// bootstrap/app.php - inside ->withMiddleware(function (Middleware $middleware) { ... })
$middleware->web(append: [
    \App\Http\Middleware\SetActiveFamily::class,
]);
```

- [ ] **Step 8: Write a feature test proving the middleware sets context**

```php
<?php
// tests/Feature/ActiveFamilyMiddlewareTest.php
use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('sets the active family for an authenticated request', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    actingAs($user);
    get('/dashboard')->assertOk();

    expect(CurrentFamily::id())->toBe($family->id);
});
```

- [ ] **Step 9: Run it to verify it passes**

Run: `php artisan test --filter=ActiveFamilyMiddlewareTest`
Expected: PASS.

- [ ] **Step 10: Write the Context-seam test (proves the family rides along to jobs)**

We do not test Laravel's guarantee that Context propagates to queued jobs (that is the framework's job). We test the seam we own: that `set()` writes the active family into `Context`, which is what Laravel then serializes into every queued job.

```php
<?php
// tests/Feature/TenantContextTest.php
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Context;

it('stores the active family in Context so it propagates to queued jobs', function () {
    $family = Family::factory()->create();

    CurrentFamily::set($family->id);

    // Laravel captures the current Context into queued job payloads and restores it in the worker,
    // so CurrentFamily::id() resolves inside jobs without any per-job wiring.
    expect(Context::get('family_id'))->toBe($family->id)
        ->and(CurrentFamily::id())->toBe($family->id);

    CurrentFamily::forget();
    expect(CurrentFamily::id())->toBeNull();
});
```

Run: `php artisan test --filter=TenantContextTest`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Support/Tenancy tests/Feature/TenancyScopeTest.php tests/Feature/ActiveFamilyMiddlewareTest.php tests/Feature/TenantContextTest.php app/Http/Middleware/SetActiveFamily.php bootstrap/app.php
git commit -m "feat: add active-family context and BelongsToFamily global scope"
```

---

### Task 4: Registration creates a family and an admin membership

**Files:**
- Modify: `app/Http/Controllers/Auth/RegisteredUserController.php` (the Breeze register controller)
- Test: `tests/Feature/RegistrationCreatesFamilyTest.php`

**Interfaces:**
- Consumes: `Family` (Task 2), the Breeze registration flow (Task 1).
- Produces: after registration, the new user owns a `Family` named "<name>'s Family" and is attached with `role = 'admin'`, and `active_family_id` is set in session.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/RegistrationCreatesFamilyTest.php
use App\Models\User;
use function Pest\Laravel\post;

it('creates a family with the registrant as admin', function () {
    post('/register', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'ada@example.com')->first();

    expect($user->families)->toHaveCount(1)
        ->and($user->families->first()->owner_id)->toBe($user->id)
        ->and($user->families->first()->pivot->role)->toBe('admin');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=RegistrationCreatesFamilyTest`
Expected: FAIL (`toHaveCount(1)` gets 0).

- [ ] **Step 3: Add family creation to the register controller**

```php
// app/Http/Controllers/Auth/RegisteredUserController.php
// Inside store(), after the User::create(...) call and before event(new Registered($user)):
$family = \App\Models\Family::create([
    'name' => $request->name . "'s Family",
    'owner_id' => $user->id,
]);
$family->members()->attach($user, ['role' => 'admin']);
$request->session()->put('active_family_id', $family->id);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=RegistrationCreatesFamilyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Auth/RegisteredUserController.php tests/Feature/RegistrationCreatesFamilyTest.php
git commit -m "feat: create a family and admin membership on registration"
```

---

### Task 5: Invite members and manage roles (admin only)

**Files:**
- Create: `app/Http/Controllers/FamilyMemberController.php`
- Create: `app/Policies/FamilyPolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/FamilyMemberTest.php`

**Interfaces:**
- Consumes: `Family`, membership pivot, active-family context, `User`.
- Produces:
  - `POST /family/members` - admin attaches an existing user (by email) to the active family with a role. 403 for non-admins.
  - `DELETE /family/members/{user}` - admin removes a member.
  - `FamilyPolicy::manageMembers(User $user, Family $family): bool` - true only if the user's pivot role in that family is `admin`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/FamilyMemberTest.php
use App\Models\Family;
use App\Models\User;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;

function familyWithAdmin(): array {
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    return [$admin, $family];
}

it('lets an admin add a member by email', function () {
    [$admin, $family] = familyWithAdmin();
    $invitee = User::factory()->create(['email' => 'kid@example.com']);

    actingAs($admin)->post('/family/members', [
        'email' => 'kid@example.com', 'role' => 'member',
    ])->assertRedirect();

    expect($family->fresh()->members)->toHaveCount(2);
});

it('forbids a non-admin from adding members', function () {
    [$admin, $family] = familyWithAdmin();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);
    $target = User::factory()->create(['email' => 'x@example.com']);

    actingAs($member)->post('/family/members', [
        'email' => 'x@example.com', 'role' => 'member',
    ])->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=FamilyMemberTest`
Expected: FAIL (route `/family/members` not defined → 404, not the asserted status).

- [ ] **Step 3: Write the policy**

```php
<?php
// app/Policies/FamilyPolicy.php
namespace App\Policies;

use App\Models\Family;
use App\Models\User;

class FamilyPolicy
{
    public function manageMembers(User $user, Family $family): bool
    {
        return $family->members()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php
// app/Http/Controllers/FamilyMemberController.php
namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;

class FamilyMemberController extends Controller
{
    public function store(Request $request)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', 'in:admin,member'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();
        $family->members()->syncWithoutDetaching([
            $user->id => ['role' => $data['role']],
        ]);

        return back();
    }

    public function destroy(User $user)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        $family->members()->detach($user->id);

        return back();
    }
}
```

- [ ] **Step 5: Register routes**

```php
// routes/web.php - inside the auth middleware group
use App\Http\Controllers\FamilyMemberController;

Route::post('/family/members', [FamilyMemberController::class, 'store'])->name('family.members.store');
Route::delete('/family/members/{user}', [FamilyMemberController::class, 'destroy'])->name('family.members.destroy');
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=FamilyMemberTest`
Expected: PASS (both cases).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/FamilyMemberController.php app/Policies/FamilyPolicy.php routes/web.php tests/Feature/FamilyMemberTest.php
git commit -m "feat: admin-only family member management"
```

---

### Task 6: Base UI shell with family switcher and members screen

**Files:**
- Create: `resources/js/pages/family/members.tsx`
- Create: `app/Http/Controllers/FamilyMemberController.php` - add `index()` (modify)
- Modify: `routes/web.php` (add GET route)
- Modify: `resources/js/layouts/app-layout.tsx` (or the starter's main authenticated layout) - add a family switcher + Members nav link
- Test: `tests/Feature/FamilyMembersPageTest.php`

**Interfaces:**
- Consumes: everything above; Inertia render.
- Produces:
  - `GET /family/members` renders an Inertia page `family/members` with props `{ family: {id,name}, members: [{id,name,email,role}], canManage: bool }`.
  - The authenticated layout shows the active family name and a link to Members.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/FamilyMembersPageTest.php
use App\Models\Family;
use App\Models\User;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the members page with props', function () {
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    actingAs($admin)->get('/family/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('family/members')
            ->where('family.name', $family->name)
            ->has('members', 1)
            ->where('canManage', true)
        );
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=FamilyMembersPageTest`
Expected: FAIL (route not defined → 404).

- [ ] **Step 3: Add the index method to the controller**

```php
// app/Http/Controllers/FamilyMemberController.php - add:
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;

public function index()
{
    $family = Family::findOrFail(CurrentFamily::id());

    return Inertia::render('family/members', [
        'family' => ['id' => $family->id, 'name' => $family->name],
        'members' => $family->members->map(fn ($m) => [
            'id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
        ]),
        'canManage' => Gate::allows('manageMembers', $family),
    ]);
}
```

- [ ] **Step 4: Add the GET route**

```php
// routes/web.php - inside the auth group, next to the other family routes
Route::get('/family/members', [FamilyMemberController::class, 'index'])->name('family.members.index');
```

- [ ] **Step 5: Create the React page**

```tsx
// resources/js/pages/family/members.tsx
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

type Member = { id: number; name: string; email: string; role: string };
type Props = { family: { id: number; name: string }; members: Member[]; canManage: boolean };

export default function Members({ family, members, canManage }: Props) {
    return (
        <AppLayout>
            <Head title={`${family.name} - Members`} />
            <div className="p-6">
                <h1 className="text-xl font-semibold mb-4">{family.name}</h1>
                <ul className="divide-y">
                    {members.map((m) => (
                        <li key={m.id} className="flex justify-between py-2">
                            <span>{m.name} <span className="text-muted-foreground">({m.email})</span></span>
                            <span className="text-sm uppercase">{m.role}</span>
                        </li>
                    ))}
                </ul>
                {canManage && <p className="mt-4 text-sm text-muted-foreground">You can manage members.</p>}
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 6: Add the Members nav link and family name to the layout**

```tsx
// resources/js/layouts/app-layout.tsx - add a nav link. Exact file may be named differently in the
// starter (check resources/js/layouts/); add alongside the existing Dashboard link:
import { Link } from '@inertiajs/react';
// ...within the nav element:
<Link href="/family/members" className="text-sm font-medium">Members</Link>
```

- [ ] **Step 7: Run the feature test to verify it passes**

Run: `php artisan test --filter=FamilyMembersPageTest`
Expected: PASS.

- [ ] **Step 8: Build assets to confirm the page compiles**

Run: `npm run build`
Expected: build succeeds, no TypeScript errors on `members.tsx`.

- [ ] **Step 9: Commit**

```bash
git add resources/js/pages/family/members.tsx app/Http/Controllers/FamilyMemberController.php routes/web.php resources/js/layouts tests/Feature/FamilyMembersPageTest.php
git commit -m "feat: family members page and nav shell"
```

---

### Task 7: Full-suite green and image smoke check

**Files:**
- Modify: `phpunit.xml` (confirm SQLite in-memory test DB)
- Test: entire suite

**Interfaces:**
- Consumes: everything.
- Produces: a green suite and a runnable image - the P1 deliverable.

- [ ] **Step 1: Confirm the test DB is SQLite in-memory**

```xml
<!-- phpunit.xml - inside <php> -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

- [ ] **Step 2: Run the entire test suite**

Run: `php artisan test`
Expected: all tests PASS (SmokeTest, FamilyModelTest, TenancyScopeTest, ActiveFamilyMiddlewareTest, TenantContextTest, RegistrationCreatesFamilyTest, FamilyMemberTest, FamilyMembersPageTest).

- [ ] **Step 3: Boot the image against Postgres and hit the health route**

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
curl -fsS http://localhost:8000/login >/dev/null && echo "OK"
docker compose down
```
Expected: prints `OK`.

- [ ] **Step 4: Commit**

```bash
git add phpunit.xml
git commit -m "chore: pin sqlite in-memory test db and verify P1 suite"
```

---

## Self-Review

**Spec coverage (P1 slice only):**
- Core owns auth, families, members, roles, base UI shell (spec §4, §7) → Tasks 2, 4, 5, 6. ✅
- `family_id` tenant scoping via global scope (spec §6, §10) → Task 3. ✅
- Per-family roles admin/member (spec §7) → pivot `role`, Tasks 2, 5. ✅ (deviation from spatie flagged in handoff)
- One Docker image, stateless (spec §2, §3) → Task 1, Task 7 Step 3. ✅
- Module registry / hook bus / marketplace / realtime / first-party modules → **out of P1 scope**, covered by P2-P8. ✅ (intentional)

**Placeholder scan:** No TBDs; every code step has complete code. The layout edit in Task 6 Step 6 notes the file may be named differently in the starter - that is a real instruction (verify the path), not a placeholder, and the surrounding steps are concrete.

**Type consistency:** `CurrentFamily::set/id/forget`, `BelongsToFamily`, `manageMembers`, pivot `role`, session key `active_family_id`, Inertia component `family/members` are used consistently across Tasks 3-7.
