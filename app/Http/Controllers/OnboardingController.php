<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TrackLastSeen;
use App\Models\Family;
use App\Support\Analytics;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    // Once a user belongs to a family, onboarding is done: bounce them to the app instead of
    // letting them create a second family through the first-run flow.
    // `?preview=1` on a GET shows the screen anyway (the viewport checks run as the demo family, and it's how
    // to look at a step again). Only the page renders: every POST still goes through this guard.
    private function redirectIfOnboarded(Request $request): ?RedirectResponse
    {
        if ($request->isMethod('GET') && $request->boolean('preview')) {
            return null;
        }

        return $request->user()->families()->exists()
            ? to_route('dashboard')
            : null;
    }

    // "About you": the first step for every new account (RegisteredUserController::registered), and where
    // RequireName sends anyone still without a name. Language and colours save as they're picked, through the
    // same endpoints as Settings (so the page switches language / skin in place); the form saves the name.
    public function about(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('onboarding/about-you', [
            'name' => $user->name,
            'locales' => Translations::options(),
            'currentLocale' => app()->getLocale(),
            'themes' => ThemeTokens::available(),
            'currentTheme' => $user->theme,
            'themeCss' => ThemeTokens::css(ThemeTokens::resolve($user, CurrentFamily::id())),
        ]);
    }

    public function saveAbout(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        // The language they're looking at is theirs from now on, picked or not (it came from the browser).
        $user->update(['name' => trim($data['name']), 'locale' => $user->locale ?? app()->getLocale()]);

        // Arrived from an invitation: finish joining that family instead of creating one.
        if ($token = $request->session()->get('invite_token')) {
            return to_route('invite.show', $token);
        }

        // Back to wherever sign-in was heading (e.g. connecting Claude), else on through onboarding.
        return redirect()->intended(route($user->families()->exists() ? 'dashboard' : 'onboarding.index', absolute: false));
    }

    // Welcome: what the next four steps are and the no-card promise (design-app.pen "Onboarding · Flow", 00).
    public function index(Request $request): RedirectResponse|Response
    {
        return $this->redirectIfOnboarded($request)
            ?? Inertia::render('onboarding/welcome', [
                'firstName' => str($request->user()->name)->before(' ')->toString(),
            ]);
    }

    // Step 1, "What should we call your family?": name (with suggestions), colour + symbol, region & units.
    public function create(Request $request): RedirectResponse|Response
    {
        return $this->redirectIfOnboarded($request)
            ?? Inertia::render('onboarding/create-family', [
                'userName' => (string) $request->user()->name,
                'colors' => Family::ONBOARDING_COLORS,
                'region' => $this->region($request),
            ]);
    }

    /**
     * The "Region & units" row: the country Cloudflare saw this request come from, plus whatever the modules
     * derive from it through the `onboarding.region` filter (Spending: currency; Vehicles: km or miles). Core
     * doesn't know which modules care, same as `family.created`; whatever they add here is what they read
     * back from the create request in their `family.created` listener.
     *
     * @return array<string, mixed>
     */
    private function region(Request $request): array
    {
        $country = TrackLastSeen::country($request);
        $name = $country ? \Locale::getDisplayRegion('-'.$country, app()->getLocale()) : null;

        return app(Hooks::class)->filter('onboarding.region', ['country' => $country, 'countryName' => $name ?: null], $country);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfOnboarded($request)) {
            return $redirect;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // Constrained to the palettes the pickers offer (the onboarding one, and the older settings one),
            // so a crafted request can't store arbitrary values that would later render as raw text in the UI.
            'color' => ['required', 'string', 'in:'.implode(',', [...Family::ONBOARDING_COLORS, ...Family::COLORS])],
            // The new onboarding picks a symbol; the emoji stays accepted (and defaults in the column) so a
            // family always has the older mark to fall back on.
            'symbol' => ['nullable', 'string', Family::SYMBOL_RULE],
            'emoji' => ['nullable', 'string', 'in:'.implode(',', Family::EMOJIS)],
        ]);

        $user = $request->user();

        $family = Family::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'],
            'emoji' => $data['emoji'] ?? '🏡',
            'symbol' => $data['symbol'] ?? null,
            'owner_id' => $user->id,
        ]);
        $family->members()->attach($user, ['role' => 'admin']);
        app(ModuleRegistry::class)->enableAll($family->id);
        // Modules set up their per-family defaults here (e.g. Spending's currency, Vehicles' units: from where the
        // person signs up, or what they changed in the "Region & units" row), with the request for context.
        // Core doesn't know which modules care.
        app(Hooks::class)->fire('family.created', $family, $request);
        Analytics::track($user, 'family_created', [], '/onboarding');
        // Onboarding funnel, browser side (docs/analytics.md): one `onboarding_<step>` per finished step.
        Analytics::queue('onboarding_family', ['symbol' => $family->symbol ?? 'emoji']);

        // Make it the active tenant immediately (SetActiveFamily reads this on the next request).
        $request->session()->put('active_family_id', $family->id);

        return to_route('dashboard');
    }
}
