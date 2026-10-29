<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\Settings\ConnectedAppsController;
use App\Http\Controllers\Settings\MediaLibraryController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RecentlyDeletedController;
use App\Http\Controllers\Settings\SettingsHubController;
use App\Http\Controllers\Settings\SignInController;
use App\Models\Family;
use App\Support\Dashboard\HomeLayout;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use App\Support\Translations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Notifications\NotificationCategories;

// Stripe webhook: no auth/session user; verified by its signature. CSRF-exempt in bootstrap/app.php.
Route::post('stripe/webhook', [BillingController::class, 'webhook'])->name('billing.webhook');

Route::middleware('auth')->group(function () {
    // The avatar opens Me (design board 12): only the person's own settings, then a row into the family's.
    Route::get('settings', [SettingsHubController::class, 'me'])->name('settings');

    // Media library needs the active family (to list its uploads), so it also runs the family middleware.
    Route::middleware('family')->group(function () {
        // Family settings (design board 11): the family-wide pages, opened from Me.
        Route::get('settings/family', [SettingsHubController::class, 'family'])->name('settings.family');
        Route::get('settings/media', [MediaLibraryController::class, 'index'])->name('media');
        Route::delete('settings/media/{type}/{id}', [MediaLibraryController::class, 'destroy'])->name('media.destroy');

        // The family's 30-day trash (App\Support\Trash): list + restore, per module that registers a source.
        // Billing (hosted plan only; 404s without Stripe config). NOT behind the paywall itself: this is
        // where a locked family is sent to subscribe. See RequireFamily.
        Route::get('settings/billing', [BillingController::class, 'show'])->name('billing.show');
        Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])->middleware('not-demo')->name('billing.checkout');
        Route::post('settings/billing/portal', [BillingController::class, 'portal'])->middleware('not-demo')->name('billing.portal');

        // Home dashboard (design M6b): each person orders and hides their own Home's sections (HomeLayout).
        Route::get('settings/home', fn (Request $request) => Inertia::render('settings/home', [
            'sections' => HomeLayout::for($request->user(), CurrentFamily::id()),
            'family' => Family::find(CurrentFamily::id())?->name,
        ]))->name('settings.home');
        Route::patch('settings/home', function (Request $request) {
            // Any known section id, even one whose module is off right now: it keeps its place for when it's back.
            // An empty order = the default order ("Reset to default order" keeps what's hidden).
            $ids = array_keys(HomeLayout::SECTIONS);
            $data = $request->validate([
                'order' => ['present', 'array'],
                'order.*' => ['string', 'distinct', Rule::in($ids)],
                'hidden' => ['present', 'array'],
                'hidden.*' => ['string', 'distinct', Rule::in($ids)],
            ]);
            $request->user()->forceFill(['home_layout' => ['order' => array_values($data['order']), 'hidden' => array_values($data['hidden'])]])->save();

            return back();
        })->name('settings.home.update');

        Route::get('settings/recently-deleted', [RecentlyDeletedController::class, 'index'])->name('recently-deleted');
        Route::post('settings/recently-deleted/{module}/{type}/{id}', [RecentlyDeletedController::class, 'restore'])
            ->whereNumber('id')->name('recently-deleted.restore');
    });

    // AI assistants / MCP clients connected through OAuth (see routes/mcp.php), and revoking them.
    Route::get('settings/connected-apps', [ConnectedAppsController::class, 'index'])->name('connected-apps');
    Route::delete('settings/connected-apps/{token}', [ConnectedAppsController::class, 'destroy'])->middleware('not-demo')->name('connected-apps.destroy');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->middleware('not-demo:email')->name('profile.update');
    // Confirming a new email address with the code sent to it (ProfileController::update sends it).
    Route::post('settings/profile/email', [ProfileController::class, 'confirmEmail'])->middleware(['not-demo:code', 'throttle:20,1'])->name('profile.email.confirm');
    Route::delete('settings/profile/email', [ProfileController::class, 'cancelEmail'])->name('profile.email.cancel');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->middleware('not-demo:email')->name('profile.destroy');

    Route::get('settings/sign-in', [SignInController::class, 'edit'])->name('sign-in.edit');
    Route::delete('settings/social-accounts/{id}', [SignInController::class, 'disconnectSocial'])->middleware('not-demo')
        ->whereNumber('id')->name('social.disconnect');

    Route::get('settings/appearance', function () {
        $familyId = CurrentFamily::id();

        return Inertia::render('settings/appearance', [
            'themes' => ThemeTokens::available(),
            'currentTheme' => auth()->user()->theme, // null => inherit the family default
            'familyDefault' => ($familyId ? Family::find($familyId)?->theme : null) ?: 'minimal-mono',
            // The skin CSS this person sees right now, so picking a skin applies in place (useSkinCss).
            'themeCss' => ThemeTokens::css(ThemeTokens::resolve(auth()->user(), $familyId)),
        ]);
    })->name('appearance');
    // Personal skin. null/absent => inherit the family default. Light/dark is separate (client-side).
    Route::patch('settings/appearance/theme', function (Request $request) {
        $ids = collect(ThemeTokens::available())->pluck('id')->all();
        $data = $request->validate([
            'theme' => ['nullable', Rule::in($ids)],
        ]);
        auth()->user()->update(['theme' => $data['theme'] ?? null]);

        return back();
    })->name('appearance.theme');

    // Language: pick the app locale (per user). Options come from the Translations registry.
    Route::get('settings/language', function () {
        return Inertia::render('settings/language', [
            'locales' => Translations::options(),
            'current' => auth()->user()->locale ?? config('app.locale'),
        ]);
    })->name('language');
    Route::patch('settings/language', function (Request $request) {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Translations::LOCALES))],
        ]);
        $request->user()->update(['locale' => $data['locale']]);

        return back();
    })->name('language.update');

    Route::get('settings/notifications', function (ModuleRegistry $registry) {
        // Categories come from the notifications registry (each module registers its own); merge in
        // the user's saved on/off, defaulting missing ones to on. A category named after a module only shows
        // while the family has that module on (design M6: "only modules your family uses"); the rest
        // ("Shared with me") always show. A hidden one keeps its saved value for when the module comes back.
        $saved = auth()->user()->notification_prefs ?? [];
        $modules = array_map(fn ($m) => $m->id, $registry->discover());
        $enabled = ($familyId = CurrentFamily::id()) ? $registry->enabledIds($familyId) : [];
        $categories = collect(NotificationCategories::all())
            ->filter(fn ($meta, $id) => ! in_array($id, $modules, true) || in_array($id, $enabled, true))
            ->map(fn ($meta, $id) => [
                'id' => $id,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'on' => (bool) ($saved[$id] ?? true),
            ])->values();

        return Inertia::render('settings/notifications', ['categories' => $categories]);
    })->name('notifications');
});
