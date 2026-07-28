<?php

namespace Modules\Projects;

use App\Support\Dashboard\DashboardItems;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Projects opt into the core sharing system (addressable as `project` in share URLs).
        ShareableRegistry::register('project', Project::class);

        // Surface active projects on the dashboard, deep-linked, respecting visibility.
        DashboardItems::register('projects', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('projects', $familyId)) {
                return [];
            }

            return Project::query()
                ->with(['shareSetting', 'shares'])
                ->whereNull('archived_at')
                ->orderBy('position')->latest()->get()
                ->filter(fn (Project $p) => $p->visibility() === 'family' || $p->shares->contains('user_id', $userId))
                ->take(4)
                ->map(fn (Project $p) => [
                    'title' => $p->name,
                    'subtitle' => $p->progress.'% · '.str_replace('_', ' ', $p->status),
                    'href' => "/m/projects/{$p->id}",
                    'icon' => 'folder-kanban',
                ])->values()->all();
        });

        Route::middleware(['web', 'auth', 'family', 'module:projects'])
            ->prefix('m/projects')
            ->name('projects.')
            ->group(function () {
                Route::get('/', [ProjectsController::class, 'index'])->name('index');
                Route::post('/', [ProjectsController::class, 'storeProject'])->name('store');
                Route::get('/{project}', [ProjectsController::class, 'show'])->name('show');
                Route::patch('/{project}', [ProjectsController::class, 'updateProject'])->name('update');
                Route::delete('/{project}', [ProjectsController::class, 'destroyProject'])->name('destroy');
                Route::post('/{project}/duplicate', [ProjectsController::class, 'duplicateProject'])->name('duplicate');

                Route::post('/{project}/tasks', [ProjectsController::class, 'storeTask'])->name('tasks.store');
                Route::patch('/tasks/{task}', [ProjectsController::class, 'updateTask'])->name('tasks.update');
                Route::delete('/tasks/{task}', [ProjectsController::class, 'destroyTask'])->name('tasks.destroy');

                Route::post('/{project}/dependencies', [ProjectsController::class, 'storeDependency'])->name('deps.store');
                Route::patch('/dependencies/{dependency}', [ProjectsController::class, 'updateDependency'])->name('deps.update');
                Route::delete('/dependencies/{dependency}', [ProjectsController::class, 'destroyDependency'])->name('deps.destroy');

                Route::get('/{project}/linkable-lists', [ProjectsController::class, 'linkableLists'])->name('lists.linkable');
                Route::post('/{project}/lists', [ProjectsController::class, 'linkList'])->name('lists.link');
                Route::delete('/{project}/lists/{list}', [ProjectsController::class, 'unlinkList'])->name('lists.unlink');
            });
    }
}
