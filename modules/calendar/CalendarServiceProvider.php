<?php

namespace Modules\Calendar;

use App\Support\Calendar\CalendarSources;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Contacts\Person;

class CalendarServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        Trash::source('calendar', CalendarTrash::class);
        Trash::register(CalendarEvent::class);
        ShareableRegistry::register('calendar_event', CalendarEvent::class);

        // The calendar's own events, through the same registry other modules use.
        CalendarSources::register('calendar', function (string $from, string $to, int $userId) {
            if (! self::on()) {
                return [];
            }

            return CalendarEvent::itemsFor($userId, $from, $to);
        });

        // A contact is hard-deleted: drop it from every event (links have no FK).
        $hooks->listen('person.deleted', function (Person $person) {
            if (self::on()) {
                CalendarEventLink::where(['linkable_type' => 'person', 'linkable_id' => $person->id])->delete();
            }
        });

        Route::middleware(['web', 'auth', 'family', 'module:calendar'])
            ->prefix('m/calendar')
            ->name('calendar.')
            ->group(function () {
                Route::get('/', [CalendarController::class, 'index'])->name('index');
                Route::post('/events', [CalendarController::class, 'store'])->name('events.store');
                Route::get('/events/{event}', [CalendarController::class, 'show'])->name('events.show');
                Route::patch('/events/{event}', [CalendarController::class, 'update'])->name('events.update');
                Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->name('events.destroy');
                Route::post('/events/{event}/tasks', [CalendarController::class, 'storeTask'])->name('tasks.store');
                Route::post('/events/{event}/tasks/reorder', [CalendarController::class, 'reorderTasks'])->name('tasks.reorder');
                Route::patch('/tasks/{task}', [CalendarController::class, 'updateTask'])->name('tasks.update');
                Route::delete('/tasks/{task}', [CalendarController::class, 'destroyTask'])->name('tasks.destroy');
            });
    }

    // Self-gate for listeners/providers: providers boot for every family (rule 2 of the hooks pattern).
    private static function on(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('calendar', (int) $familyId);
    }
}
