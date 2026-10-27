<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TrackLastSeen;
use App\Mail\HandoffLinkMail;
use App\Models\Family;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Auth\Handoff;
use App\Support\Billing\Billing;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Notifications\NotificationCategories;

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
        // Hard paywall: no free period for a family made here (Billing::hasAccess). Not fillable, so forceFill.
        $family->forceFill(['hard_paywall' => true])->save();
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
                ->map(fn ($i) => ['email' => $i->email, 'role' => $i->role === 'teen' ? 'teen' : 'adult'])->values(),
        ]);
    }

    public function sendInvites(Request $request): RedirectResponse
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }

        // Adult = admin (sees and edits everything, like the person inviting), Teen = teen (their lists, tasks and
        // calendar, see Family::ROLES). Empty list = "I'll invite them later".
        $data = $request->validate([
            'invites' => ['array', 'max:20'],
            'invites.*.email' => ['required', 'email', 'max:255', 'distinct'],
            'invites.*.role' => ['required', 'in:adult,teen'],
        ]);
        $invites = collect($data['invites'] ?? [])
            ->reject(fn ($i) => $family->members()->where('email', $i['email'])->exists());

        foreach ($invites as $i) {
            FamilyInviteController::send($family, $i['email'], $i['role'] === 'adult' ? 'admin' : 'teen', $request->user());
        }
        Analytics::queue('onboarding_invites', ['count' => $invites->count()]);

        return $this->advance($request->user(), $this->next('invite'));
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

        return $this->advance($request->user(), $this->next('modules'));
    }

    /**
     * Step 4: on a phone, "Put FamilyWell on your Home Screen" (required: it's the only way reminders reach an
     * iPhone). The page moves on by itself once it runs installed (display-mode: standalone), which is also
     * where RequireFamily brings someone who opens the new Home Screen icon. On a computer it's "Now take it to
     * your phone": a QR code / emailed link that signs the phone in (Handoff), or installing on the computer.
     */
    public function install(Request $request): RedirectResponse|Response
    {
        if (! $this->onboardingFamily($request)) {
            return $this->leave($request);
        }

        return Inertia::render('onboarding/install', [
            'email' => $request->user()->email,
            // Polled by the computer while the phone does this step: once the phone has moved on, so does it.
            'step' => fn () => $request->user()->fresh()->onboarding_step,
            // ?preview=1 shows the screen without following anyone's progress.
            'preview' => $request->boolean('preview'),
        ]);
    }

    public function saveInstall(Request $request): RedirectResponse
    {
        if (! $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        // How they got past it, for the funnel: opened installed, installed on the computer, or the phone's
        // "continue in the browser" escape (shown only after a while, for browsers that can't install).
        $data = $request->validate(['via' => ['required', 'in:standalone,desktop,browser']]);
        Analytics::queue('onboarding_installed', ['via' => $data['via']]);
        // For the next screens: the "Opened from your Home Screen" pill and the recap's install line.
        $request->session()->put('onboarding.installed_via', $data['via']);

        return $this->advance($request->user(), $this->next('install'));
    }

    // A fresh sign-in link for the computer's QR code. JSON, so the page can renew it before it expires.
    public function handoff(Request $request): JsonResponse
    {
        abort_unless($this->onboardingFamily($request), 403);

        return response()->json(['url' => Handoff::create($request->user()), 'ttl' => Handoff::TTL_MINUTES * 60]);
    }

    // "No camera handy? We'll email you a sign-in link." Only ever to the account's own address: a link that
    // signs this account in must not be sendable to someone else's inbox.
    public function emailHandoff(Request $request): RedirectResponse
    {
        if (! $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        Mail::to($request->user()->email)->send(new HandoffLinkMail(Handoff::create($request->user())));

        return back();
    }

    // GET /h/{token}: the phone end of the QR code / emailed link. Signs in, then the app resumes the onboarding.
    public function useHandoff(Request $request, string $token): RedirectResponse
    {
        $user = Handoff::consume($token);
        if (! $user) {
            return to_route('login')->with('status', __('That sign-in link has expired or was already used. Sign in to carry on.'));
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    /**
     * Step 5 (design 05 / D05): what FamilyWell may notify about, with real-looking examples from the modules they
     * picked. The toggles save through Settings' own endpoint (push.prefs) and the browser asks for permission;
     * this only records the answer and moves on.
     */
    public function notifications(Request $request): RedirectResponse|Response
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $picked = app(ModuleRegistry::class)->enabledIds($family->id);
        // A toggle per picked module that has a push category (Calendar has none yet, so it isn't offered).
        $categories = array_values(array_intersect(self::PICKABLE, $picked, NotificationCategories::ids()));

        return Inertia::render('onboarding/notifications', [
            'categories' => $categories,
            'names' => $this->invitedNames($family) ?: [str($request->user()->name)->before(' ')->toString()],
            'installedVia' => $request->session()->get('onboarding.installed_via'),
        ]);
    }

    public function saveNotifications(Request $request): RedirectResponse
    {
        if (! $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $data = $request->validate(['granted' => ['required', 'boolean']]);
        Analytics::queue('onboarding_notifications', ['granted' => $data['granted'] ? 'yes' : 'no']);

        return $this->advance($request->user(), $this->next('notifications'));
    }

    // Step 6, "Welcome to <family>" (design 06 / D06): the recap of what they built, then the tour or straight on.
    public function ready(Request $request): RedirectResponse|Response
    {
        if (! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $user = $request->user();
        $picked = array_values(array_intersect(self::PICKABLE, app(ModuleRegistry::class)->enabledIds($family->id)));
        $pushOn = $user->pushSubscriptions()->exists()
            ? count(array_filter(array_intersect_key($user->notification_prefs ?? [], array_flip($picked)), fn ($on) => $on))
            : 0;

        return Inertia::render('onboarding/ready', [
            'family' => $family->only(['name', 'color', 'symbol', 'emoji']),
            'me' => str($user->name)->before(' ')->toString(),
            'invited' => $this->invitedNames($family),
            'modules' => $picked,
            'installedVia' => $request->session()->get('onboarding.installed_via'),
            'pushOn' => $pushOn,
            // The plan comes after the tour only where there's billing; self-hosted (and dev) goes straight in.
            'planNext' => Billing::enabled(),
        ]);
    }

    // "Show me around" (tour = true) or "Skip the tour". The tour runs on the real dashboard (shared `tour` prop).
    public function saveReady(Request $request): RedirectResponse
    {
        if (! $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $tour = $request->boolean('tour');
        Analytics::queue('onboarding_ready', ['tour' => $tour ? 'yes' : 'no']);
        if ($tour) {
            $request->user()->forceFill(['onboarding_step' => 'tour'])->save();

            return to_route('dashboard');
        }

        return $this->advance($request->user(), $this->next('tour'));
    }

    // End of the tour ("Let's go" or "Skip tour"), from the dashboard.
    public function finishTour(Request $request): RedirectResponse
    {
        if ($request->user()->onboarding_step !== 'tour') {
            return to_route('dashboard');
        }
        Analytics::queue('onboarding_toured');

        return $this->advance($request->user(), $this->next('tour'));
    }

    /**
     * The last stop (design 09 / T09 / D09), after the tour and only where billing is on: one plan, yearly
     * preselected, the total in plain sight, paid right here with Stripe Elements (Apple Pay / Google Pay through the
     * Express Checkout Element, cards through the Payment Element). See subscribe() and paid().
     */
    public function plan(Request $request): RedirectResponse|Response
    {
        if (! Billing::enabled() || ! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }

        return Inertia::render('onboarding/plan', [
            'family' => $family->only(['name']),
            'invited' => $this->invitedNames($family),
            'pricing' => Billing::pricing(),
            // The list price, for the "Intro price -$9" line while the intro offer lasts.
            'standard' => ['monthly' => (int) config('familywell.pricing.standard.monthly'), 'yearly' => (int) config('familywell.pricing.standard.yearly')],
            // Publishable key only (it's meant for the browser); the secret never leaves the server.
            'stripeKey' => config('services.stripe.key'),
            'email' => $request->user()->email,
            'failed' => $request->boolean('failed'),
            'paid' => false,
            'selfHostingUrl' => config('familywell.self_hosting_url') ?: null,
        ]);
    }

    /**
     * Pay: the browser has collected the payment details (elements.submit()); create the incomplete subscription
     * and hand back the secret it confirms with. A plan switch after an abandoned attempt cancels that first one,
     * so a family never piles up incomplete subscriptions (Stripe would expire them after a day anyway).
     */
    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(Billing::enabled() && ($family = $this->onboardingFamily($request)), 403);
        $this->authorize('manageBilling', $family);
        abort_if(Billing::isPaid($family), 409, 'Already subscribed.');
        $plan = $request->validate(['plan' => ['required', 'in:monthly,yearly']])['plan'];

        if ($stale = $request->session()->pull('onboarding.pending_subscription')) {
            Billing::cancelIncomplete($stale);
        }
        $started = Billing::startSubscription($family, $request->user()->email, $plan);
        $request->session()->put('onboarding.pending_subscription', $started['subscription']);
        Analytics::queue('checkout_started', ['plan' => $plan]);

        return response()->json($started);
    }

    // Paid: sync the subscription now (the webhook stays the source of truth), then the win and into the app. Also
    // Stripe's return_url when a card needs a 3-D Secure redirect.
    public function paid(Request $request): RedirectResponse|Response
    {
        if (! Billing::enabled() || ! $family = $this->onboardingFamily($request)) {
            return $this->leave($request);
        }
        $subscription = (string) $request->query('subscription', $request->session()->get('onboarding.pending_subscription', ''));
        if ($subscription !== '') {
            Billing::syncSubscription($family, $subscription);
        }
        if (! Billing::isPaid($family)) {
            return to_route('onboarding.plan', ['failed' => 1]);
        }
        $request->session()->forget('onboarding.pending_subscription');
        Analytics::queue('checkout_completed');
        $request->user()->forceFill(['onboarding_step' => null])->save();

        // The page celebrates for a beat, then goes into the app.
        return Inertia::render('onboarding/plan', ['family' => $family->only(['name']), 'invited' => [], 'pricing' => Billing::pricing(), 'standard' => null, 'stripeKey' => null, 'email' => '', 'failed' => false, 'paid' => true, 'selfHostingUrl' => null]);
    }

    /** First names from the pending invites ("marco.rivera@..." -> "Marco"), for the examples and the recap. */
    private function invitedNames(Family $family): array
    {
        return $family->invitations()->whereNotNull('email')->orderBy('id')->pluck('email')
            ->map(fn ($e) => ucfirst(strtolower((string) preg_split('/[._+\-]/', explode('@', $e)[0])[0])))
            ->filter()->values()->all();
    }

    /**
     * The steps after the family exists, each pointing at the next (null = done, into the app). A step's GET
     * route is `onboarding.<step>` (the tour runs on the dashboard instead); `users.onboarding_step` remembers
     * where someone is, so RequireFamily can bring them back to it.
     */
    private const FLOW = ['invite' => 'modules', 'modules' => 'install', 'install' => 'notifications', 'notifications' => 'ready'];

    private function next(string $step): ?string
    {
        // After the tour comes the plan, only where billing is on (hosted); self-hosted / dev go straight in.
        return $step === 'tour' ? (Billing::enabled() ? 'plan' : null) : self::FLOW[$step];
    }

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
