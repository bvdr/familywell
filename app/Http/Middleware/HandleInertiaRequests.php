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
        ]);
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
