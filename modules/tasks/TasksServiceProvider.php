<?php

namespace Modules\Tasks;

use App\Models\EntityVisit;
use App\Models\User;
use App\Support\Add\AddContext;
use App\Support\Add\AddKinds;
use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardItems;
use App\Support\Dashboard\DashboardSummaries;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Search\SearchSources;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\NotificationCategories;
use Modules\Tasks\Mcp\TaskData;

class TasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // "Just say it" in the Add sheet (AddKinds): a to-do, or a whole new to-do list. A to-do goes to the list on
        // screen, else the list this person added to last, else their first list (else a new one named like the
        // module); the card names that list up front (target) so it's clear where it lands.
        // The lists this person can add to, in their order: what the reader may pick for `list`.
        $lists = fn (User $user) => TaskList::visibleTo($user->id)->whereNull('archived_at')->orderBy('position');
        $pickList = function (User $user, AddContext $ctx, array $v = []) use ($lists): ?TaskList {
            // A list the person named, or the heading of a photographed list ("Oana"): that existing list first.
            $named = filled($v['list'] ?? null) ? $lists($user)->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $v['list']))])->first() : null;
            $lastList = Task::where('created_by', $user->id)->whereNotNull('task_list_id')->latest('id')->value('task_list_id');

            return $named
                ?? ($ctx->module === 'tasks' && $ctx->entityId ? TaskData::list($user, $ctx->entityId) : null)
                ?? ($lastList ? TaskData::list($user, $lastList) : null)
                ?? $lists($user)->first();
        };
        AddKinds::register('todo', 'tasks', [
            'fields' => [
                'title' => ['type' => 'text', 'required' => true],
                'due' => ['type' => 'date'],
                'who' => ['type' => 'text', 'ask' => 'member'],
                // Which existing list, when the input names one. Shown as the card's destination, not as a field.
                'list' => ['type' => 'text', 'options' => fn (User $user) => $lists($user)->pluck('name')->all(), 'hidden' => true],
            ],
            'target' => fn (User $user, AddContext $ctx, array $v) => $pickList($user, $ctx, $v)?->name ?? Translations::get('nav.tasks'),
            'save' => function (User $user, array $v, AddContext $ctx) use ($pickList) {
                $data = Validator::validate(['title' => $v['title'] ?? null, 'due_date' => $v['due'] ?? null], [
                    'title' => ['required', 'string', 'max:255'], 'due_date' => ['nullable', 'date'],
                ]);
                $list = $pickList($user, $ctx, $v) ?? TaskList::createFor($user, ['name' => Translations::get('nav.tasks')]);
                $task = TaskData::addTask($user, $list, $data + ['assigned_to' => $ctx->memberId($v['who'] ?? null)]);

                return ['title' => $task->title, 'url' => "/m/tasks/lists/{$list->id}"];
            },
        ]);
        AddKinds::register('task_list', 'tasks', [
            'fields' => [
                'name' => ['type' => 'text', 'required' => true],
            ],
            'save' => function (User $user, array $v) {
                $data = Validator::validate(['name' => $v['name'] ?? null], ['name' => ['required', 'string', 'max:255']]);
                $list = TaskList::createFor($user, $data);
                TaskData::changed();

                return ['title' => $list->name, 'url' => "/m/tasks/lists/{$list->id}"];
            },
        ]);
        AddKinds::screen('tasks', fn (?int $id, User $user) => $id && ($l = TaskData::list($user, $id)) ? "the to-do list {$l->name}" : null);

        // "Your modules" tile: open tasks due today (or overdue) on the lists this person can see.
        DashboardSummaries::register('tasks', fn (int $userId) => ['key' => 'dashboard.sum_tasks', 'params' => [
            'count' => Task::where('done', false)->whereDate('due_date', '<=', today())->whereHas('list', fn ($q) => $q->visibleTo($userId))->count(),
        ]]);
        // Adding a Task counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('tasks', Task::class);

        // Reversible delete: trashed rows are purged after the retention window (trash:purge).
        // What this module shows in Settings > Recently deleted and its MCP trash tools.
        Trash::source('tasks', TasksTrash::class);
        Trash::register(TaskList::class);
        Trash::register(Task::class);
        // MCP: tools an AI assistant can use on this module, gated by the `tasks:read` / `tasks:write`
        // scopes the person grants on the consent screen (see docs/mcp.md).
        McpTools::register('tasks', 'Tasks', [
            Mcp\ListLists::class, Mcp\GetList::class, Mcp\Search::class,
            Mcp\CreateList::class, Mcp\UpdateList::class, Mcp\CreateTask::class, Mcp\UpdateTask::class,
            Mcp\DeleteList::class, Mcp\DeleteTask::class, Mcp\ListDeleted::class, Mcp\Restore::class,
        ]);

        // Opt to-do lists into the core sharing system (addressable as `task_list` in share URLs).
        ShareableRegistry::register('task_list', TaskList::class);

        // Notifications category for this module (drives the Settings toggle + stacked-push label).
        NotificationCategories::register('tasks', 'Tasks', 'list-checks');

        // "Pick up where you left off": the to-do lists this user opened in the last few days,
        // tagged with when so the dashboard can order them across modules. Respects visibility.
        DashboardItems::register('tasks', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('tasks', $familyId)) {
                return [];
            }

            $visits = EntityVisit::recentMap('task_list');
            if ($visits->isEmpty()) {
                return [];
            }

            return TaskList::query()
                ->withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('done', true)])
                ->with(['shareSetting', 'shares'])
                ->whereIn('id', $visits->keys())
                ->whereNull('archived_at')
                ->get()
                ->filter(fn (TaskList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
                ->map(fn (TaskList $l) => [
                    'title' => $l->name,
                    'subtitle' => "{$l->done_count} of {$l->tasks_count} done",
                    'href' => "/m/tasks/lists/{$l->id}",
                    'category' => 'tasks',
                    // The list's own icon slug + colour swatch (lib/tasks.ts resolves them, like the lists page).
                    'icon' => $l->icon ?: 'list-checks',
                    'color' => $l->color,
                    'visitedAt' => $visits[$l->id]->toIso8601String(),
                ])->values()->all();
        });

        // Today / This Week: open, dated tasks living in a to-do list (project tasks come from the
        // projects provider), due through the end of this week, in a list the user can see.
        DashboardAgenda::register('tasks', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('tasks', $familyId)) {
                return [];
            }

            $weekEnd = DashboardAgenda::until();

            return Task::query()
                ->whereNotNull('task_list_id')
                ->whereNotNull('due_date')
                ->where('done', false)
                ->whereDate('due_date', '<=', $weekEnd)
                ->with(['list.shareSetting', 'list.shares', 'assignee'])
                ->get()
                ->filter(fn (Task $t) => $t->list && ($t->list->visibility() === 'family' || $t->list->shares->contains('user_id', $userId)))
                ->map(fn (Task $t) => [
                    'title' => $t->title,
                    'subtitle' => $t->list->name,
                    'href' => "/m/tasks/lists/{$t->task_list_id}",
                    'icon' => 'list-checks',
                    'dueDate' => $t->due_date->toDateString(),
                    'toggleUrl' => "/m/tasks/tasks/{$t->id}", 'toggleBody' => ['done' => true], // the dashboard tick sends this: set done, never flip
                    'kind' => 'task', // kind + id: get_today's widget ticks it off via the MCP tool
                    'id' => $t->id,
                    'assignee' => $t->assignee?->only(['id', 'name']), // Home's avatar chip on the row
                ])->values()->all();
        });

        // Home's "Anytime" row: open to-dos with no date (top-level only, in a live list the viewer can see), so
        // they still get a glance without crowding Today. Newest first; the row names the first three.
        DashboardWidgets::register('tasks', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('tasks', $familyId)) {
                return null;
            }
            $open = Task::query()->whereNull('due_date')->whereNull('parent_id')->where('done', false)
                ->whereHas('list', fn ($q) => $q->visibleTo($userId)->whereNull('archived_at'));
            $count = (clone $open)->count();

            return $count === 0 ? null : ['undated' => $count, 'titles' => $open->latest('id')->limit(3)->pluck('title')->all()];
        });

        // Calendar: list tasks with a due date in the range, done ones too (struck through on the calendar).
        // Visibility = the list's sharing, same rule as the dashboard agenda above.
        CalendarSources::register('tasks', function (string $from, string $to, int $userId) {
            if (! app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id())) {
                return [];
            }

            return Task::query()->whereNotNull('task_list_id')
                ->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)
                ->with(['list.shareSetting', 'list.shares', 'assignee'])->get()
                ->filter(fn (Task $t) => $t->list && ($t->list->visibility() === 'family' || $t->list->shares->contains('user_id', $userId)))
                ->map(fn (Task $t) => CalendarSources::item('task', $t->id, $t->title, $t->due_date->toDateString(),
                    href: "/m/tasks/lists/{$t->task_list_id}", icon: 'list-checks', color: $t->list->color,
                    people: $t->assignee ? [['type' => 'user', 'id' => $t->assignee->id, 'name' => $t->assignee->name]] : [], done: $t->done))
                ->values()->all();
        });

        // H5 search: to-dos (title/note) AND the lists themselves (name), only ones this user can see.
        SearchSources::register('tasks', function (string $q, int $userId) {
            $rows = [];
            $tasks = SearchSources::ilike(
                Task::query()->with('list')->whereHas('list', fn ($l) => $l->visibleTo($userId)),
                ['title', 'note'], $q
            )->orderByDesc('id')->limit(20)->get();
            foreach ($tasks as $task) {
                $rows[] = [
                    'module' => 'tasks', 'title' => $task->title,
                    'meta' => trim(implode(' · ', array_filter([
                        $task->list?->name,
                        $task->done ? Translations::get('common.done') : ($task->due_date ? SearchSources::formatDate($task->due_date) : null),
                    ]))),
                    'href' => "/m/tasks/lists/{$task->task_list_id}", 'icon' => 'list-checks',
                ];
            }
            $lists = SearchSources::ilike(TaskList::query()->visibleTo($userId)->whereNull('archived_at'), ['name'], $q)
                ->orderByDesc('id')->limit(20)->get();
            foreach ($lists as $list) {
                $rows[] = ['module' => 'tasks', 'title' => $list->name, 'meta' => Translations::get('nav.tasks'),
                    'href' => "/m/tasks/lists/{$list->id}", 'icon' => 'list-checks'];
            }

            return array_slice($rows, 0, 20);
        });

        // Full Inertia pages, gated by `module:tasks` so they are only reachable when the family
        // has enabled the module.
        Route::middleware(['web', 'auth', 'family', 'module:tasks'])
            ->prefix('m/tasks')
            ->name('tasks.')
            ->group(function () {
                Route::get('/', [TasksController::class, 'index'])->name('index');
                Route::post('/lists', [TasksController::class, 'storeList'])->name('lists.store');
                Route::get('/lists/{list}', [TasksController::class, 'show'])->name('show');
                Route::patch('/lists/{list}', [TasksController::class, 'updateList'])->name('lists.update');
                Route::post('/lists/{list}/duplicate', [TasksController::class, 'duplicateList'])->name('lists.duplicate');
                Route::delete('/lists/{list}', [TasksController::class, 'destroyList'])->name('lists.destroy');

                Route::post('/lists/{list}/tasks', [TasksController::class, 'storeTask'])->name('tasks.store');
                Route::post('/lists/{list}/tasks/reorder', [TasksController::class, 'reorderTasks'])->name('tasks.reorder');
                Route::patch('/tasks/{task}', [TasksController::class, 'updateTask'])->name('tasks.update');
                Route::delete('/tasks/{task}', [TasksController::class, 'destroyTask'])->name('tasks.destroy');
            });
    }
}
