<?php

namespace Modules\Projects;

use App\Models\EntityVisit;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardItems;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\NotificationCategories;
use Modules\Tasks\Task;

class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Projects opt into the core sharing system (addressable as `project` in share URLs).
        ShareableRegistry::register('project', Project::class);

        NotificationCategories::register('projects', 'Projects', 'folder-kanban');

        // MCP: tools an AI assistant can use on this module, gated by the `projects:read` /
        // `projects:write` scopes the person grants on the consent screen (see docs/mcp.md).
        // projects_delete is permanent (projects have no trash), so it is marked destructive and asks the
        // assistant to confirm with the person first. Deleted project tasks go to the trash like any task.
        McpTools::register('projects', 'Projects', [
            Mcp\ListProjects::class, Mcp\GetProject::class,
            Mcp\CreateProject::class, Mcp\UpdateProject::class,
            Mcp\AddTask::class, Mcp\UpdateTask::class, Mcp\DeleteTask::class, Mcp\DeleteProject::class, ]);

        // "Pick up where you left off": projects this user opened in the last few days, tagged with
        // when so the dashboard can order them across modules. Respects visibility.
        DashboardItems::register('projects', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('projects', $familyId)) {
                return [];
            }

            $visits = EntityVisit::recentMap('project');
            if ($visits->isEmpty()) {
                return [];
            }

            return Project::query()
                ->with(['shareSetting', 'shares'])
                ->whereIn('id', $visits->keys())
                ->whereNull('archived_at')
                ->get()
                ->filter(fn (Project $p) => $p->visibility() === 'family' || $p->shares->contains('user_id', $userId))
                ->map(fn (Project $p) => [
                    'title' => $p->name,
                    'subtitle' => $p->progress.'% · '.str_replace('_', ' ', $p->status),
                    'href' => "/m/projects/{$p->id}",
                    'category' => 'projects',
                    'icon' => 'folder-kanban',
                    'visitedAt' => $visits[$p->id]->toIso8601String(),
                ])->values()->all();
        });

        // Today / This Week: open, dated project tasks (tasks-module tasks linked to a project),
        // due through the end of this week, in a non-archived project the user can see.
        DashboardAgenda::register('projects', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('projects', $familyId)) {
                return [];
            }

            // Which task is in which viewable project (family-scoped links; keep last if a task ever
            // links to two projects).
            $links = ProjectLink::where('linkable_type', 'task')->get();
            if ($links->isEmpty()) {
                return [];
            }
            $projects = Project::query()
                ->with(['shareSetting', 'shares'])
                ->whereIn('id', $links->pluck('project_id')->unique())
                ->whereNull('archived_at')->get()
                ->filter(fn (Project $p) => $p->visibility() === 'family' || $p->shares->contains('user_id', $userId))
                ->keyBy('id');
            $taskToProject = $links->filter(fn (ProjectLink $l) => $projects->has($l->project_id))
                ->pluck('project_id', 'linkable_id');
            if ($taskToProject->isEmpty()) {
                return [];
            }

            $weekEnd = now()->endOfWeek()->toDateString();

            return Task::query()
                ->whereIn('id', $taskToProject->keys())
                ->whereNotNull('due_date')
                ->where('done', false)
                ->whereDate('due_date', '<=', $weekEnd)
                ->get()
                ->map(function (Task $t) use ($taskToProject, $projects) {
                    $project = $projects[$taskToProject[$t->id]];

                    return [
                        'title' => $t->title,
                        'subtitle' => $project->name,
                        'href' => "/m/projects/{$project->id}",
                        'icon' => 'folder-kanban',
                        'dueDate' => $t->due_date->toDateString(),
                        'toggleUrl' => "/m/projects/tasks/{$t->id}", // empty-body PATCH toggles done
                        'kind' => 'project_task', // kind + id: get_today's widget ticks it off via the MCP tool
                        'id' => $t->id,
                    ];
                })->values()->all();
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

                Route::post('/{project}/cover', [ProjectsController::class, 'uploadCover'])->name('cover.upload');
                Route::get('/{project}/cover', [ProjectsController::class, 'coverFile'])->name('cover.show');
                Route::delete('/{project}/cover', [ProjectsController::class, 'deleteCover'])->name('cover.delete');

                Route::post('/{project}/tasks', [ProjectsController::class, 'storeTask'])->name('tasks.store');
                Route::post('/{project}/tasks/reorder', [ProjectsController::class, 'reorderTasks'])->name('tasks.reorder');
                Route::patch('/tasks/{task}', [ProjectsController::class, 'updateTask'])->name('tasks.update');
                Route::delete('/tasks/{task}', [ProjectsController::class, 'destroyTask'])->name('tasks.destroy');

                Route::post('/{project}/dependencies', [ProjectsController::class, 'storeDependency'])->name('deps.store');
                Route::patch('/dependencies/{dependency}', [ProjectsController::class, 'updateDependency'])->name('deps.update');
                Route::delete('/dependencies/{dependency}', [ProjectsController::class, 'destroyDependency'])->name('deps.destroy');

                Route::get('/{project}/linkable-lists', [ProjectsController::class, 'linkableLists'])->name('lists.linkable');
                Route::post('/{project}/lists', [ProjectsController::class, 'linkList'])->name('lists.link');
                Route::post('/{project}/lists/new', [ProjectsController::class, 'createList'])->name('lists.create');
                Route::delete('/{project}/lists/{list}', [ProjectsController::class, 'unlinkList'])->name('lists.unlink');

                Route::get('/{project}/linkable-notes', [ProjectsController::class, 'linkableNotes'])->name('notes.linkable');
                Route::post('/{project}/notes', [ProjectsController::class, 'linkNote'])->name('notes.link');
                Route::post('/{project}/notes/new', [ProjectsController::class, 'createNote'])->name('notes.create');
                Route::delete('/{project}/notes/{note}', [ProjectsController::class, 'unlinkNote'])->name('notes.unlink');
            });
    }
}
