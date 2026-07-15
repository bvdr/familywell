# Familywell Realtime Foundation Implementation Plan (P4)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Prove-it rule: logic steps are TDD (FAIL then PASS with real output); viewable steps capture a real Playwright screenshot into `~/familywell-proof/` and print `PROOF-SHOT: <path>`.

**Goal:** Live realtime over WebSockets. A private per-family channel (member-only), a broadcastable event fired on family activity, an Echo client wired at runtime, and a Redis backplane for multi-instance scaling. Dogfooded: the `hello` widget shows a live ping counter that increments over the socket when the module route is hit.

**Architecture:** Laravel Reverb is the WebSocket server (own process/service). Broadcasts go on `PrivateChannel('family.{id}')`, authorized in `routes/channels.php` (subscriber must be a member). The `hello.pinged` hook broadcasts a `FamilyPinged` event. The browser configures Echo from **runtime-injected** config (`window.__familywellReverb`, emitted server-side per deployment), not Vite build-time env, so the same image works on any host. Multiple Reverb instances share a Redis backplane (spec 12.4); the client heartbeat sits below the PaaS LB idle timeout.

**Tech Stack:** P1-P3 stack + laravel/reverb, laravel-echo, pusher-js, Redis.

## Global Constraints

- Inherits P1-P3 constraints.
- **Runtime Reverb config, not build-time:** Echo reads `window.__familywellReverb` (server-injected). No `VITE_REVERB_*` baked into the bundle.
- **Private channels are member-gated (spec 5):** only family members may subscribe to `family.{id}`.
- **Multi-instance backplane (spec 12.4):** Reverb scales via Redis pub/sub; client heartbeat < LB idle timeout.

---

### Task 1: Install Reverb/broadcasting and the member-gated family channel

**Files:**
- Modify: `composer.json`/`package.json` (via installers), `config/reverb.php`, `config/broadcasting.php`
- Modify: `routes/channels.php`
- Test: `tests/Feature/Realtime/FamilyChannelTest.php`

**Interfaces:**
- Private channel `family.{familyId}`; authorization returns true only if the authenticated user is a member of that family.

- [ ] **Step 1: Install broadcasting + Reverb**

```bash
docker exec fwdev composer require laravel/reverb --no-interaction
docker exec fwdev php artisan install:broadcasting --no-interaction
npm install            # host: laravel-echo + pusher-js
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// tests/Feature/Realtime/FamilyChannelTest.php
use App\Models\Family;
use App\Models\User;

function channelFamily(): array {
    $owner = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $owner->id]);
    $family->members()->attach($owner, ['role' => 'admin']);
    return [$owner, $family];
}

it('authorizes a family member on the private family channel', function () {
    [$owner, $family] = channelFamily();

    $this->actingAs($owner)->postJson('/broadcasting/auth', [
        'channel_name' => "private-family.{$family->id}",
        'socket_id' => '1234.5678',
    ])->assertOk();
});

it('rejects a non-member on the private family channel', function () {
    [, $family] = channelFamily();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->postJson('/broadcasting/auth', [
        'channel_name' => "private-family.{$family->id}",
        'socket_id' => '1234.5678',
    ])->assertForbidden();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=FamilyChannelTest`
Expected: FAIL (channel not defined -> both unauthorized, so the member test fails).

- [ ] **Step 4: Define the channel authorization**

In `routes/channels.php`:

