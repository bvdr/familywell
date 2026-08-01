<?php

namespace Modules\Tasks;

use App\Events\TasksChanged;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyNotifier;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TasksController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $lists = TaskList::query()
            ->withCount([
                'tasks',
                'tasks as done_count' => fn ($q) => $q->where('done', true),
            ])
            ->with(['shareSetting', 'shares', 'tasks:id,task_list_id,assigned_to'])
            ->orderBy('position')
            ->latest()
            ->get()
            // BelongsToFamily already limits to the current family; `family`-visibility lists are
            // visible to every member, `invited`/`private` need an explicit share row.
            ->filter(fn (TaskList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
            ->values();

        $memberNames = Family::findOrFail(CurrentFamily::id())->members->pluck('name', 'id');

        return Inertia::render('tasks/lists', [
            'lists' => $lists->map(fn (TaskList $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'color' => $l->color,
                'icon' => $l->icon,
                'taskCount' => $l->tasks_count,
                'doneCount' => $l->done_count,
                'archived' => $l->archived_at !== null,
                'visibility' => $l->visibility(),
                'members' => $l->tasks->pluck('assigned_to')->filter()->unique()->values()
                    ->map(fn ($id) => ['id' => $id, 'name' => $memberNames[$id] ?? '?'])->all(),
            ])->values(),
        ]);
    }

    // Build the ShareState the core ShareSheet expects (visibility, per-member roles, public link).
    private function shareState(TaskList $list, Family $family): array
    {
        $visibility = $list->visibility();
        $sharesByUser = $list->shares->keyBy('user_id');
        $link = $list->shareLinks()->first();

        return [
            'type' => 'task_list',
            'id' => $list->id,
            'visibility' => $visibility,
            'canManage' => $this->access->canManage(request()->user(), $list),
            'members' => $family->members->map(function ($m) use ($sharesByUser, $visibility) {
                $explicit = $sharesByUser->get($m->id)?->role;

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null),
                    'isOwner' => $explicit === 'owner',
                ];
            })->values(),
            'link' => $link ? ['id' => $link->id, 'token' => $link->token, 'url' => route('share.public', $link->token)] : null,
        ];
    }

    public function storeList(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:20'],
            'visibility' => ['nullable', 'in:family,invited,private'],
        ]);

        $list = TaskList::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? 'green',
            'icon' => $data['icon'] ?? 'list',
            'created_by' => $request->user()->id,
        ]);

        // Creator owns the share (can manage visibility/roles); defaults to `family` visibility.
        $list->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);
        if (! empty($data['visibility'])) {
            $list->setVisibility($data['visibility']);
        }

        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return to_route('tasks.show', $list);
    }

    public function show(Request $request, TaskList $list): Response
    {
        // Self-heal lists created before an owner share existed. Idempotent.
        if (! $list->shares()->where('role', 'owner')->exists()) {
            $list->shares()->firstOrCreate(['user_id' => $list->created_by], ['role' => 'owner']);
        }

        abort_unless($this->access->canView($request->user(), $list), 403);

        $family = Family::findOrFail(CurrentFamily::id());
        $members = $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values();

        // Top-level tasks with their (single level of) sub-tasks.
        $tasks = $list->tasks()->whereNull('parent_id')
            ->with('subtasks')
            ->orderBy('position')->orderBy('id')->get();

        return Inertia::render('tasks/list', [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'color' => $list->color,
                'icon' => $list->icon,
                'archived' => $list->archived_at !== null,
                'visibility' => $list->visibility(),
            ],
            'share' => $this->shareState($list, $family),
            'members' => $members,
            'tasks' => $tasks->map(fn (Task $t) => $this->taskPayload($t))->values(),
        ]);
    }

    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'done' => $task->done,
            'dueDate' => optional($task->due_date)->toDateString(),
            'assignedTo' => $task->assigned_to,
            'subtasks' => $task->subtasks->map(fn (Task $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'note' => $s->note,
                'done' => $s->done,
                'dueDate' => optional($s->due_date)->toDateString(),
                'assignedTo' => $s->assigned_to,
            ])->values(),
        ];
    }

    public function updateList(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:20'],
            'icon' => ['sometimes', 'string', 'max:20'],
            'archived' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'in:family,invited,private'],
        ]);

        if (array_key_exists('archived', $data)) {
            $list->archived_at = $data['archived'] ? now() : null;
        }
        $list->fill(array_intersect_key($data, array_flip(['name', 'color', 'icon'])))->save();

        if (array_key_exists('visibility', $data)) {
            $list->setVisibility($data['visibility']);
        }

        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return back();
    }

    // Copy a list and its tasks (sub-tasks included, all reset to not-done) into a fresh owned list.
    public function duplicateList(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $list), 403);

        $copy = TaskList::create([
            'name' => $list->name.' (copy)',
            'color' => $list->color,
            'icon' => $list->icon,
            'created_by' => $request->user()->id,
        ]);
        $copy->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);

        foreach ($list->tasks()->whereNull('parent_id')->orderBy('position')->get() as $top) {
            $newTop = $copy->tasks()->create([
                'title' => $top->title,
                'note' => $top->note,
                'due_date' => $top->due_date,
                'assigned_to' => $top->assigned_to,
                'position' => $top->position,
                'created_by' => $request->user()->id,
            ]);
            foreach ($top->subtasks as $sub) {
                $copy->tasks()->create([
                    'parent_id' => $newTop->id,
                    'title' => $sub->title,
                    'note' => $sub->note,
                    'due_date' => $sub->due_date,
                    'assigned_to' => $sub->assigned_to,
                    'position' => $sub->position,
                    'created_by' => $request->user()->id,
                ]);
            }
        }

        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return to_route('tasks.show', $copy);
    }

    public function destroyList(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($list->created_by === $request->user()->id, 403);
        $list->delete();
        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return to_route('tasks.index');
    }

    public function storeTask(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        // Sub-task: the parent must live in this list and be top-level (one level deep only).
        if (! empty($data['parent_id'])) {
            $parent = $list->tasks()->findOrFail($data['parent_id']);
            abort_if($parent->parent_id !== null, 422, 'Sub-tasks cannot have sub-tasks.');
        }

        $list->tasks()->create([
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'position' => (int) $list->tasks()->where('parent_id', $data['parent_id'] ?? null)->max('position') + 1,
            'created_by' => $request->user()->id,
        ]);

        FamilyNotifier::toAccessOf($list, 'tasks', $request->user()->id, 'New task', "{$request->user()->name} added: {$data['title']}", route('tasks.show', $list, false));
        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return back();
    }

    // Drag-and-drop reorder + re-nest. Accepts the tasks in their new visual order, each with the
    // parent it now belongs to (null = top level). Positions are re-numbered per parent group.
    public function reorderTasks(Request $request, TaskList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $data = $request->validate([
            'order' => ['present', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.parent_id' => ['nullable', 'integer'],
        ]);

        $ids = collect($data['order'])->pluck('id')->all();
        $tasks = $list->tasks()->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($tasks->count() === count($ids), 422);

        // Enforce a single level of nesting: an id used as someone's parent may not itself be nested.
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
        }

        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        // Only list tasks are managed here; project (listless) tasks go through the projects module.
        abort_unless($task->list && $this->access->roleFor($request->user(), $task->list) !== null, 403);

        $actor = $request->user();
        $url = route('tasks.show', $task->list, false);

        // No body => a plain check/uncheck toggle (the common case, one tap in the list).
        if (! $request->hasAny(['title', 'note', 'due_date', 'assigned_to', 'done'])) {
            $task->update(['done' => ! $task->done]);
            if ($task->done) {
                FamilyNotifier::toAccessOf($task->list, 'tasks', $actor->id, 'Task done', "{$actor->name} completed: {$task->title}", $url);
            }
        } else {
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'note' => ['sometimes', 'nullable', 'string', 'max:255'],
                'due_date' => ['sometimes', 'nullable', 'date'],
                'assigned_to' => ['sometimes', 'nullable', 'integer'],
                'done' => ['sometimes', 'boolean'],
            ]);
            $before = $task->assigned_to;
            $wasDone = $task->done;
            $task->update($data);
            // Ping the new assignee only on a genuine (re)assignment.
            if (array_key_exists('assigned_to', $data) && $data['assigned_to'] && $data['assigned_to'] !== $before) {
                FamilyNotifier::push(User::whereKey($data['assigned_to'])->get(), 'tasks', $actor->id, 'Assigned to you', "{$actor->name} assigned you: {$task->title}", $url);
            }
            if (array_key_exists('done', $data) && $data['done'] && ! $wasDone) {
                FamilyNotifier::toAccessOf($task->list, 'tasks', $actor->id, 'Task done', "{$actor->name} completed: {$task->title}", $url);
            }
        }

        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyTask(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->list && $this->access->roleFor($request->user(), $task->list) !== null, 403);
        $task->delete(); // cascades to sub-tasks
        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));

        return back();
    }
}
