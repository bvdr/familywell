<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Support\Auth\SocialLogin;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // One email-code flow for both (AuthenticatedSessionController): the two URLs only change the title.
    Route::get('register', [AuthenticatedSessionController::class, 'register'])
        ->name('register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // Per IP. EmailCode also caps sends per address and wrong guesses per code.
    Route::post('auth/code', [AuthenticatedSessionController::class, 'sendCode'])
        ->middleware('throttle:10,1')
        ->name('auth.code.send');

    // ponytail: TEMPORARY, for app reviewers (see AuthenticatedSessionController::passwordLogin).
    Route::post('login/password', [AuthenticatedSessionController::class, 'passwordLogin'])
        ->middleware('throttle:10,1')
        ->name('login.password');

    Route::post('auth/code/verify', [AuthenticatedSessionController::class, 'verifyCode'])
        ->middleware('throttle:20,1')
        ->name('auth.code.verify');

    // "Continue with Google / Apple". Google returns with a GET; Apple with a cross-site form POST, which is
    // why its callback is CSRF-exempt (bootstrap/app.php) and checks its own state cookie instead.
    // Throttled per IP like the login form, so the callbacks can't be hammered with guessed codes.
    Route::middleware('throttle:20,1')->group(function () {
        Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])
            ->whereIn('provider', SocialLogin::PROVIDERS)
            ->name('social.redirect');

        Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])
            ->whereIn('provider', ['google'])
            ->name('social.callback');

        Route::post('auth/{provider}/callback', [SocialLoginController::class, 'callback'])
            ->whereIn('provider', ['apple']);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