```php
use App\Models\Family;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('family.{familyId}', function ($user, int $familyId) {
    return Family::where('id', $familyId)
        ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
        ->exists();
});
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=FamilyChannelTest`
Expected: PASS (member 200, stranger 403).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json config routes/channels.php bootstrap tests/Feature/Realtime/FamilyChannelTest.php resources/js
git commit -m "feat(realtime): install Reverb broadcasting and member-gated family channel"
```

---

### Task 2: FamilyPinged broadcast event, fired from the hello hook

**Files:**
- Create: `app/Events/FamilyPinged.php`
- Modify: `modules/hello/HelloServiceProvider.php` (broadcast on `hello.pinged`)
- Test: `tests/Feature/Realtime/FamilyPingedTest.php`

**Interfaces:**
- `FamilyPinged` implements `ShouldBroadcast`; `broadcastOn()` returns `[new PrivateChannel("family.{id}")]`; `broadcastAs()` returns `'FamilyPinged'`.
- Hitting `GET /m/hello` (enabled) dispatches `FamilyPinged` for the active family.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Realtime/FamilyPingedTest.php
use App\Events\FamilyPinged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts on the private family channel', function () {
    $event = new FamilyPinged(7);
    expect($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-family.7')
        ->and($event->broadcastAs())->toBe('FamilyPinged');
});

it('dispatches FamilyPinged when the hello route is hit', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $this->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('hello', $family->id);

    Event::fake([FamilyPinged::class]);
    $this->actingAs($user)->get('/m/hello')->assertOk();
    Event::assertDispatched(FamilyPinged::class, fn ($e) => $e->familyId === $family->id);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker exec fwdev php artisan test --filter=FamilyPingedTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write the event**

```php
<?php
// app/Events/FamilyPinged.php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FamilyPinged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $familyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("family.{$this->familyId}")];
    }

    public function broadcastAs(): string
    {
        return 'FamilyPinged';
    }
}
```

- [ ] **Step 4: Broadcast from the hello hook**

In `modules/hello/HelloServiceProvider.php`, extend the `hello.pinged` listener to also broadcast:

```php
$hooks->listen('hello.pinged', function () {
    \Illuminate\Support\Facades\DB::table('hello_pings')->insert([
        'family_id' => \App\Support\Tenancy\CurrentFamily::id(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \App\Events\FamilyPinged::dispatch((int) \App\Support\Tenancy\CurrentFamily::id());
});
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec fwdev php artisan test --filter=FamilyPingedTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Events/FamilyPinged.php modules/hello/HelloServiceProvider.php tests/Feature/Realtime/FamilyPingedTest.php
git commit -m "feat(realtime): FamilyPinged broadcast fired from the hello hook"
```

---

### Task 3: Runtime Echo client, live widget counter, Reverb + Redis services, live proof

**Files:**
- Create: `resources/js/echo.ts` (runtime-config Echo)
- Modify: `resources/js/app.tsx` (import echo)
- Create: `app/Http/Middleware/ShareRealtime.php` (inject `window.__familywellReverb` + `window.__familywellFamilyId`)
- Modify: `bootstrap/app.php` (append ShareRealtime), `resources/views/app.blade.php` (emit the globals)
- Modify: `modules/hello/resources/dist/hello.js` (subscribe + ping counter)
- Modify: `docker-compose.yml` (reverb + redis services, broadcast env)
- Proof: Playwright live WebSocket screenshot

**Interfaces:**
- `window.__familywellReverb = {key, host, port, scheme}`; `window.__familywellFamilyId`. Echo built at runtime from these. Widget subscribes to `family.{id}` and increments on `.FamilyPinged`.

- [ ] **Step 1: Write the runtime Echo client**

```ts
// resources/js/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
        __familywellReverb?: { key: string; host: string; port: number; scheme: string };
        __familywellFamilyId?: number;
    }
}

window.Pusher = Pusher;
const cfg = window.__familywellReverb;
if (cfg) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: cfg.key,
        wsHost: cfg.host,
        wsPort: cfg.port,
        wssPort: cfg.port,
        forceTLS: cfg.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // Heartbeat below a typical PaaS LB idle timeout (spec 12.4).
        activityTimeout: 30000,
    });
}
```

- [ ] **Step 2: Import echo in app.tsx (before loadModules)**

Add `import './echo';` near the top of `resources/js/app.tsx`, before the `loadModules(...)` call.

- [ ] **Step 3: ShareRealtime middleware**

```php
<?php
// app/Http/Middleware/ShareRealtime.php
namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareRealtime
{
    public function handle(Request $request, Closure $next)
    {
        View::share('reverb', [
            'key' => config('reverb.apps.apps.0.key', env('REVERB_APP_KEY')),
            'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST', 'localhost')),
            'port' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
            'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
        ]);
        View::share('activeFamilyId', CurrentFamily::id());

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware and emit the globals**

