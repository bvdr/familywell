<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

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
            // Web Push public (VAPID) key, so the client can subscribe the browser to push. Public by design.
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            // i18n: the current locale + the picker options + the full translation map for that locale
            // (English-filled). Lazy so it resolves after SetLocale has applied the user's preference.
            'locale' => fn () => app()->getLocale(),
            'locales' => \App\Support\Translations::options(),
            'translations' => fn () => \App\Support\Translations::for(app()->getLocale()),
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
                    ->map(fn ($n) => \Modules\Notifications\NotificationsController::present($n))->values()->all(),
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

        return \App\Models\Family::find($familyId)
            ?->members()->orderBy('users.id')->pluck('users.id')
            ->values()->flip()->all() ?? [];
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
