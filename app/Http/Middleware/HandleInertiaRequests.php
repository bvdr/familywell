<?php

namespace App\Http\Middleware;

use App\Support\Analytics;
use App\Support\Auth\SocialLogin;
use App\Support\Billing\Billing;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Middleware;
use Modules\Notifications\NotificationsController;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
        $locale = app()->getLocale();

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            // Modules enabled for the active family, so the sidebar can show links for page-modules
            // (e.g. Spending) only when they are turned on. Lazy closure on purpose: Inertia resolves
            // share() at the START of the middleware chain, before SetActiveFamily sets the family
            // context, so this must defer to render time when CurrentFamily::id() is available.
            'modules' => fn () => ['enabled' => $this->enabledModuleIds()],
            // Module previews this person is opted in to (App\Support\Modules\Preview), for usePreview() in React.
            'preview' => fn () => $request->user()?->preview_modules ?? [],
            // Web Push public (VAPID) key, so the client can subscribe the browser to push. Public by design.
            // Hosted-plan billing on/off (STRIPE_KEY set). Shows Settings > Billing; self-hosted = false.
            'billingEnabled' => Billing::enabled(),
            // "Continue with Google / Apple" buttons: only providers whose keys are configured (none on a
            // self-hosted install without them).
            'socialProviders' => SocialLogin::enabled(),
            // The signed-in person's pseudonymous Umami id (users.analytics_id), which app.tsx passes to
            // umami.identify(). Null without Umami (self-hosted) or when signed out. Never name or email.
            'analyticsId' => fn () => Analytics::enabled() ? $request->user()?->analytics_id : null,
            // Funnel steps queued by Analytics::queue() for app.tsx to send from the browser. Pulled, so each
            // goes out once; a partial reload doesn't evaluate it, leaving them for the next full page.
            'analyticsEvents' => fn () => Analytics::enabled() ? $request->session()->pull('analytics_events', []) : [],
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            // i18n: the current locale + the picker options + the full translation map for that locale
            // (English-filled). SetLocale already ran (see bootstrap/app.php), so both are eager.
            'locale' => $locale,
            'locales' => Translations::options(),
            // Once-prop: the map is ~46KB (97% of a typical Inertia response) and only changes with
            // the locale, so ship it on the first page load and on locale change, never on every
            // visit. Keyed by locale: switching language yields a key the client hasn't got, so the
            // server sends the new map. Needs @inertiajs/react >= 2.2 (once-prop protocol).
            'translations' => Inertia::once(fn () => Translations::for($locale))->as("translations-{$locale}"),
            // Each family member's avatar colour, as userId => palette index assigned by member order,
            // so every member gets a different (and consistent, app-wide) colour. Lazy: needs the
            // family context that SetActiveFamily fills in later in the chain.
            'memberColors' => fn () => $this->memberColors(),
            // Header bell glimpse: unread count + recent items. MUST be shared here (per request), not
            // via Inertia::share in a provider boot(): under Octane boot() runs once per worker and
            // Octane drops the registered share after the first request, so the bell went permanently
            // empty ("all caught up") on every reused worker. The middleware runs every request.
            'notifications' => fn () => $this->notifications($request),
        ]);
    }

    /**
     * Unread count + the recent items for the header bell. Lazy try/catch: a notifications query
     * failure (e.g. table not migrated right after a deploy) must fall back to empty, never blank
     * the whole app by throwing out of share().
     *
     * @return array{unread:int, items:array<int,mixed>}
     */
    private function notifications(Request $request): array
    {
        if (! $user = $request->user()) {
            return ['unread' => 0, 'items' => []];
        }
        try {
            return [
                'unread' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(12)->get()
                    ->map(fn ($n) => NotificationsController::present($n))->values()->all(),
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['unread' => 0, 'items' => []];
        }
    }

    /** @return array<int,int> userId => avatar palette index (assigned by member order) */
    private function memberColors(): array
    {
        if (! $familyId = CurrentFamily::id()) {
            return [];
        }

        // Straight off the pivot: one query instead of Family::find + members (2 per request).
        return DB::table('family_user')->where('family_id', $familyId)
            ->orderBy('user_id')->pluck('user_id')
            ->values()->flip()->all();
    }

    /** @return list<string> */
    private function enabledModuleIds(): array
    {
        if (! $familyId = CurrentFamily::id()) {
            return [];
        }

        return collect(app(ModuleRegistry::class)->discover())
            ->filter(fn ($m) => app(ModuleRegistry::class)->isEnabled($m->id, (int) $familyId))
            ->keys()
            ->values()
            ->all();
    }
}