`bootstrap/app.php` web append: add `\App\Http\Middleware\ShareRealtime::class`.

`resources/views/app.blade.php`, after the modules script:

```blade
<script>
    window.__familywellReverb = @json($reverb ?? null);
    window.__familywellFamilyId = @json($activeFamilyId ?? null);
</script>
```

- [ ] **Step 5: Live subscription in the hello widget**

Replace `modules/hello/resources/dist/hello.js` with a version that subscribes and counts:

```js
const { React, registerSlot } = window.Familywell;

function HelloWidget() {
    const [pings, setPings] = React.useState(0);
    React.useEffect(() => {
        const fid = window.__familywellFamilyId;
        if (window.Echo && fid) {
            window.Echo.private('family.' + fid).listen('.FamilyPinged', () => setPings((p) => p + 1));
            return () => window.Echo.leave('family.' + fid);
        }
    }, []);
    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'hello-widget' },
        React.createElement('h3', { className: 'font-semibold' }, 'Hello module'),
        React.createElement('p', { className: 'text-sm text-muted-foreground', 'data-testid': 'ping-count' }, 'pings: ' + pings),
    );
}
registerSlot('dashboard.widgets', HelloWidget);
```

- [ ] **Step 6: Add reverb + redis services and broadcast env to docker-compose**

Add to `docker-compose.yml`:

```yaml
  reverb:
    build: .
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports: ["8080:8080"]
    env_file: .env
    environment:
      BROADCAST_CONNECTION: reverb
      REVERB_SCALING_ENABLED: "true"
      REDIS_HOST: redis
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_DATABASE: familywell
      DB_USERNAME: familywell
      DB_PASSWORD: familywell
    depends_on: [redis, db]
  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]
```

And add to the `app` service `environment`: `BROADCAST_CONNECTION: reverb`, `REVERB_SCALING_ENABLED: "true"`, `REDIS_HOST: redis`, `REVERB_HOST: reverb`, `REVERB_PUBLIC_HOST: localhost`, `REVERB_PORT: 8080`. Add `redis` to the app `depends_on`. Ensure `REVERB_APP_ID/KEY/SECRET` exist in `.env` (added by `reverb:install`).

- [ ] **Step 7: Build assets, rebuild image, boot the full stack**

```bash
npm run build
docker compose up -d --build
docker compose exec -T app php artisan migrate:fresh --force
docker compose exec -T app php artisan modules:migrate
```
Verify Reverb is listening:
```bash
curl -s -o /dev/null -w "reverb HTTP %{http_code}\n" http://localhost:8080
```

- [ ] **Step 8: Live Playwright WebSocket proof**

Register a user, enable hello, open the dashboard (widget shows `pings: 0`, socket subscribed). Trigger `page.request.get('/m/hello')` (same session, fires the broadcast server-side). Wait for the widget to read `pings: 1`. Screenshot before -> `p4-realtime-before.png` and after -> `p4-realtime-after.png`. Assert the count went 0 -> 1 (this is the live red/green).

- [ ] **Step 9: Print PROOF-SHOT lines + result**

Expected: `RESULT PINGS_BEFORE=0 PINGS_AFTER=1`, both PNGs exist.

- [ ] **Step 10: Commit**

```bash
git add resources/js/echo.ts resources/js/app.tsx app/Http/Middleware/ShareRealtime.php bootstrap/app.php resources/views/app.blade.php modules/hello/resources/dist/hello.js docker-compose.yml
git commit -m "feat(realtime): runtime Echo client, live widget counter, Reverb+Redis services"
```

---

## Self-Review

- Reverb/broadcasting installed + member-gated channel (spec 5) -> Task 1. Broadcast event on family activity -> Task 2. Echo client + live delivery -> Task 3. Redis backplane + heartbeat (spec 12.4) -> Task 3 (services + activityTimeout). Dogfood -> hello live ping counter.
- Prove-it: Tasks 1-2 are Pest TDD; Task 3 is a live WebSocket Playwright assertion (0 -> 1) plus screenshots.
- Deferred (honest): presence channels, reconnection/backoff tuning, and wiring realtime into a real feature list (P5 Todos) are follow-on. P4 proves the socket path end to end.
