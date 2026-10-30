<?php

namespace Modules\Projects;

use App\Models\EntityVisit;
use App\Models\User;
use App\Support\Add\AddContext;
use App\Support\Add\AddKinds;
use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardItems;
use App\Support\Dashboard\DashboardSummaries;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Modules\Notifications\NotificationCategories;
use Modules\Projects\Mcp\ProjectData;
use Modules\Shopping\ShoppingList;
use Modules\Tasks\Task;

class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // "Just say it" in the Add sheet (AddKinds): a new project (with its first tasks, "a project Gard with: buy
        // posts, call the builder"), or a task in an existing project. A project task goes to the project the input
        // names, else the project on screen; with neither there's no guess (the card says so on Add), since a stray
        // project task is worse than the person picking Classic.
        $projects = fn (User $user) => Project::visibleTo($user->id)->whereNull('archived_at')->orderBy('position');
        $pickProject = function (User $user, AddContext $ctx, array $v) use ($projects): ?Project {
            $named = filled($v['project'] ?? null) ? $projects($user)->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $v['project']))])->first() : null;

            return $named ?? ($ctx->module === 'projects' && $ctx->entityId ? ProjectData::project($user, $ctx->entityId) : null);
        };
        AddKinds::register('project', 'projects', [
            'fields' => [
                'name' => ['type' => 'text', 'required' => true],
                'start' => ['type' => 'date'],
                'tasks' => ['type' => 'list', 'of' => ['name' => 'text']],
            ],
            'save' => function (User $user, array $v) {
                $data = Validator::validate(['name' => $v['name'] ?? null, 'target_start' => $v['start'] ?? null], [
                    'name' => ['required', 'string', 'max:255'], 'target_start' => ['nullable', 'date'],
                ]);
                $project = Project::createFor($user, $data);
                foreach ((array) ($v['tasks'] ?? []) as $t) {
                    $title = mb_substr(trim((string) (is_array($t) ? ($t['name'] ?? '') : $t)), 0, 255);
                    if ($title !== '') {
                        $project->addTask($user, ['title' => $title]);
                    }
                }
                ProjectData::changed();

                return ['title' => $project->name, 'url' => $project->path()];
            },
        ]);
        AddKinds::register('project_task', 'projects', [
            'fields' => [
                'title' => ['type' => 'text', 'required' => true],
                'due' => ['type' => 'date'],
                'who' => ['type' => 'text', 'ask' => 'member'],
                'project' => ['type' => 'text', 'options' => fn (User $user) => $projects($user)->pluck('name')->all(), 'hidden' => true],
            ],
            'target' => fn (User $user, AddContext $ctx, array $v) => $pickProject($user, $ctx, $v)?->name,
            'save' => function (User $user, array $v, AddContext $ctx) use ($pickProject) {
                $data = Validator::validate(['title' => $v['title'] ?? null, 'due_date' => $v['due'] ?? null], [
                    'title' => ['required', 'string', 'max:255'], 'due_date' => ['nullable', 'date'],
                ]);
                $project = $pickProject($user, $ctx, $v) ?? throw ValidationException::withMessages(['project' => 'Which project? Name it, or add from the project.']);
                $task = $project->addTask($user, $data + ['assigned_to' => $ctx->memberId($v['who'] ?? null)]);
                ProjectData::changed();

                return ['title' => $task->title, 'url' => $project->path()];
            },
        ]);
        // On a project's page: its name and its linked shopping lists, so "buy screws" goes to the project's list and
        // "call the builder" becomes a task in this project (the reader fills `list` / `project` from it).
        AddKinds::screen('projects', function (?int $id, User $user) {
            $project = $id ? ProjectData::project($user, $id) : null;
            if (! $project) {
                return null;
            }
            // ponytail: only shopping lists, the one attached thing an Add kind can file into; add notes/spending
            // here when they get a kind with a `list` field.
            $lists = ShoppingList::visibleTo($user->id)->whereIn('id', $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id'))->pluck('name');

            return "the project {$project->name}".($lists->isNotEmpty() ? ', its shopping lists: '.$lists->implode(', ') : '');
        });

        // "Your modules" tile: projects still going (not archived) that this person can see.
        DashboardSummaries::register('projects', fn (int $userId) => ['key' => 'dashboard.sum_projects', 'params' => ['count' => Project::visibleTo($userId)->whereNull('archived_at')->count()]]);
        // Adding a Project counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('projects', Project::class);
        // Classic add's sub line ("last: bananas"): this person's newest project they can still see.
        AddKinds::last('projects', fn (User $u) => Project::where('created_by', $u->id)->visibleTo($u->id)->latest('id')->value('name'));

        // Projects opt into the core sharing system (addressable as `project` in share URLs).
        ShareableRegistry::register('project', Project::class);

        NotificationCategories::register('projects', 'Projects', 'folder-kanban');

        // MCP: tools an AI assistant can use on this module, gated by the `projects:read` /
        // `projects:write` scopes the person grants on the consent screen (see docs/mcp.md).
        // Deleted projects and project tasks go to the 30-day trash (projects_list_deleted / projects_restore).
        McpTools::register('projects', 'Projects', [
            Mcp\ListProjects::class, Mcp\GetProject::class,
            Mcp\CreateProject::class, Mcp\UpdateProject::class,
            Mcp\AddTask::class, Mcp\UpdateTask::class, Mcp\DeleteTask::class, Mcp\DeleteProject::class,
            Mcp\LinkItem::class, Mcp\UnlinkItem::class, Mcp\ListDeleted::class, Mcp\Restore::class, ]);

        // 30-day trash: deleted projects + project tasks in Settings > Recently deleted (ProjectsTrash).
        Trash::source('projects', ProjectsTrash::class);
        Trash::register(Project::class);
        // A trashed project task keeps its link so it can be restored into the project; drop the link once
        // trash:purge erases the task for good (links have no FK to tasks_tasks).
        Task::forceDeleted(fn (Task $t) => ProjectLink::withoutGlobalScopes()->where(['linkable_type' => 'task', 'linkable_id' => $t->id])->delete());

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

            $weekEnd = DashboardAgenda::until();

            return Task::query()
                ->whereIn('id', $taskToProject->keys())
                ->whereNotNull('due_date')
                ->where('done', false)
                ->whereDate('due_date', '<=', $weekEnd)
                ->with('assignee')
                ->get()
                ->map(function (Task $t) use ($taskToProject, $projects) {
                    $project = $projects[$taskToProject[$t->id]];

                    return [
                        'title' => $t->title,
                        'subtitle' => $project->name,
                        'href' => "/m/projects/{$project->id}",
                        'icon' => 'folder-kanban',
                        'dueDate' => $t->due_date->toDateString(),
                        'toggleUrl' => "/m/projects/tasks/{$t->id}", 'toggleBody' => ['done' => true], // the dashboard tick sends this: set done, never flip
                        'kind' => 'project_task', // kind + id: get_today's widget ticks it off via the MCP tool
                        'id' => $t->id,
                        'assignee' => $t->assignee?->only(['id', 'name']), // Home's avatar chip on the row
                    ];
                })->values()->all();
        });

        // Calendar: a project's target start as a one-day marker (projects have no end date; the user chose
        // not to add one). Archived projects stay off the calendar.
        CalendarSources::register('projects', function (string $from, string $to, int $userId) {
            if (! app(ModuleRegistry::class)->isEnabled('projects', (int) CurrentFamily::id())) {
                return [];
            }

            return Project::query()->visibleTo($userId)->whereNull('archived_at')
                ->whereDate('target_start', '>=', $from)->whereDate('target_start', '<=', $to)->with('owner')->get()
                ->map(fn (Project $p) => CalendarSources::item('project', $p->id, $p->name, $p->target_start->toDateString(),
                    href: "/m/projects/{$p->id}", icon: 'folder-kanban', color: $p->color,
                    people: $p->owner ? [['type' => 'user', 'id' => $p->owner->id, 'name' => $p->owner->name]] : []))
                ->values()->all();
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

                Route::post('/{project}/spend-lists/new', [ProjectsController::class, 'createSpendList'])->name('spend.create');

                // Linked spending lists + contacts: {type} is a ProjectLinkables type.
                Route::get('/{project}/links/{type}/linkable', [ProjectsController::class, 'linkables'])
                    ->whereIn('type', ['spend_event', 'contact'])->name('links.linkable');
                Route::post('/{project}/links/{type}', [ProjectsController::class, 'linkItems'])
                    ->whereIn('type', ['spend_event', 'contact'])->name('links.store');
                Route::delete('/{project}/links/{type}/{id}', [ProjectsController::class, 'unlinkItem'])
                    ->whereIn('type', ['spend_event', 'contact'])->whereNumber('id')->name('links.destroy');
            });
    }
}
