<?php

namespace Modules\Calendar;

use App\Models\User;
use App\Support\Add\AddContext;
use App\Support\Add\AddKinds;
use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardSummaries;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Search\SearchSources;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\Trash\Trash;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Modules\Contacts\Person;
use Modules\Notifications\NotificationCategories;
use Modules\Tasks\Task;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([Console\SyncSubscriptions::class]);
    }

    public function boot(Hooks $hooks): void
    {
        // "Just say it" in the Add sheet: an event read out of what the person typed / photographed (AddKinds), with
        // its to-dos when listed ("school trip Friday: pack lunch, sign the form" -> the event's own checklist).
        // A datetime at midnight reads as all-day; no end = an hour for a timed event, the same day otherwise.
        AddKinds::register('event', 'calendar', [
            'fields' => [
                'title' => ['type' => 'text', 'required' => true],
                'start' => ['type' => 'datetime', 'required' => true],
                'end' => ['type' => 'datetime'],
                'who' => ['type' => 'text', 'ask' => 'member'],
                'tasks' => ['type' => 'list', 'of' => ['name' => 'text']],
            ],
            'save' => function (User $user, array $v, AddContext $ctx) {
                Validator::validate($v, ['start' => ['required', 'date']]); // no silent "today" for an event the reader couldn't date
                $start = Carbon::parse($v['start']);
                $timed = $start->format('H:i') !== '00:00';
                $end = ! empty($v['end']) ? Carbon::parse($v['end']) : null;
                if (! $end || $end->lt($start)) {
                    $end = $timed ? $start->copy()->addHour() : $start->copy();
                }
                $who = $ctx->memberId($v['who'] ?? null);
                $event = CalendarEvent::createFor($user, CalendarEvent::validated([
                    'title' => $v['title'] ?? '',
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'start_time' => $timed ? $start->format('H:i') : null,
                    'end_time' => $timed ? $end->format('H:i') : null,
                    'people' => $who ? [['type' => 'user', 'id' => $who]] : [],
                ]));
                foreach ((array) ($v['tasks'] ?? []) as $t) {
                    $title = mb_substr(trim((string) (is_array($t) ? ($t['name'] ?? '') : $t)), 0, 255);
                    if ($title !== '') {
                        $event->addTask($user, ['title' => $title]);
                    }
                }

                return ['title' => $event->title, 'url' => $event->url()];
            },
        ]);
        // A to-do for an event that's already on the calendar ("for the trip: book the hotel"): the event the input
        // names (the next one with that title), else the event on screen. Neither: no guess, the card asks on Add.
        $upcoming = fn (User $user) => CalendarEvent::visibleTo($user->id)->whereDate('end_date', '>=', today())->orderBy('start_date');
        $pickEvent = function (User $user, AddContext $ctx, array $v) use ($upcoming): ?CalendarEvent {
            $named = filled($v['event'] ?? null) ? $upcoming($user)->whereRaw('lower(title) = ?', [mb_strtolower(trim((string) $v['event']))])->first() : null;

            return $named ?? ($ctx->module === 'calendar' && $ctx->entityId ? CalendarEvent::visibleTo($user->id)->find($ctx->entityId) : null);
        };
        AddKinds::register('event_task', 'calendar', [
            'fields' => [
                'title' => ['type' => 'text', 'required' => true],
                'due' => ['type' => 'date'],
                'who' => ['type' => 'text', 'ask' => 'member'],
                // ponytail: the next 30 events' titles (a repeating event whose first date passed isn't among them); a
                // far-off or repeating event is reached from its own page (the event on screen).
                'event' => ['type' => 'text', 'options' => fn (User $user) => $upcoming($user)->limit(30)->pluck('title')->unique()->values()->all(), 'hidden' => true],
            ],
            'target' => fn (User $user, AddContext $ctx, array $v) => $pickEvent($user, $ctx, $v)?->title,
            'save' => function (User $user, array $v, AddContext $ctx) use ($pickEvent) {
                $data = Validator::validate(['title' => $v['title'] ?? null, 'due_date' => $v['due'] ?? null], [
                    'title' => ['required', 'string', 'max:255'], 'due_date' => ['nullable', 'date'],
                ]);
                $event = $pickEvent($user, $ctx, $v) ?? throw ValidationException::withMessages(['event' => 'Which event? Name it, or add from the event.']);
                $task = $event->addTask($user, $data + ['assigned_to' => $ctx->memberId($v['who'] ?? null)]);

                return ['title' => $task->title, 'url' => $event->url()];
            },
        ]);
        AddKinds::screen('calendar', fn (?int $id, User $user) => $id && ($e = CalendarEvent::visibleTo($user->id)->find($id))
            ? "the event {$e->title} on {$e->start_date->toDateString()}" : null);

        // "Your modules" tile: events from today to the end of the week (their own calendar view, recurrences included).
        DashboardSummaries::register('calendar', fn (int $userId) => ['key' => 'dashboard.sum_calendar', 'params' => [
            'count' => count(CalendarEvent::itemsFor($userId, now()->toDateString(), now()->endOfWeek()->toDateString())),
        ]]);
        // "Calendar changes": its own on/off row in Settings > Notifications and the onboarding's notifications step.
        // Pushed by CalendarEvent::createFor / applyUpdate (events added, moved or renamed).
        NotificationCategories::register('calendar', 'Calendar', 'calendar-days');

        // Other calendars (ICS subscriptions) refresh hourly; withoutOverlapping so a slow host can't stack runs.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('calendar:sync-subscriptions')->hourly()->withoutOverlapping();
        });

        // Adding a CalendarEvent counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('calendar', CalendarEvent::class);

        Trash::source('calendar', CalendarTrash::class);
        Trash::register(CalendarEvent::class);
        // A trashed to-do keeps its event link so it can be restored onto the event; drop the link once
        // trash:purge erases the task for good (links have no FK to tasks_tasks).
        Task::forceDeleted(fn (Task $t) => CalendarEventLink::withoutGlobalScopes()->where(['linkable_type' => 'task', 'linkable_id' => $t->id])->delete());
        ShareableRegistry::register('calendar_event', CalendarEvent::class);

        // MCP: calendar:read / calendar:write scopes.
        McpTools::register('calendar', 'Calendar', [
            Mcp\ListCalendar::class, Mcp\GetEvent::class, Mcp\CreateEvent::class,
            Mcp\UpdateEvent::class, Mcp\DeleteEvent::class, Mcp\AddTodo::class,
            Mcp\UpdateTodo::class, Mcp\DeleteTodo::class, Mcp\ListDeleted::class, Mcp\Restore::class,
        ]);

        // H5 search: title or notes match, visible events only (events already use the core sharing
        // system, so the same visibleTo() every other provider uses applies here too).
        SearchSources::register('calendar', function (string $q, int $userId) {
            $events = SearchSources::ilike(CalendarEvent::query()->visibleTo($userId), ['title', 'notes'], $q)
                ->orderByDesc('start_date')->limit(20)->get();

            return $events->map(fn (CalendarEvent $e) => [
                'module' => 'calendar', 'title' => $e->title,
                'meta' => SearchSources::formatDate($e->start_date).' · '.($e->start_time ? "{$e->start_time}–{$e->end_time}" : Translations::get('calendar.all_day')),
                'href' => "/m/calendar/events/{$e->id}", 'icon' => 'calendar-days',
            ])->values()->all();
        });

        // The calendar's own events, through the same registry other modules use.
        CalendarSources::register('calendar', function (string $from, string $to, int $userId) {
            if (! self::on()) {
                return [];
            }

            // Plus the events' dated to-dos: the tasks source only covers list tasks, so these would show nowhere.
            $todos = array_map(fn (array $p) => CalendarSources::item(
                'task', $p[0]->id, $p[0]->title, $p[0]->due_date->toDateString(), href: "/m/calendar/events/{$p[1]->id}",
                icon: 'list-checks', color: $p[1]->color, done: $p[0]->done,
                people: $p[0]->assignee ? [['type' => 'user', 'id' => $p[0]->assignee->id, 'name' => $p[0]->assignee->name]] : [],
            ), CalendarEvent::datedTodos($userId, fn ($q) => $q->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)));

            // Plus other calendars the person subscribed to, or that someone shared with the family (read-only).
            return [...CalendarEvent::itemsFor($userId, $from, $to), ...$todos, ...CalendarSubscription::itemsFor($userId, $from, $to)];
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
            $until = DashboardAgenda::until();

            return collect(CalendarEvent::itemsFor($userId, $today, $until))
                ->map(function (array $i) use ($today) {
                    $days = (int) now()->parse($i['start'])->diffInDays(now()->parse($i['end'])) + 1;
                    $subtitle = $i['startTime']
                        ? "{$i['startTime']}–{$i['endTime']}"
                        : ($days > 1
                            ? strtr(Translations::get('calendar.day_of'), [':n' => (int) now()->parse($i['start'])->diffInDays(now()->parse(max($i['start'], $today))) + 1, ':total' => $days])
                            : Translations::get('calendar.all_day'));

                    return ['title' => $i['title'], 'subtitle' => $subtitle, 'href' => $i['href'], 'icon' => $i['icon'],
                        'dueDate' => max($i['start'], $today), 'kind' => 'event', 'id' => $i['id'],
                        // Home draws an event with its start time in the rail, its colour as the bar and who
                        // it's for in the meta line; eventId lets its prep to-dos (below) nest under it.
                        'time' => $i['startTime'], 'endTime' => $i['endTime'], 'color' => $i['color'],
                        'people' => array_column($i['people'], 'name'), 'eventId' => $i['id']];
                })
                // Open, dated event to-dos due by the end of the week (overdue included), like project tasks:
                // the tasks provider skips listless tasks, so they'd never surface otherwise.
                ->concat(array_map(fn (array $p) => [
                    'title' => $p[0]->title, 'subtitle' => $p[1]->title, 'href' => "/m/calendar/events/{$p[1]->id}", 'icon' => 'list-checks',
                    'dueDate' => $p[0]->due_date->toDateString(),
                    'toggleUrl' => "/m/calendar/tasks/{$p[0]->id}", 'toggleBody' => ['done' => true], // the dashboard tick sends this: set done, never flip
                    'kind' => 'calendar_todo', 'id' => $p[0]->id, // get_today's widget ticks it via calendar_update_todo
                    'eventId' => $p[1]->id, 'assignee' => $p[0]->assignee?->only(['id', 'name']), // Home nests it under its event as PREP
                ], CalendarEvent::datedTodos($userId, fn ($q) => $q->where('done', false)->whereDate('due_date', '<=', $until))))
                ->values()->all();
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
                // The endless timeline loads more days as you scroll: items for a range, as JSON.
                Route::get('/items', [CalendarController::class, 'items'])->name('items');
                Route::post('/events', [CalendarController::class, 'store'])->name('events.store');
                Route::get('/events/{event}', [CalendarController::class, 'show'])->name('events.show');
                Route::patch('/events/{event}', [CalendarController::class, 'update'])->name('events.update');
                Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->name('events.destroy');
                Route::post('/events/{event}/tasks', [CalendarController::class, 'storeTask'])->name('tasks.store');
                Route::post('/events/{event}/tasks/reorder', [CalendarController::class, 'reorderTasks'])->name('tasks.reorder');
                Route::patch('/tasks/{task}', [CalendarController::class, 'updateTask'])->name('tasks.update');
                Route::delete('/tasks/{task}', [CalendarController::class, 'destroyTask'])->name('tasks.destroy');
                Route::post('/feed', [CalendarFeedController::class, 'reset'])->name('feed.reset');
                // Other calendars (ICS subscriptions). Adding one fetches a URL from our server, hence the throttle.
                Route::post('/subscriptions', [CalendarSubscriptionController::class, 'store'])->middleware('throttle:10,1')->name('subscriptions.store');
                Route::patch('/subscriptions/{subscription}', [CalendarSubscriptionController::class, 'update'])->name('subscriptions.update');
                Route::delete('/subscriptions/{subscription}', [CalendarSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
                Route::get('/external/{event}', [CalendarSubscriptionController::class, 'show'])->name('external.show');
            });

        // Two limits, not one: per-TOKEN (not guest IP) is the main guard — Google/Apple fetch every
        // subscriber's feed from shared IP pools, so an IP-keyed limit alone would 429 real subscribers as
        // usage grows. But per-token alone has no aggregate bound: a client can flood the endpoint by trying
        // a different (guessed/garbage) token on every request, each a cheap 404 lookup. The per-IP cap is
        // generous (20x the per-token one) so it never bites a shared polling pool, only a genuine flood from
        // one source. Prefixed keys so the two limiters' buckets can never collide.
        RateLimiter::for('calendar-feed', fn (Request $request) => [
            Limit::perMinute(30)->by('t:'.$request->route('token')),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);

        // ICS subscribe feed: hit by Google/Apple servers with no session; the unguessable token authorizes.
        Route::middleware(['throttle:calendar-feed'])
            ->get('/calendar/feed/{token}.ics', [CalendarFeedController::class, 'show'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('calendar.feed');
    }

    // Self-gate for listeners/providers: providers boot for every family (rule 2 of the hooks pattern).
    private static function on(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('calendar', (int) $familyId);
    }
}
