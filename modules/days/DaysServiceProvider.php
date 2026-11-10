<?php

namespace Modules\Days;

use App\Support\Dashboard\DashboardSummaries;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Engagement;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Days\Console\NotifyDays;
use Modules\Notifications\NotificationCategories;

/**
 * Days to remember (design board 10c): every yearly date that isn't a birthday. Owns /m/days (A1-A3c), the Home
 * card (A4/A5, via DashboardWidgets) and the 9:00 reminder (A6, days:notify). Birthdays stay their own module;
 * Home shows both sections.
 *
 * Not built yet (ponytail): MCP tools, a calendar source, "+ Photo", and listing a contact's days on their card.
 */
class DaysServiceProvider extends ServiceProvider
{
    /** Home shows today's days plus what's coming in this window. */
    public const HOME_WINDOW = 30;

    public function register(): void
    {
        $this->commands([NotifyDays::class]);
    }

    public function boot(): void
    {
        DashboardSummaries::register('days', fn () => ['key' => 'dashboard.sum_days', 'params' => [
            'count' => $this->visible()->filter(fn (Day $d) => $d->daysUntil() <= self::HOME_WINDOW)->count(),
        ]]);
        Engagement::track('days', Day::class);
        // Its own row on Me > Notifications (design: "one switch per module").
        NotificationCategories::register('days', 'Days to remember', 'heart');
        ShareableRegistry::register('day', Day::class);
        Trash::source('days', DaysTrash::class);
        Trash::register(Day::class);

        // Hourly, like birthdays:notify: each run reaches the families whose own clock has passed 9:00.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('days:notify')->hourly()->withoutOverlapping();
        });

        // A4/A5: today's days get the big card, the next few are listed under it. null = nothing soon / module off.
        DashboardWidgets::register('days', function () {
            if (! $this->enabledHere()) {
                return null;
            }
            $soon = $this->visible()->filter(fn (Day $d) => $d->daysUntil() <= self::HOME_WINDOW)
                ->sortBy(fn (Day $d) => $d->daysUntil())->values();
            if ($soon->isEmpty()) {
                return null;
            }

            return $soon->map(function (Day $d) {
                $row = $d->toPayload();
                // Only today's card needs the people (Call / Call family); skips a contacts query per coming day.
                $row['people'] = $d->daysUntil() === 0
                    ? $d->people()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'phone' => $p->contactMethods->firstWhere('type', 'phone')?->value])->values()
                    : [];

                return $row;
            })->all();
        });

        Route::middleware(['web', 'auth', 'family', 'module:days'])
            ->prefix('m/days')
            ->name('days.')
            ->group(function () {
                Route::get('/', [DaysController::class, 'index'])->name('index');
                Route::post('/', [DaysController::class, 'store'])->name('store');
                Route::patch('/{day}', [DaysController::class, 'update'])->name('update');
                Route::delete('/{day}', [DaysController::class, 'destroy'])->name('destroy');
            });
    }

    /** The days the signed-in person can see. */
    private function visible()
    {
        $userId = Auth::id();
        if (! $userId) {
            return collect();
        }
        // Home's card and its status line both ask: one load per request (request attribute bag, dies with the request).
        $bag = app('request')->attributes;
        $key = 'days.visible.'.CurrentFamily::id().".{$userId}";
        if (! $bag->has($key)) {
            $bag->set($key, Day::visibleTo($userId)->with('shareSetting')->get());
        }

        return $bag->get($key);
    }

    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('days', (int) $familyId);
    }
}
