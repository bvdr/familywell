<?php

use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyInviteController;
use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\FamilyModuleController;
use App\Http\Controllers\GuestLinkController;
use App\Http\Controllers\ModuleAssetController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PublicGuestController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareController;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Dashboard\DashboardData;
use App\Support\Demo\DemoFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Client-side crash reporter: the frontend ErrorBoundary POSTs here so account/device-specific
// render errors (that never reach our test accounts) land in the server logs with the page + stack.
Route::post('/client-error', function (Request $request) {
    Log::warning('client-error', [
        'message' => (string) $request->input('message'),
        'url' => (string) $request->input('url'),
        'stack' => mb_substr((string) $request->input('stack', ''), 0, 2000),
        'storage' => mb_substr((string) $request->input('storage', ''), 0, 2000),
        'standalone' => (bool) $request->input('standalone'),
        'ua' => mb_substr((string) $request->input('ua', ''), 0, 300),
        'user' => optional($request->user())->id,
    ]);

    return response()->noContent();
})->middleware('throttle:60,1')->name('client.error');

Route::get('/', function () {
    // Root goes straight to the app. Unauthenticated visitors get bounced to /login by the
    // dashboard's auth middleware. There is no public marketing page yet, so the old Laravel
    // welcome scaffold served no purpose here.
    return redirect()->route('dashboard');
})->name('home');

// Onboarding: authed but NOT behind RequireFamily (that would loop), since this is where a
// family-less user creates their first family.
// The invite landing is public: it handles guests itself (remember the token, send them to register).
Route::get('invite/{token}', [FamilyInviteController::class, 'show'])->name('invite.show');

// One-click public demo: signs the visitor in as the demo family's owner (no account needed) and
// makes the demo family active. The family is found ONLY by its is_demo flag (DemoFamily::locate);
// 404 until DemoSeeder has run. Anyone already signed in is switched to the demo account. Throttled
// because it is an unauthenticated login endpoint. Its data is wiped hourly by `demo:reset`.
Route::get('demo', function (Request $request) {
    $family = DemoFamily::locate();
    abort_unless($family, 404);

    Auth::login(User::findOrFail($family->owner_id));
    $request->session()->regenerate();
    $request->session()->put('active_family_id', $family->id);
    Analytics::queue('demo_opened');

    // intended(): an app reviewer connecting Claude/ChatGPT signs in with the demo and goes on to consent.
    return redirect()->intended(route('dashboard'));
})->middleware('throttle:30,1')->name('demo.enter');

