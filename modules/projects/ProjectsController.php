<?php

namespace Modules\Projects;

use App\Events\ProjectsChanged;
use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Models\EntityVisit;
use App\Models\Family;
use App\Support\Media\StoredImage;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Notes\Note;
use Modules\Notifications\Notify;
use Modules\Shopping\ShoppingList;
use Modules\Spending\Currencies;
use Modules\Spending\SpendSetting;
use Modules\Tasks\Task;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectsController extends Controller
{
    public const STATUSES = ['planning', 'in_progress', 'blocked', 'done'];

    public const DEP_STATUSES = ['pending', 'blocked', 'done'];

    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $memberNames = Family::findOrFail(CurrentFamily::id())->members->pluck('name', 'id');

        $projects = Project::query()
            ->with(['shareSetting', 'shares'])
            ->orderBy('position')->latest()->get()
            ->filter(fn (Project $p) => $p->visibility() === 'family' || $p->shares->contains('user_id', $userId))
            ->values();

        // Card counts = the project page's: its to-dos (listless tasks-module tasks linked via `task` rows) plus their
        // sub-tasks, trashed ones excluded. Not Project::tasks(): that is the retired projects_tasks table, which still
        // held old rows and showed e.g. "0 of 3" on a project whose real to-dos differ. Two queries for all cards.
        $links = ProjectLink::whereIn('project_id', $projects->pluck('id'))->where('linkable_type', 'task')->get(['project_id', 'linkable_id']);
        $projectOf = $links->pluck('project_id', 'linkable_id');
        $tasksByProject = Task::where(fn ($q) => $q->whereIn('id', $projectOf->keys())->orWhereIn('parent_id', $projectOf->keys()))
            ->get(['id', 'parent_id', 'done', 'assigned_to'])
            ->groupBy(fn (Task $t) => $projectOf[$t->parent_id ?? $t->id] ?? null);

        return Inertia::render('projects/index', [
            'projects' => $projects->map(function (Project $p) use ($memberNames, $tasksByProject) {
                $tasks = $tasksByProject[$p->id] ?? collect();
                $done = $tasks->where('done', true)->count();
                $total = $tasks->count();
                $memberIds = $tasks->pluck('assigned_to')->push($p->owner_id)->filter()->unique()->values();

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'status' => $p->status,
                    'progress' => $p->progress,
                    'color' => $p->color,
                    'waitingOn' => $p->status === 'blocked' ? $p->waiting_on : null,
                    'done' => $done,
                    'total' => $total,
                    'archived' => $p->archived_at !== null,
                    'coverUrl' => $p->cover_path ? StoredImage::url('projects.cover.show', $p, $p->cover_path, thumb: true) : null,
                    'updated' => $p->updated_at?->diffForHumans(short: true),
                    'members' => $memberIds->map(fn ($id) => ['id' => $id, 'name' => $memberNames[$id] ?? '?'])->all(),
                ];
            })->values(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'color' => ['nullable', 'string', 'max:20'],
            'target_start' => ['nullable', 'date'],
        ]);

        $project = Project::createFor($request->user(), $data); // owner share + notification (shared with MCP)
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        if (! $project->shares()->where('role', 'owner')->exists()) {
            $project->shares()->firstOrCreate(['user_id' => $project->created_by], ['role' => 'owner']);
        }
        abort_unless($this->access->canView($request->user(), $project), 403);

        // Mark this project as "just opened" for the dashboard's Pick-up-where-you-left-off.
        EntityVisit::record('project', $project->id);

        $family = Family::findOrFail(CurrentFamily::id());
        $members = $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values();

        // Unified Tasks section: powered by the tasks module. A project's tasks are listless
        // tasks-module tasks (task_list_id null) linked via `task` rows; sub-tasks ride along.
        $taskIds = $project->links()->where('linkable_type', 'task')->pluck('linkable_id');
        $projectTasks = Task::whereIn('id', $taskIds)->whereNull('parent_id')
            ->with('subtasks')->orderBy('position')->orderBy('id')->get();

        // Linked shopping lists: show each with its bought/total progress.
        $listIds = $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id');
        $lists = ShoppingList::whereIn('id', $listIds)->withCount([
            'items', 'items as bought_count' => fn ($q) => $q->where('bought', true),
        ])->get();

        // Linked notes.
        $noteIds = $project->links()->where('linkable_type', 'note')->pluck('linkable_id');
        $notes = Note::whereIn('id', $noteIds)->get();

        return Inertia::render('projects/project', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'progress' => $project->progress,
                'color' => $project->color,
                'ownerId' => $project->owner_id,
                'ownerName' => $project->owner?->name,
                'targetStart' => $project->target_start?->toDateString(),
                'waitingOn' => $project->waiting_on,
                'archived' => $project->archived_at !== null,
                'visibility' => $project->visibility(),
                'updated' => $project->updated_at?->diffForHumans(short: true),
                'coverUrl' => $project->cover_path ? StoredImage::url('projects.cover.show', $project, $project->cover_path) : null,
            ],
            'share' => $this->shareState($project, $family),
            'members' => $members,
            'tasks' => $projectTasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'note' => $t->note,
                'done' => $t->done,
                'dueDate' => optional($t->due_date)->toDateString(),
                'assignedTo' => $t->assigned_to,
                'subtasks' => $t->subtasks->map(fn (Task $s) => [
                    'id' => $s->id, 'title' => $s->title, 'note' => $s->note, 'done' => $s->done,
                    'dueDate' => optional($s->due_date)->toDateString(), 'assignedTo' => $s->assigned_to,
                ])->values(),
            ])->values(),
            'dependencies' => $project->dependencies()->orderBy('position')->orderBy('id')->get()
                ->map(fn (ProjectDependency $d) => ['id' => $d->id, 'name' => $d->name, 'note' => $d->note, 'status' => $d->status])->values(),
            'lists' => $lists->map(fn (ShoppingList $l) => [
                'id' => $l->id, 'name' => $l->name, 'store' => $l->store, 'bought' => $l->bought_count, 'total' => $l->items_count,
            ])->values(),
            // Linked spending lists + contacts (ProjectLinkables). null when that module is off for the
            // family, so the page hides the section and its "add" entries.
            'spendLists' => ProjectLinkables::linked($project, $request->user(), ProjectLinkables::SPEND),
            'contacts' => ProjectLinkables::linked($project, $request->user(), ProjectLinkables::CONTACT),
            'notes' => $notes->map(fn (Note $n) => [
                'id' => $n->id,
                'title' => $n->title !== '' ? $n->title : 'Untitled note',
                'body' => Str::limit($n->body, 160),
                'color' => $n->color,
            ])->values(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateProject(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'color' => ['sometimes', 'string', 'max:20'],
            'owner_id' => ['sometimes', 'nullable', 'integer'],
            'target_start' => ['sometimes', 'nullable', 'date'],
            'waiting_on' => ['sometimes', 'nullable', 'string', 'max:255'],
            'archived' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'in:family,invited,private'],
        ]);

        $project->updateFrom($request->user(), $data);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyProject(Request $request, Project $project): RedirectResponse
    {
        $project->deleteFor($request->user()); // creator-only + notify + cover cleanup (shared with MCP)
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return to_route('projects.index');
    }

    public function duplicateProject(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $project), 403);
        $copy = $project->replicate(['archived_at']);
        $copy->name = $project->name.' (copy)';
        $copy->created_by = $request->user()->id;
        $copy->owner_id = $request->user()->id;
        $copy->save();
        $copy->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);

        // Copy the project's tasks (listless tasks-module tasks + their sub-tasks, reset to not-done)
        // and re-link the top-level ones to the copy.
        $taskIds = $project->links()->where('linkable_type', 'task')->pluck('linkable_id');
        $tops = Task::whereIn('id', $taskIds)->whereNull('parent_id')->with('subtasks')->orderBy('position')->get();
        foreach ($tops as $t) {
            $newTop = Task::create([
                'task_list_id' => null, 'title' => $t->title, 'note' => $t->note, 'due_date' => $t->due_date,
                'assigned_to' => $t->assigned_to, 'position' => $t->position, 'created_by' => $request->user()->id,
            ]);
            $copy->links()->create(['linkable_type' => 'task', 'linkable_id' => $newTop->id]);
            foreach ($t->subtasks as $s) {
                Task::create([
                    'task_list_id' => null, 'parent_id' => $newTop->id, 'title' => $s->title, 'note' => $s->note,
                    'due_date' => $s->due_date, 'assigned_to' => $s->assigned_to, 'position' => $s->position, 'created_by' => $request->user()->id,
                ]);
            }
        }
        foreach ($project->dependencies as $d) {
            $copy->dependencies()->create(['name' => $d->name, 'note' => $d->note, 'status' => 'pending', 'position' => $d->position, 'created_by' => $request->user()->id]);
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return to_route('projects.show', $copy);
    }

    // ---- Cover photo (R2, same pattern as spend receipts) ----
    public function uploadCover(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],   // full image, up to 12 MB (client compresses first)
            'photo_thumb' => ['nullable', 'image', 'max:4096'], // small client-generated variant for cards
        ]);

        StoredImage::delete($project->cover_path);
        $project->update([
            'cover_path' => StoredImage::store($request->file('photo'), $request->file('photo_thumb'), 'projects/'.CurrentFamily::id()),
        ]);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function coverFile(Request $request, Project $project): StreamedResponse
    {
        abort_unless($this->access->canView($request->user(), $project), 403);
        abort_unless($project->cover_path, 404);

        // ?v=thumb serves the small variant (used by list cards); default serves the full image.
        return StoredImage::response($project->cover_path, $request->query('v'));
    }

    public function deleteCover(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        StoredImage::delete($project->cover_path);
        $project->update(['cover_path' => null]);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // ---- Tasks (unified: tasks-module tasks living directly in the project, task_list_id null) ----

    // Resolve the project a tasks-module task belongs to (via its top-level `task` link) and guard
    // that the caller may manage it. Route-model binding runs before the family scope is set, so we
    // check family_id explicitly. A sub-task inherits its parent's project link.
    private function projectForTask(Request $request, Task $task): Project
    {
        abort_unless((int) $task->family_id === (int) CurrentFamily::id(), 404);
        $project = Project::forTask($task);
        abort_unless($project, 404);
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);

        return $project;
    }

    public function storeTask(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        // Parent check (one level deep), position, project link and notification live on the model (shared with MCP).
        $project->addTask($request->user(), $data);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // Drag-and-drop reorder + re-nest for a project's tasks. Tasks in new visual order, each with its
    // parent (null = top level). Positions re-numbered per group; project links reconciled so top-level
    // tasks stay linked and nested tasks (reached via their parent) do not.
    public function reorderTasks(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate([
            'order' => ['present', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.parent_id' => ['nullable', 'integer'],
        ]);

        // The tasks that belong to this project: its linked top-level tasks plus their sub-tasks.
        $topIds = $project->links()->where('linkable_type', 'task')->pluck('linkable_id');
        $tasks = Task::where(fn ($q) => $q->whereIn('id', $topIds)->orWhereIn('parent_id', $topIds))->get()->keyBy('id');

        $ids = collect($data['order'])->pluck('id');
        abort_unless($ids->every(fn ($id) => $tasks->has($id)), 422);

        $parentIds = collect($data['order'])->pluck('parent_id')->filter()->unique();
        foreach ($data['order'] as $row) {
            if ($row['parent_id'] !== null) {
                abort_unless($tasks->has($row['parent_id']), 422);
                abort_if($parentIds->contains($row['id']), 422, 'Only one level of nesting is allowed.');
            }
        }

        $counters = [];
        foreach ($data['order'] as $row) {
            $key = $row['parent_id'] ?? 'root';
            $pos = $counters[$key] = ($counters[$key] ?? -1) + 1;
            $tasks[$row['id']]->update(['parent_id' => $row['parent_id'], 'position' => $pos]);

            if ($row['parent_id'] === null) {
                $project->links()->firstOrCreate(['linkable_type' => 'task', 'linkable_id' => $row['id']]);
            } else {
                $project->links()->where(['linkable_type' => 'task', 'linkable_id' => $row['id']])->delete();
            }
        }

        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        $project = $this->projectForTask($request, $task);

        // An empty body toggles done (the dashboard agenda's tick); same notifications either way.
        $data = $request->hasAny(['title', 'note', 'due_date', 'assigned_to', 'done'])
            ? $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'note' => ['sometimes', 'nullable', 'string', 'max:255'],
                'due_date' => ['sometimes', 'nullable', 'date'],
                'assigned_to' => ['sometimes', 'nullable', 'integer'],
                'done' => ['sometimes', 'boolean'],
            ])
            : ['done' => ! $task->done];
        $project->updateTask($request->user(), $task, $data);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyTask(Request $request, Task $task): RedirectResponse
    {
        $project = $this->projectForTask($request, $task);
        $project->deleteTask($request->user(), $task); // unlink + trash + notify (shared with MCP)
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // ---- Dependencies (blocked by) ----
    public function storeDependency(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:'.implode(',', self::DEP_STATUSES)],
        ]);
        $project->dependencies()->create([
            'name' => $data['name'], 'note' => $data['note'] ?? null, 'status' => $data['status'] ?? 'pending',
            'position' => (int) $project->dependencies()->max('position') + 1, 'created_by' => $request->user()->id,
        ]);
        Notify::toAccessOf($project, 'projects', $request->user()->id, 'Blocker added', "{$request->user()->name} flagged: {$data['name']}", route('projects.show', $project, false));
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateDependency(Request $request, ProjectDependency $dependency): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $dependency->project) !== null, 403);
        $dependency->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:'.implode(',', self::DEP_STATUSES)],
        ]));
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyDependency(Request $request, ProjectDependency $dependency): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $dependency->project) !== null, 403);
        $project = $dependency->project;
        $name = $dependency->name;
        $dependency->delete();

        Notify::toAccessOf($project, 'projects', $request->user()->id, $project->name, "{$request->user()->name} removed blocker: {$name}", route('projects.show', $project, false));
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // ---- Linked shopping lists ----
    public function linkList(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate(['list_ids' => ['required', 'array'], 'list_ids.*' => ['integer']]);
        foreach ($data['list_ids'] as $id) {
            // Family scope on both models keeps cross-family links out.
            if (ShoppingList::whereKey($id)->exists()) {
                $project->links()->firstOrCreate(['linkable_type' => 'shopping_list', 'linkable_id' => $id]);
            }
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // Create a brand-new shopping list (optionally seeded with items) and link it to the project.
    public function createList(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'string', 'max:60'],
        ]);

        $list = ShoppingList::create(['name' => $data['name'], 'color' => 'green', 'icon' => 'cart', 'created_by' => $request->user()->id]);
        $list->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);
        foreach ($data['items'] ?? [] as $i => $item) {
            $list->items()->create(['name' => $item['name'], 'quantity' => $item['quantity'] ?? null, 'position' => $i, 'created_by' => $request->user()->id]);
        }
        $project->links()->firstOrCreate(['linkable_type' => 'shopping_list', 'linkable_id' => $list->id]);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function unlinkList(Request $request, Project $project, int $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $project->links()->where(['linkable_type' => 'shopping_list', 'linkable_id' => $list])->delete();
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // Shopping lists the family has, for the "link a list" picker (excludes already-linked ones).
    // Each carries its item count and a short "shared with" label for the row subtitle.
    public function linkableLists(Request $request, Project $project)
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $linked = $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id')->all();
        $memberNames = Family::findOrFail(CurrentFamily::id())->members->pluck('name', 'id');

        $lists = ShoppingList::whereNull('archived_at')->whereNotIn('id', $linked)
            ->withCount('items')->with(['shareSetting', 'shares'])->latest()->get();

        return response()->json([
            'lists' => $lists->map(function (ShoppingList $l) use ($memberNames) {
                $vis = $l->visibility();
                $sharedWith = $vis === 'private'
                    ? 'only you'
                    : ($vis === 'family'
                        ? 'shared with family'
                        : 'shared with '.$l->shares->where('role', '!=', 'owner')->pluck('user_id')->map(fn ($id) => $memberNames[$id] ?? '?')->join(', '));

                return ['id' => $l->id, 'name' => $l->name, 'store' => $l->store, 'count' => $l->items_count, 'sharedWith' => $sharedWith];
            })->values(),
        ]);
    }

    // ---- Linked notes ----
    public function linkNote(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate(['note_ids' => ['required', 'array'], 'note_ids.*' => ['integer']]);
        foreach ($data['note_ids'] as $id) {
            // Family scope keeps cross-family links out.
            if (Note::whereKey($id)->exists()) {
                $project->links()->firstOrCreate(['linkable_type' => 'note', 'linkable_id' => $id]);
            }
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // Create a blank note, link it, and drop the user straight into its editor to write.
    public function createNote(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:255']]);

        $note = Note::create(['title' => $data['title'] ?? '', 'created_by' => $request->user()->id]);
        $note->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);
        $project->links()->firstOrCreate(['linkable_type' => 'note', 'linkable_id' => $note->id]);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return to_route('notes.show', $note);
    }

    public function unlinkNote(Request $request, Project $project, int $note): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $project->links()->where(['linkable_type' => 'note', 'linkable_id' => $note])->delete();
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // Notes the family has, for the "link a note" picker (excludes already-linked ones).
    public function linkableNotes(Request $request, Project $project)
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $linked = $project->links()->where('linkable_type', 'note')->pluck('linkable_id')->all();
        $userId = $request->user()->id;

        // Only notes this user can see (family-visible, or explicitly shared with them).
        $notes = Note::whereNotIn('id', $linked)->with(['shareSetting', 'shares'])->latest('updated_at')->get()
            ->filter(fn (Note $n) => $n->visibility() === 'family' || $n->shares->contains('user_id', $userId))
            ->values();

        return response()->json([
            'notes' => $notes->map(fn (Note $n) => [
                'id' => $n->id,
                'name' => $n->title !== '' ? $n->title : 'Untitled note',
                'preview' => Str::limit(trim(preg_replace('/\s+/', ' ', $n->body)), 60),
                'sharedWith' => $n->visibility() === 'family' ? 'shared with family' : ($n->visibility() === 'private' ? 'only you' : 'shared'),
            ])->values(),
        ]);
    }

    // ---- Linked spending lists + contacts ({type} = spend_event | contact, see ProjectLinkables) ----
    // Same shape as the shopping-list/note endpoints, but one set for both types: the rules (module
    // enabled, visibility) live in ProjectLinkables so the MCP tools apply exactly the same ones.
    public function linkables(Request $request, Project $project, string $type)
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        abort_unless(ProjectLinkables::enabled($type), 404);

        return response()->json(['items' => ProjectLinkables::candidates($project, $request->user(), $type)]);
    }

    public function linkItems(Request $request, Project $project, string $type): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        abort_unless(ProjectLinkables::enabled($type), 404);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        foreach ($data['ids'] as $id) {
            ProjectLinkables::link($project, $request->user(), $type, (int) $id); // silently skips what the user can't see
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // "New expense list for this project": a spending list named after the project (editable in the
    // sheet), linked in the same step and kept out of monthly spending by default (ProjectLinkables).
    public function createSpendList(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        abort_unless(ProjectLinkables::enabled(ProjectLinkables::SPEND), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        ProjectLinkables::createSpendList($project, $request->user(), $data['name'],
            SpendSetting::defaultCurrency() ?? Currencies::FALLBACK);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Removes the link only; the spending list / contact itself is untouched.
    public function unlinkItem(Request $request, Project $project, string $type, int $id): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        ProjectLinkables::unlink($project, $type, $id);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    private function shareState(Project $project, Family $family): array
    {
        $visibility = $project->visibility();
        $sharesByUser = $project->shares->keyBy('user_id');
        $link = $project->shareLinks()->first();

        return [
            'type' => 'project',
            'id' => $project->id,
            'visibility' => $visibility,
            'canManage' => $this->access->canManage(request()->user(), $project),
            'members' => $family->members->map(function ($m) use ($sharesByUser, $visibility) {
                $explicit = $sharesByUser->get($m->id)?->role;

                return ['id' => $m->id, 'name' => $m->name, 'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null), 'isOwner' => $explicit === 'owner'];
            })->values(),
            'link' => $link ? ['id' => $link->id, 'token' => $link->token, 'url' => route('share.public', $link->token)] : null,
        ];
    }
}
