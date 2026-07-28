<?php

namespace Modules\Projects;

use App\Events\ProjectsChanged;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Shopping\ShoppingList;
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
            ->with(['shareSetting', 'shares', 'tasks:id,project_id,assigned_to,done'])
            ->orderBy('position')->latest()->get()
            ->filter(fn (Project $p) => $p->visibility() === 'family' || $p->shares->contains('user_id', $userId))
            ->values();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(function (Project $p) use ($memberNames) {
                $done = $p->tasks->where('done', true)->count();
                $total = $p->tasks->count();
                $memberIds = $p->tasks->pluck('assigned_to')->push($p->owner_id)->filter()->unique()->values();

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

        $project = Project::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'planning',
            'progress' => $data['progress'] ?? 0,
            'color' => $data['color'] ?? 'green',
            'owner_id' => $request->user()->id,
            'target_start' => $data['target_start'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $project->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);

        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        if (! $project->shares()->where('role', 'owner')->exists()) {
            $project->shares()->firstOrCreate(['user_id' => $project->created_by], ['role' => 'owner']);
        }
        abort_unless($this->access->canView($request->user(), $project), 403);

        $family = Family::findOrFail(CurrentFamily::id());
        $members = $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values();

        $tasks = $project->tasks()->orderBy('position')->orderBy('id')->get();

        // Linked shopping lists (v1's only linkable type): show each with its bought/total progress.
        $listIds = $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id');
        $lists = ShoppingList::whereIn('id', $listIds)->withCount([
            'items', 'items as bought_count' => fn ($q) => $q->where('bought', true),
        ])->get();

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
                'coverUrl' => $project->cover_path ? route('projects.cover.show', $project) : null,
            ],
            'share' => $this->shareState($project, $family),
            'members' => $members,
            'tasks' => $tasks->map(fn (ProjectTask $t) => [
                'id' => $t->id, 'name' => $t->name, 'done' => $t->done, 'assignedTo' => $t->assigned_to,
            ])->values(),
            'dependencies' => $project->dependencies()->orderBy('position')->orderBy('id')->get()
                ->map(fn (ProjectDependency $d) => ['id' => $d->id, 'name' => $d->name, 'note' => $d->note, 'status' => $d->status])->values(),
            'lists' => $lists->map(fn (ShoppingList $l) => [
                'id' => $l->id, 'name' => $l->name, 'store' => $l->store, 'bought' => $l->bought_count, 'total' => $l->items_count,
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

        if (array_key_exists('archived', $data)) {
            $project->archived_at = $data['archived'] ? now() : null;
        }
        $project->fill(array_intersect_key($data, array_flip(['name', 'description', 'status', 'progress', 'color', 'owner_id', 'target_start', 'waiting_on'])))->save();
        if (array_key_exists('visibility', $data)) {
            $project->setVisibility($data['visibility']);
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyProject(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->created_by === $request->user()->id, 403);
        $project->delete();
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
        foreach ($project->tasks as $t) {
            $copy->tasks()->create(['name' => $t->name, 'assigned_to' => $t->assigned_to, 'done' => false, 'position' => $t->position, 'created_by' => $request->user()->id]);
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
        $request->validate(['photo' => ['required', 'image', 'max:12288']]); // up to 12 MB (client compresses first)

        if ($project->cover_path) {
            Storage::disk('r2')->delete($project->cover_path);
        }
        $project->update(['cover_path' => $request->file('photo')->store('projects/'.CurrentFamily::id(), 'r2')]);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function coverFile(Request $request, Project $project): StreamedResponse
    {
        abort_unless($this->access->canView($request->user(), $project), 403);
        abort_unless($project->cover_path, 404);

        return Storage::disk('r2')->response($project->cover_path);
    }

    public function deleteCover(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        if ($project->cover_path) {
            Storage::disk('r2')->delete($project->cover_path);
            $project->update(['cover_path' => null]);
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // ---- Tasks ----
    public function storeTask(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'assigned_to' => ['nullable', 'integer']]);
        $project->tasks()->create([
            'name' => $data['name'], 'assigned_to' => $data['assigned_to'] ?? null,
            'position' => (int) $project->tasks()->max('position') + 1, 'created_by' => $request->user()->id,
        ]);
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateTask(Request $request, ProjectTask $task): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $task->project) !== null, 403);
        if (! $request->hasAny(['name', 'assigned_to', 'done'])) {
            $task->update(['done' => ! $task->done]);
        } else {
            $task->update($request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'assigned_to' => ['sometimes', 'nullable', 'integer'],
                'done' => ['sometimes', 'boolean'],
            ]));
        }
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyTask(Request $request, ProjectTask $task): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $task->project) !== null, 403);
        $task->delete();
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
        $dependency->delete();
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

        return back();
    }

    // ---- Linked shopping lists ----
    public function linkList(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $data = $request->validate(['list_id' => ['required', 'integer']]);
        // Family scope on both models keeps cross-family links out.
        abort_unless(ShoppingList::whereKey($data['list_id'])->exists(), 404);
        $project->links()->firstOrCreate(['linkable_type' => 'shopping_list', 'linkable_id' => $data['list_id']]);
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
    public function linkableLists(Request $request, Project $project)
    {
        abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
        $linked = $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id')->all();

        return response()->json([
            'lists' => ShoppingList::whereNull('archived_at')->whereNotIn('id', $linked)->latest()->get()
                ->map(fn (ShoppingList $l) => ['id' => $l->id, 'name' => $l->name, 'store' => $l->store])->values(),
        ]);
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
