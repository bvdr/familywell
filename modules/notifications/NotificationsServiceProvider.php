<?php

namespace Modules\Notifications;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Core sharing ("X shared with you") is not owned by a feature module, so the notifications
        // module registers its category itself. Feature modules register their own in their boot().
        NotificationCategories::register('sharing', 'Shared with me', 'user-plus');

        // The header bell's `notifications` shared prop lives in HandleInertiaRequests::share() (per
        // request), NOT here: an Inertia::share() in this boot() runs once per Octane worker and gets
        // dropped after the first request, leaving the bell permanently "all caught up".

        // Always-on infrastructure: unlike feature modules these routes are not gated by
        // `module:notifications`; every signed-in user has a notifications feed and can manage push.
        Route::middleware(['web', 'auth'])->group(function () {
            // Web Push subscription management + preferences + test.
            Route::post('push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
            Route::delete('push/subscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
            Route::post('push/test', [PushController::class, 'test'])->name('push.test');
            Route::patch('push/prefs', [PushController::class, 'prefs'])->name('push.prefs');

            // In-app feed.
            Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationsController::class, 'readAll'])->name('notifications.readAll');
            Route::post('/notifications/{notification}/read', [NotificationsController::class, 'read'])->name('notifications.read');
        });
    }
}
