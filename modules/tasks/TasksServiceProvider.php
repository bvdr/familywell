<?php

namespace Modules\Tasks;

use App\Support\Dashboard\DashboardItems;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Opt to-do lists into the core sharing system (addressable as `task_list` in share URLs).
        ShareableRegistry::register('task_list', TaskList::class);

        // Surface the family's to-do lists on the dashboard, deep-linked, respecting visibility.
        DashboardItems::register('tasks', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('tasks', $familyId)) {
                return [];
            }

            return TaskList::query()
                ->withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('done', true)])
                ->with(['shareSetting', 'shares'])
                ->whereNull('archived_at')
                ->orderBy('position')
                ->latest()
                ->get()
                ->filter(fn (TaskList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
                ->take(4)
                ->map(fn (TaskList $l) => [
                    'title' => $l->name,
                    'subtitle' => "{$l->done_count} of {$l->tasks_count} done",
                    'href' => "/m/tasks/lists/{$l->id}",
                    'icon' => 'list-checks',
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
