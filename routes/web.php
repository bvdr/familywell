<?php

use App\Http\Controllers\FamilyController;
use App\Http\Controllers\FamilyInviteController;
use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\FamilyModuleController;
use App\Http\Controllers\ModuleAssetController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::middleware(['auth'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::get('onboarding/create', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    // Accepting requires a logged-in user, but NOT a family (a new/joining user has none yet).
    Route::post('invite/{token}', [FamilyInviteController::class, 'accept'])->name('invite.accept');
});

// The app proper: requires both auth and a family.
Route::middleware(['auth', 'family'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('/family/members', [FamilyMemberController::class, 'index'])->name('family.members.index');
    Route::delete('/family/members/{user}', [FamilyMemberController::class, 'destroy'])->name('family.members.destroy');
    Route::patch('/family', [FamilyController::class, 'update'])->name('family.update');
    Route::post('/family/invitations', [FamilyInviteController::class, 'store'])->name('family.invitations.store');
    Route::delete('/family/invitations/{invitation}', [FamilyInviteController::class, 'destroy'])->name('family.invitations.destroy');

    Route::get('/m/{module}/frontend.js', [ModuleAssetController::class, 'frontend'])->name('module.frontend');

    Route::get('/family/modules', [FamilyModuleController::class, 'index'])->name('family.modules.index');
    Route::post('/family/modules/{module}/enable', [FamilyModuleController::class, 'enable'])->name('family.modules.enable');
    Route::delete('/family/modules/{module}', [FamilyModuleController::class, 'disable'])->name('family.modules.disable');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