Route::middleware(['auth'])->group(function () {
    Route::get('onboarding/about', [OnboardingController::class, 'about'])->name('onboarding.about');
    Route::post('onboarding/about', [OnboardingController::class, 'saveAbout'])->name('onboarding.about.store');
    Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::get('onboarding/create', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::get('onboarding/invite', [OnboardingController::class, 'invite'])->name('onboarding.invite');
    // Throttled: each invite sends an email.
    Route::post('onboarding/invite', [OnboardingController::class, 'sendInvites'])->middleware('throttle:10,1')->name('onboarding.invite.store');
    Route::get('onboarding/modules', [OnboardingController::class, 'modules'])->name('onboarding.modules');
    Route::post('onboarding/modules', [OnboardingController::class, 'saveModules'])->name('onboarding.modules.store');
    Route::get('onboarding/install', [OnboardingController::class, 'install'])->name('onboarding.install');
    Route::post('onboarding/install', [OnboardingController::class, 'saveInstall'])->name('onboarding.install.store');
    // Sign-in links for the phone (App\Support\Auth\Handoff): throttled, the email one harder since it sends mail.
    Route::post('onboarding/handoff', [OnboardingController::class, 'handoff'])->middleware('throttle:10,1')->name('onboarding.handoff');
    Route::post('onboarding/handoff/email', [OnboardingController::class, 'emailHandoff'])->middleware('throttle:3,10')->name('onboarding.handoff.email');

    // Accepting requires a logged-in user, but NOT a family (a new/joining user has none yet).
    Route::post('invite/{token}', [FamilyInviteController::class, 'accept'])->name('invite.accept');

    // Web Push subscription + the in-app notifications feed now live in the notifications module
    // (Modules\Notifications\NotificationsServiceProvider), registered globally there.
});

// The phone end of the onboarding's QR code / emailed sign-in link. No auth by design (it IS the sign-in); the
// token is single-use, expires in 10 minutes and is throttled per IP against guessing.
Route::get('/h/{token}', [OnboardingController::class, 'useHandoff'])->middleware('throttle:20,1')->name('handoff');

// Public read-only view of a shared entity via its invite-link token. No auth by design; the token
// is a 48-char unguessable secret. Throttled since it's unauthenticated (enumeration / abuse guard).
Route::get('/e/{token}', [PublicShareController::class, 'show'])->middleware('throttle:120,1')->name('share.public');

// Public guest view of a guest-shared entity via its link token. No auth by design; the token is a
// 48-char unguessable secret, with an optional password gate. Throttled (unauthenticated). The 'web'
// group gives it a session, which the password unlock uses. Guest-initiated writes live on the owning
// module's own public routes (authorized via GuestAccess).
Route::get('/g/{token}', [PublicGuestController::class, 'show'])->middleware('throttle:120,1')->name('guest.public');
Route::post('/g/{token}/unlock', [PublicGuestController::class, 'unlock'])->middleware('throttle:20,1')->name('guest.unlock');

// The app proper: requires both auth and a family.
Route::middleware(['auth', 'family'])->group(function () {
    Route::get('dashboard', function () {
        // Recent (Pick up where you left off) + Today + This Week, assembled from module providers.
        return Inertia::render('dashboard', DashboardData::build());
    })->name('dashboard');

    // Generic sharing management for any registered shareable entity ({type}/{id}). Owner-only,
    // enforced in the controller.
    Route::patch('/shares/{type}/{id}/visibility', [ShareController::class, 'visibility'])->name('shares.visibility');
    Route::put('/shares/{type}/{id}/members/{user}', [ShareController::class, 'putMember'])->name('shares.members.put');
    Route::delete('/shares/{type}/{id}/members/{user}', [ShareController::class, 'removeMember'])->name('shares.members.remove');
    Route::post('/shares/{type}/{id}/link', [ShareController::class, 'createLink'])->middleware('not-demo')->name('shares.link.create');
    Route::delete('/shares/{type}/{id}/link/{link}', [ShareController::class, 'deleteLink'])->name('shares.link.delete');

    // Generic guest-sharing management for any registered guestable entity ({type}/{id}). Owner-only.
    Route::patch('/guest-links/{type}/{id}', [GuestLinkController::class, 'update'])->middleware('not-demo')->name('guests.link.update');
    Route::patch('/guest-links/{type}/{id}/password', [GuestLinkController::class, 'password'])->middleware('not-demo')->name('guests.link.password');
    Route::post('/guest-links/{type}/{id}/guests', [GuestLinkController::class, 'storeGuest'])->name('guests.store');
    Route::patch('/guest-links/{type}/{id}/guests/{guest}', [GuestLinkController::class, 'updateGuest'])->name('guests.update');
    Route::delete('/guest-links/{type}/{id}/guests/{guest}', [GuestLinkController::class, 'destroyGuest'])->name('guests.destroy');

    Route::get('/family/members', [FamilyMemberController::class, 'index'])->name('family.members.index');
    Route::delete('/family/members/{user}', [FamilyMemberController::class, 'destroy'])->name('family.members.destroy');
    Route::patch('/family', [FamilyController::class, 'update'])->name('family.update');
    Route::patch('/family/theme', [FamilyController::class, 'updateTheme'])->name('family.theme');
    Route::post('/family/invitations', [FamilyInviteController::class, 'store'])->middleware('not-demo:email')->name('family.invitations.store');
    Route::delete('/family/invitations/{invitation}', [FamilyInviteController::class, 'destroy'])->name('family.invitations.destroy');

    Route::get('/m/{module}/frontend.js', [ModuleAssetController::class, 'frontend'])->name('module.frontend');

    Route::get('/family/modules', [FamilyModuleController::class, 'index'])->name('family.modules.index');
    Route::post('/family/modules/{module}/enable', [FamilyModuleController::class, 'enable'])->name('family.modules.enable');
    Route::delete('/family/modules/{module}', [FamilyModuleController::class, 'disable'])->name('family.modules.disable');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
