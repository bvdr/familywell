<?php

namespace Modules\ImportantTodos;

use App\Models\User;
use App\Support\Dashboard\HomeLayout;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Tasks\Task;

/**
 * Home's "Your important" card (design H1 / H1a / H1b): every person's open important to-dos, yours pinned on top.
 *
 * Ownership: an important to-do belongs to its assignee (that's who has to do it). One nobody is assigned to belongs
 * to whoever starred it (the person who said "this matters"), and a flag from before we recorded that to the task's
 * creator. The card's grouping, the 3-open limit and lifting yours out of the agenda all use this one rule (OWNER).
 *
 * Scope: to-dos in a to-do list (task_list_id set), the same ones Home's agenda shows. Project tasks live on their
 * project page and aren't on Home's agenda either, so they're left out of the card and of the limit.
 */
class ImportantCard
{
    public const LIMIT = 3;

    /** The owner of a flagged task, as SQL over tasks_tasks joined with important_todos_flags. */
    private const OWNER = 'COALESCE(tasks_tasks.assigned_to, important_todos_flags.starred_by, tasks_tasks.created_by)';

    /** Open, starred to-dos in live lists, each with its owner_id (select). Family-scoped by the Task tenant scope. */
    private static function open(): Builder
    {
        return Task::query()
            ->join('important_todos_flags', 'important_todos_flags.task_id', '=', 'tasks_tasks.id')
            ->where('important_todos_flags.important', true)
            ->where('tasks_tasks.done', false)
            ->whereNotNull('tasks_tasks.task_list_id')
            ->select('tasks_tasks.*')
            ->selectRaw(self::OWNER.' as owner_id');
    }

    /**
     * The card for the signed-in person, or null when it has nothing (or the module/section is off). Memoized on the
     * request: the agenda (dashboard.lifted) and the widget both ask for it on one Home load.
     *
     * @return array{mine: list<array>, others: list<array{owner: array{id: int, name: string}, items: list<array>}>}|null
     */
    public static function forViewer(): ?array
    {
        $attrs = request()->attributes;
        if (! $attrs->has('important_todos.card')) {
            $attrs->set('important_todos.card', self::build());
        }

        return $attrs->get('important_todos.card');
    }

    private static function build(): ?array
    {
        $user = auth()->user();
        $familyId = CurrentFamily::id();
        if (! $user || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('important_todos', (int) $familyId)
            || ! in_array('important', HomeLayout::visible($user, (int) $familyId), true)) {
            return null; // hidden in Settings > Home dashboard: no card, and nothing is lifted out of the agenda
        }

        // Only lists this person can see (the agenda's rule), and not archived ones (read-only, can't be ticked).
        $tasks = self::open()
            ->whereHas('list', fn ($q) => $q->visibleTo($user->id)->whereNull('archived_at'))
            ->with('list:id,name')
            // Most urgent first: overdue and dated by day (oldest first), undated last, then oldest star first.
            ->orderByRaw('CASE WHEN tasks_tasks.due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tasks_tasks.due_date')
            ->orderBy('tasks_tasks.id')
            ->get();
        if ($tasks->isEmpty()) {
            return null; // nobody starred anything: the card is hidden
        }

        $names = User::whereIn('id', $tasks->pluck('owner_id')->unique())->pluck('name', 'id');
        // Up to LIMIT per person: someone can end up with more (a starred to-do assigned to them), the rest wait.
        $groups = $tasks->groupBy('owner_id')->map(fn (Collection $g) => $g->take(self::LIMIT));
        $row = fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'list' => $t->list->name,
            'href' => "/m/tasks/lists/{$t->task_list_id}",
            'dueDate' => $t->due_date?->toDateString(),
            'toggleUrl' => "/m/tasks/tasks/{$t->id}", // the agenda's tick endpoint (body {done: true})
            'owner' => ['id' => (int) $t->owner_id, 'name' => $names[$t->owner_id] ?? '?'],
        ];

        return [
            'mine' => $groups->get($user->id, collect())->map($row)->values()->all(),
            // Grouped per person, the one with the most urgent to-do first (groupBy keeps the urgency order).
            'others' => $groups->except($user->id)->map(fn (Collection $g, $ownerId) => [
                'owner' => ['id' => (int) $ownerId, 'name' => $names[$ownerId] ?? '?'],
                'items' => $g->map($row)->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Refuse a 4th open star for one person. Runs on `task.saved` before the flag is written; the owner the task
     * WILL have is its assignee, else the person starring it. Throws a translated 422 naming who is full.
     */
    public static function assertRoomFor(Task $task, User $actor): void
    {
        if ($task->done || $task->task_list_id === null) {
            return; // only open list to-dos count (see the class note)
        }
        $ownerId = $task->assigned_to ?? $actor->id;
        $count = self::open()->where('tasks_tasks.id', '!=', $task->id)->whereRaw(self::OWNER.' = ?', [$ownerId])->count();
        if ($count < self::LIMIT) {
            return;
        }
        $message = $ownerId === $actor->id
            ? Translations::get('important_todos.limit_you')
            : str_replace(':name', (string) User::whereKey($ownerId)->value('name'), Translations::get('important_todos.limit_them'));

        throw ValidationException::withMessages(['important' => str_replace(':limit', (string) self::LIMIT, $message)]);
    }
}
