<?php

use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

// admin.familywell.org's platform API (read-only except the lifetime-access toggle and the admin's push device) (the admin Worker calls it server-side with the admin's
// token). Bearer tokens only, so like routes/board.php it is loaded outside the `web` group.
Route::middleware(['auth:api', EnsurePlatformAdmin::class, 'throttle:120,1'])->prefix('api/admin')->group(function () {
    Route::get('me', [AdminApiController::class, 'me']);
    Route::get('stats', [AdminApiController::class, 'stats']);
    Route::get('users', [AdminApiController::class, 'users']);
    Route::get('users/{id}', [AdminApiController::class, 'user'])->whereNumber('id');
    Route::get('families', [AdminApiController::class, 'families']);
    Route::get('families/{id}', [AdminApiController::class, 'family'])->whereNumber('id');
    // The one write: the comped lifetime-access toggle (see AdminApiController::setLifetime).
    Route::put('families/{id}/lifetime', [AdminApiController::class, 'grantLifetime'])->whereNumber('id');
    Route::delete('families/{id}/lifetime', [AdminApiController::class, 'revokeLifetime'])->whereNumber('id');
    // The admin PWA's own push device (App\Support\AdminPush): our VAPID key, (un)subscribe, a test push.
    Route::get('push', [AdminApiController::class, 'pushKey']);
    Route::put('push', [AdminApiController::class, 'pushSubscribe']);
    Route::delete('push', [AdminApiController::class, 'pushUnsubscribe']);
    Route::post('push/test', [AdminApiController::class, 'pushTest']);
});
