<?php

namespace Modules\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Core sharing ("X shared with you") is not owned by a feature module, so the notifications
        // module registers its category itself. Feature modules register their own in their boot().
        NotificationCategories::register('sharing', 'Shared with me', 'user-plus');

        // Share the unread count + a few recent items on every request, so the header bell can render
        // its badge and dropdown without a round-trip. Per-user, so no family context needed.
        Inertia::share('notifications', function () {
            $user = auth()->user();
            if (! $user) {
                return ['unread' => 0, 'items' => []];
            }
            // This prop is shared on EVERY page (the header bell). If the notifications query ever
            // fails (e.g. table not migrated yet right after a deploy), fall back to empty rather
            // than throw, which would blank the whole app.
            try {
                return [
                    'unread' => $user->unreadNotifications()->count(),
                    'items' => $user->notifications()->latest()->limit(12)->get()
                        ->map(fn (DatabaseNotification $n) => NotificationsController::present($n))->values(),
                ];
            } catch (\Throwable $e) {
                report($e);

                return ['unread' => 0, 'items' => []];
            }
        });

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
