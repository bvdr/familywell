<?php

namespace Modules\Calendar;

use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
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

        // Today / This Week: occurrences overlapping today..end of week. An ongoing multi-day event appears ONCE,
        // on Today, with "day N of M", instead of on every remaining day. dueDate = max(start, today) buckets it
        // (DashboardData puts <= today on Today). No toggleUrl: events aren't "done"; the row renders without a tick.
        DashboardAgenda::register('calendar', function () {
            $userId = auth()->id();
            if (! $userId || ! self::on()) {
                return [];
            }
            $today = now()->toDateString();

            return collect(CalendarEvent::itemsFor($userId, $today, now()->endOfWeek()->toDateString()))
                ->map(function (array $i) use ($today) {
                    $days = (int) now()->parse($i['start'])->diffInDays(now()->parse($i['end'])) + 1;
                    $subtitle = $i['startTime']
                        ? "{$i['startTime']}–{$i['endTime']}"
                        : ($days > 1
                            ? strtr(Translations::get('calendar.day_of'), [':n' => (int) now()->parse($i['start'])->diffInDays(now()->parse(max($i['start'], $today))) + 1, ':total' => $days])
                            : Translations::get('calendar.all_day'));

                    return ['title' => $i['title'], 'subtitle' => $subtitle, 'href' => $i['href'], 'icon' => $i['icon'],
                        'dueDate' => max($i['start'], $today), 'kind' => 'event', 'id' => $i['id']];
                })->values()->all();
        });

        // Now & next card: multi-day events happening now, then ones starting within 30 days (max 4).
        DashboardWidgets::register('calendar', function () {
            $userId = auth()->id();
            if (! $userId || ! self::on()) {
                return null;
            }
            $today = now()->toDateString();
            $rows = collect(CalendarEvent::itemsFor($userId, $today, now()->addDays(30)->toDateString()))
                ->filter(fn ($i) => $i['end'] > $i['start'])
                ->map(function ($i) use ($today) {
                    $now = $i['start'] <= $today;
                    $start = now()->parse($i['start']);

                    return ['id' => $i['id'], 'title' => $i['title'], 'icon' => $i['icon'], 'color' => $i['color'], 'start' => $i['start'], 'end' => $i['end'], 'href' => $i['href'],
                        'now' => $now, 'days' => (int) $start->diffInDays(now()->parse($i['end'])) + 1,
                        'dayOf' => $now ? (int) $start->diffInDays(now()->parse($today)) + 1 : null,
                        'inDays' => $now ? null : (int) now()->parse($today)->diffInDays($start)];
                })
                ->sortBy([['now', 'desc'], ['start', 'asc']])->take(4)->values()->all();

            return $rows ?: null;
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
