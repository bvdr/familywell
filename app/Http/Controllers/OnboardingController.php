<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TrackLastSeen;
use App\Models\Family;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
    // Back from step 2 lands here again with the family already made: the form then edits it (`family` set).
    public function create(Request $request): RedirectResponse|Response
    {
        $family = $this->onboardingFamily($request);
        if (! $family && ($redirect = $this->redirectIfOnboarded($request))) {
            return $redirect;
        }

        return Inertia::render('onboarding/create-family', [
            'userName' => (string) $request->user()->name,
            'colors' => Family::ONBOARDING_COLORS,
            'region' => $this->region($request),
            'family' => $family?->only(['name', 'color', 'symbol']),
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
        $editing = $this->onboardingFamily($request);
        if (! $editing && ($redirect = $this->redirectIfOnboarded($request))) {
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

        // Came back to step 1 from step 2: rename / restyle the family made a moment ago, then carry on.
        if ($editing) {
            $editing->update(['name' => $data['name'], 'color' => $data['color'], 'symbol' => $data['symbol'] ?? null]);

            return $this->advance($user, 'invite');
        }

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

        return $this->advance($user, 'invite');
    }

    // Step 2, "Who shares your home?": email invites with a role each, or the family's share link.
    public function invite(Request $request): RedirectResponse|Response
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }

        return Inertia::render('onboarding/invite', [
            'link' => FamilyInviteController::link($family, $request->user()),
            'invited' => $family->invitations()->whereNotNull('email')->get()
                ->map(fn ($i) => ['email' => $i->email, 'role' => $i->role === 'admin' ? 'adult' : 'teen'])->values(),
        ]);
    }

    public function sendInvites(Request $request): RedirectResponse
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }

        // Adult = admin (can manage the family, like the person inviting), Teen = member. The design speaks in
        // household terms; the app's two roles stay the source of truth. Empty list = "I'll invite them later".
        $data = $request->validate([
            'invites' => ['array', 'max:20'],
            'invites.*.email' => ['required', 'email', 'max:255', 'distinct'],
            'invites.*.role' => ['required', 'in:adult,teen'],
        ]);
        $invites = collect($data['invites'] ?? [])
            ->reject(fn ($i) => $family->members()->where('email', $i['email'])->exists());

        foreach ($invites as $i) {
            FamilyInviteController::send($family, $i['email'], $i['role'] === 'adult' ? 'admin' : 'member', $request->user());
        }
        Analytics::queue('onboarding_invites', ['count' => $invites->count()]);

        return $this->advance($request->user(), self::FLOW['invite']);
    }

    // The modules a family picks from in step 3 (design order). Add-ons that extend one of these (important /
    // recurring to-dos, expense import) and Notifications aren't choices: they stay on.
    private const PICKABLE = ['shopping', 'tasks', 'calendar', 'spending', 'notes', 'recipes', 'inventory', 'vehicles', 'projects', 'contacts', 'birthdays'];

    // Suggested when nothing's been picked yet: the four the design starts ticked.
    private const SUGGESTED = ['shopping', 'tasks', 'calendar', 'spending'];

    // Step 3, "Pick 4 to start": a small start sticks. Nothing is locked, the rest turn on in Settings > Modules.
    public function modules(Request $request): RedirectResponse|Response
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $registry = app(ModuleRegistry::class);
        $available = array_values(array_filter(self::PICKABLE, fn ($id) => $registry->isInstalled($id)));
        $enabled = array_values(array_intersect($available, $registry->enabledIds($family->id)));

        return Inertia::render('onboarding/modules', [
            'available' => $available,
            // A new family has every module on (enableAll), which isn't a pick: start from the suggestion then.
            // Back from a later step, it's what they picked.
            'picked' => count($enabled) <= 4 ? $enabled : array_values(array_intersect(self::SUGGESTED, $available)),
            'requestsUrl' => config('familywell.requests_url') ?: null,
        ]);
    }

    public function saveModules(Request $request): RedirectResponse
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $registry = app(ModuleRegistry::class);
        $available = array_values(array_filter(self::PICKABLE, fn ($id) => $registry->isInstalled($id)));
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1', 'max:4'],
            'modules.*' => ['string', 'distinct', 'in:'.implode(',', $available)],
        ]);

        foreach ($available as $id) {
            in_array($id, $data['modules'], true) ? $registry->enable($id, $family->id) : $registry->disable($id, $family->id);
        }
        Analytics::queue('onboarding_modules', ['modules' => implode(',', $data['modules'])]);

        return $this->advance($request->user(), self::FLOW['modules']);
    }

    /**
     * The steps after the family exists, each pointing at the next (null = done, into the app). A step's GET
     * route is `onboarding.<step>`; `users.onboarding_step` remembers where someone is, so RequireFamily can
     * bring them back to it.
     */
    private const FLOW = ['invite' => 'modules', 'modules' => null];

    // Not (or no longer) in an onboarding they can do, e.g. they switched to a family they don't run: stop
    // remembering a step, or RequireFamily would send them straight back here in a loop.
    private function leave(Request $request): RedirectResponse
    {
        if (! $request->boolean('preview')) {
            $request->user()->forceFill(['onboarding_step' => null])->save();
        }

        return to_route('dashboard');
    }

    private function advance(User $user, ?string $step): RedirectResponse
    {
        $user->forceFill(['onboarding_step' => $step])->save();

        return $step ? to_route("onboarding.{$step}") : to_route('dashboard');
    }

    /**
     * The family whose onboarding this person is in the middle of: the active one, when they're mid-onboarding
     * and an admin of it (an invited member never set it up, so never gets these steps). `?preview=1` on a GET
     * shows the steps for the active family without changing anything.
     */
    private function onboardingFamily(Request $request): ?Family
    {
        $user = $request->user();
        if ($user->onboarding_step === null && ! ($request->isMethod('GET') && $request->boolean('preview'))) {
            return null;
        }
        $family = Family::find(CurrentFamily::id());

        return $family && Gate::forUser($user)->allows('manageMembers', $family) ? $family : null;
    }
}
