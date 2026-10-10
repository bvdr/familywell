<?php

use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

// admin.familywell.org's read-only platform API (the admin Worker calls it server-side with the admin's
// token). Bearer tokens only, so like routes/board.php it is loaded outside the `web` group.
Route::middleware(['auth:api', EnsurePlatformAdmin::class, 'throttle:120,1'])->prefix('api/admin')->group(function () {
    Route::get('me', [AdminApiController::class, 'me']);
    Route::get('stats', [AdminApiController::class, 'stats']);
    Route::get('users', [AdminApiController::class, 'users']);
    Route::get('users/{id}', [AdminApiController::class, 'user'])->whereNumber('id');
    Route::get('families', [AdminApiController::class, 'families']);
    Route::get('families/{id}', [AdminApiController::class, 'family'])->whereNumber('id');
});
