<?php

namespace Modules\Tasks;

use App\Models\EntityVisit;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardItems;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use Modules\Notifications\NotificationCategories;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
                    'icon' => 'list-checks',
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

            $weekEnd = now()->endOfWeek()->toDateString();

            return Task::query()
                ->whereNotNull('task_list_id')
                ->whereNotNull('due_date')
                ->where('done', false)
                ->whereDate('due_date', '<=', $weekEnd)
                ->with(['list.shareSetting', 'list.shares'])
                ->get()
                ->filter(fn (Task $t) => $t->list && ($t->list->visibility() === 'family' || $t->list->shares->contains('user_id', $userId)))
                ->map(fn (Task $t) => [
                    'title' => $t->title,
                    'subtitle' => $t->list->name,
                    'href' => "/m/tasks/lists/{$t->task_list_id}",
                    'icon' => 'list-checks',
                    'dueDate' => $t->due_date->toDateString(),
                    'toggleUrl' => "/m/tasks/tasks/{$t->id}", // empty-body PATCH toggles done
                ])->values()->all();
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
