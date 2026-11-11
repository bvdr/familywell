<?php

namespace Modules\Calendar;

use App\Models\Family;
use App\Models\User;
use App\Support\Calendar\CalendarSources;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Contacts\Person;
use Modules\Notifications\Notify;
use Modules\Projects\Project;
use Modules\Tasks\Task;

/**
 * A calendar event: all-day or timed, single or multi-day, optionally repeating (expanded on read by
 * Occurrences). Linked to people (family members + contacts), to-dos (listless tasks, like project tasks) and
 * at most one project via calendar_event_links. Shared like other family things (Shareable).
 * Validation + link rules live here so the web UI and the MCP tools can't drift apart.
 */
class CalendarEvent extends Model
{
    use BelongsToFamily;
    use Shareable;
    use SoftDeletes; // 30-day trash (CalendarTrash, trash:purge)

    protected $table = 'calendar_events';

    protected $fillable = [
        'title', 'notes', 'icon', 'color', 'category', 'start_date', 'end_date', 'start_time', 'end_time',
        'repeat', 'repeat_interval', 'repeat_until', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'repeat_until' => 'date', 'repeat_interval' => 'integer'];

    protected $attributes = ['repeat' => 'none', 'repeat_interval' => 1];

    public const COLUMNS = ['title', 'notes', 'icon', 'color', 'category', 'start_date', 'end_date', 'start_time', 'end_time', 'repeat', 'repeat_interval', 'repeat_until'];

    protected static function booted(): void
    {
        // To-dos aren't a HasMany (they're linked rows), so CascadesTrash can't reach them: trash the event's
        // top-level tasks with the event's exact deleted_at, and explicitly update sub-tasks too — this is a
        // query-builder UPDATE, which fires no model events, so Task's own CascadesTrash (which only runs on
        // $model->delete()) never sees it and never cascades to them on its own. On restore, bring back the
        // ones that share that timestamp (ones trashed on their own stay trashed).
        static::softDeleted(function (self $e) {
            Task::whereIn('id', $e->taskIds())->update(['deleted_at' => $e->deleted_at]);
            Task::whereIn('parent_id', $e->taskIds())->update(['deleted_at' => $e->deleted_at]);
        });
        static::restoring(function (self $e) {
            $ids = $e->taskIds();
            Task::onlyTrashed()->where('deleted_at', $e->deleted_at)
                ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('parent_id', $ids))->update(['deleted_at' => null]);
        });
    }

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'event_id');
    }

    /** Marks on this event's days (by day index; see CalendarEventMark). Cascade-deleted with the event. */
    public function marks(): HasMany
    {
        return $this->hasMany(CalendarEventMark::class, 'event_id')->orderBy('id');
    }

    public function dayNotes(): HasMany
    {
        return $this->hasMany(CalendarEventDayNote::class, 'event_id');
    }

    /** Only events longer than one day have days to mark and write on. */
    public function isMultiDay(): bool
    {
        return $this->end_date->gt($this->start_date);
    }

    /**
     * The occurrence of this event that covers $day (Y-m-d), as [start, end]; the stored dates when $day is null or
     * falls in no occurrence. A repeating fast has one page, so a tap on next year's bar must open next year's days.
     *
     * @return array{0:string,1:string}
     */
    public function occurrenceAt(?string $day): array
    {
        $start = $this->start_date->toDateString();
        $end = $this->end_date->toDateString();
        if ($day) {
            $first = $this->occurrenceStartsOn($day)[0] ?? null;
            if ($first) {
                return [$first, CarbonImmutable::parse($first)->addDays((int) $this->start_date->diffInDays($this->end_date))->toDateString()];
            }
        }

        return [$start, $end];
    }

    /** Start dates of the occurrences covering $day (an event never overlaps itself, so 0 or 1). @return list<string> */
    private function occurrenceStartsOn(string $day): array
    {
        return Occurrences::starts($this->start_date->toDateString(), $this->end_date->toDateString(), $this->repeat, $this->repeat_interval, $this->repeat_until?->toDateString(), $day, $day);
    }

    /** Whether $day (Y-m-d) lies inside some occurrence: the only dates a day note can belong to. */
    public function coversDate(string $day): bool
    {
        return $this->occurrenceStartsOn($day) !== [];
    }

    /**
     * Write, change or (blank body) remove the note on $date, a day inside an occurrence (callers check coversDate).
     * Removing trashes it (30 days, CalendarTrash). The (event_id, date) unique index also counts trashed rows, so
     * a new note on that day revives the trashed row instead of inserting a second one.
     */
    public function setDayNote(string $date, ?string $body): void
    {
        $body = trim((string) $body);
        if ($body === '') {
            $this->dayNotes()->where('date', $date)->first()?->delete();

            return;
        }
        $note = $this->dayNotes()->withTrashed()->firstOrNew(['date' => $date]);
        $note->body = $body;
        $note->deleted_at = null;
        $note->save();
    }

    /** @return list<int> */
    public function taskIds(): array
    {
        return $this->links()->where('linkable_type', 'task')->pluck('linkable_id')->all();
    }

    /** The event a to-do belongs to (via its top-level task link; a sub-task inherits its parent's). */
    public static function forTask(Task $task): ?self
    {
        $link = CalendarEventLink::where(['linkable_type' => 'task', 'linkable_id' => $task->parent_id ?? $task->id])->first();

        return $link ? static::find($link->event_id) : null;
    }

    public function hasTopTask(int $taskId): bool
    {
        return in_array($taskId, $this->taskIds(), true) && Task::whereKey($taskId)->whereNull('parent_id')->exists();
    }

    /**
     * Add a to-do: a listless tasks-module task (task_list_id null), like project tasks, or a sub-task of one
     * of this event's top-level to-dos (one level deep, 404 otherwise). Only top-level tasks get the link.
     */
    public function addTask(User $user, array $data): Task
    {
        $parentId = $data['parent_id'] ?? null;
        abort_if($parentId && ! $this->hasTopTask((int) $parentId), 404);
        $task = Task::create([
            'task_list_id' => null,
            'parent_id' => $parentId,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'position' => (int) Task::whereNull('task_list_id')->where('parent_id', $parentId)->max('position') + 1,
            'created_by' => $user->id,
        ]);
        if (! $parentId) {
            $this->links()->firstOrCreate(['linkable_type' => 'task', 'linkable_id' => $task->id]);
        }
        if (! empty($data['assigned_to']) && (int) $data['assigned_to'] !== $user->id) {
            Notify::push(User::whereKey($data['assigned_to'])->get(), 'tasks', $user->id, 'Assigned to you', "{$user->name} assigned you: {$task->title}", "/m/calendar/events/{$this->id}");
        }

        return $task;
    }

    public function updateTask(User $actor, Task $task, array $data): void
    {
        $before = $task->assigned_to;
        $task->update($data);
        if (! empty($data['assigned_to']) && $data['assigned_to'] !== $before && (int) $data['assigned_to'] !== $actor->id) {
            Notify::push(User::whereKey($data['assigned_to'])->get(), 'tasks', $actor->id, 'Assigned to you', "{$actor->name} assigned you: {$task->title}", "/m/calendar/events/{$this->id}");
        }
    }

    /** Trash a to-do (its sub-tasks follow via Task's CascadesTrash); a top-level one loses its link. */
    /**
     * Move a to-do (and its sub-tasks) to the trash. Its link stays (reads go through Task, which hides
     * trashed rows) so CalendarTrash can list it and a restore puts it back on the event; the calendar
     * provider drops the link when trash:purge erases the task.
     */
    public function deleteTask(Task $task): void
    {
        $task->delete();
    }

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'icon' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
            // A built-in or one this family added (CalendarCategory); the list is per family, so it is read at validation time.
            'category' => ['nullable', Rule::in(CalendarCategory::slugs())],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'repeat' => ['sometimes', Rule::in(Occurrences::REPEATS)],
            'repeat_interval' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'repeat_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'people' => ['sometimes', 'array', 'max:50'],
            'people.*.type' => ['required', Rule::in(['user', 'person'])],
            'people.*.id' => ['required', 'integer'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Validate a FULL field set (updates merge onto formValues() first, so a PATCH of only end_date is still
     * checked against the stored start_date). Also checks that a same-day timed event ends after it starts,
     * and that people belong to this family.
     */
    public static function validated(array $input): array
    {
        $v = Validator::make($input, self::rules());
        $v->after(function ($v) use ($input) {
            if (! empty($input['start_time']) && ! empty($input['end_time'])
                && ($input['start_date'] ?? null) === ($input['end_date'] ?? null) && $input['end_time'] <= $input['start_time']) {
                $v->errors()->add('end_time', 'The end time must be after the start time.');
            }
            if (! empty($input['people']) && is_array($input['people']) && ! self::peopleBelongHere($input['people'])) {
                $v->errors()->add('people', 'Pick people from this family.');
            }
        });

        return $v->validate();
    }

    private static function peopleBelongHere(array $people): bool
    {
        $col = collect($people);
        $userIds = $col->where('type', 'user')->pluck('id')->map(fn ($id) => (int) $id)->unique();
        $personIds = $col->where('type', 'person')->pluck('id')->map(fn ($id) => (int) $id)->unique();
        $family = Family::find(CurrentFamily::id());

        return $family
            && $family->members()->whereIn('users.id', $userIds)->count() === $userIds->count()
            && Person::whereIn('id', $personIds)->count() === $personIds->count(); // Person is family-scoped
    }

    /** Column values as validator input (dates/times as the strings the rules expect). */
    public function formValues(): array
    {
        return [
            'title' => $this->title, 'notes' => $this->notes, 'icon' => $this->icon, 'color' => $this->color, 'category' => $this->category,
            'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(),
            'start_time' => $this->start_time, 'end_time' => $this->end_time, 'repeat' => $this->repeat,
            'repeat_interval' => $this->repeat_interval, 'repeat_until' => $this->repeat_until?->toDateString(),
        ];
    }

    /** Create an event owned by $user (owner share; family-visible by default). $data = validated(). */
    public static function createFor(User $user, array $data): self
    {
        $event = static::create(self::onlyColumns($data) + ['created_by' => $user->id]);
        $event->shares()->create(['user_id' => $user->id, 'role' => 'owner']);
        $event->syncLinks($data);
        // Push to everyone who can see it (the "Calendar changes" category), never the person who made it.
        Notify::toAccessOf($event, 'calendar', $user->id, 'Calendar', "{$user->name} added {$event->title} on {$event->start_date->format('D j M')}", $event->url());

        return $event;
    }

    /**
     * Partial update: merge onto current values, validate the whole, save, sync links that were sent. Shared by the
     * web app and MCP, so this is where a change is announced: moved (date / time) or renamed, by $actor.
     */
    public function applyUpdate(array $input, ?User $actor = null): void
    {
        $data = self::validated(array_merge($this->formValues(), $input));
        $this->update(self::onlyColumns($data));
        $this->syncLinks(array_intersect_key($data, array_flip(array_keys($input))));

        $moved = $this->wasChanged(['start_date', 'end_date', 'start_time', 'end_time']);
        if ($actor && ($moved || $this->wasChanged('title'))) {
            $when = $this->start_date->format('D j M').($this->start_time ? ' '.substr((string) $this->start_time, 0, 5) : '');
            $body = $moved ? "{$actor->name} moved {$this->title} to {$when}" : "{$actor->name} updated {$this->title}";
            Notify::toAccessOf($this, 'calendar', $actor->id, 'Calendar', $body, $this->url());
        }
    }

    /** Where a push about this event opens. */
    public function url(): string
    {
        return route('calendar.events.show', $this, false);
    }

    private static function onlyColumns(array $data): array
    {
        $cols = array_intersect_key($data, array_flip(self::COLUMNS));
        if (array_key_exists('repeat', $cols) && $cols['repeat'] === 'none') {
            $cols['repeat_until'] = null; // a one-off has no "until"; keeps the row honest
        }

        return $cols;
    }

    /** Replace people / project links when those keys are present. */
    private function syncLinks(array $data): void
    {
        if (array_key_exists('people', $data)) {
            $this->links()->whereIn('linkable_type', ['user', 'person'])->delete();
            foreach (collect($data['people'] ?? [])->unique(fn ($p) => $p['type'].':'.$p['id'])->values() as $i => $p) {
                $this->links()->create(['linkable_type' => $p['type'], 'linkable_id' => (int) $p['id'], 'position' => $i]);
            }
        }
        if (array_key_exists('project_id', $data)) {
            // The editor may not see the linked project (it's private to someone else), so their form shows no
            // project and sends null. Replace only when they pick a project they can see, or clear one they
            // can see; anything else (unchanged, hidden current link, unseeable pick) leaves the link alone.
            $current = $this->projectId();
            $new = $data['project_id'] ? (int) $data['project_id'] : null;
            if ($new !== $current && ($new ? self::projectVisible($new) : self::projectVisible($current))) {
                $this->links()->where('linkable_type', 'project')->delete();
                if ($new) {
                    $this->links()->create(['linkable_type' => 'project', 'linkable_id' => $new]);
                }
            }
        }
    }

    private function projectId(): ?int
    {
        $id = $this->links()->where('linkable_type', 'project')->value('linkable_id');

        return $id ? (int) $id : null;
    }

    /** Projects module on and the acting user can see the project (auth user: web session or MCP token). */
    private static function projectVisible(?int $id): bool
    {
        return $id && self::projectsOn() && Project::whereKey($id)->visibleTo((int) auth()->id())->exists();
    }

    private static function projectsOn(): bool
    {
        return app(ModuleRegistry::class)->isEnabled('projects', (int) CurrentFamily::id());
    }

    /** @return list<array{type:string,id:int,name:string}> current members + existing contacts, in link order */
    public function people(): array
    {
        return self::peopleFor(collect([$this]))[$this->id] ?? [];
    }

    /**
     * People for many events in two queries (calendar page). A user who left the family or a deleted contact
     * is skipped on read; contacts are also cleaned up by the person.deleted listener.
     *
     * @return array<int, list<array{type:string,id:int,name:string}>>
     */
    public static function peopleFor($events): array
    {
        $links = CalendarEventLink::whereIn('event_id', $events->pluck('id'))->whereIn('linkable_type', ['user', 'person'])
            ->orderBy('position')->get();
        // Skip a lookup with nothing to look up, and filter members by the pivot instead of loading the family row
        // first: Home calls this on each visit, and those were 3 of its queries for usually-empty answers.
        $userIds = $links->where('linkable_type', 'user')->pluck('linkable_id');
        $personIds = $links->where('linkable_type', 'person')->pluck('linkable_id');
        $users = $userIds->isEmpty() ? collect() : User::query()
            ->join('family_user', 'family_user.user_id', '=', 'users.id')
            ->where('family_user.family_id', CurrentFamily::id())
            ->whereIn('users.id', $userIds)
            ->pluck('users.name', 'users.id');
        $persons = $personIds->isEmpty() ? collect() : Person::whereIn('id', $personIds)->pluck('name', 'id');

        $out = [];
        foreach ($links as $l) {
            $name = $l->linkable_type === 'user' ? $users->get($l->linkable_id) : $persons->get($l->linkable_id);
            if ($name !== null) {
                $out[$l->event_id][] = ['type' => $l->linkable_type, 'id' => (int) $l->linkable_id, 'name' => $name];
            }
        }

        return $out;
    }

    /**
     * Occurrences of the events $userId may see, overlapping [from, to], as unified calendar items.
     *
     * $openTodos opts in to the day-card extras (marks, a day's note, its open to-do count) on multi-day events:
     * called with the date window those events' occurrences cover, it returns [eventId => [Y-m-d => open to-dos due
     * that day]] (the calendar provider answers from the to-dos it already loaded when the window fits its range).
     * Null (Home, MCP) skips them, and with them their queries.
     *
     * The window is wider than [from, to] on purpose: the timeline loads its strip in chunks and keeps one copy of each
     * occurrence, the last one loaded, so every copy must carry the WHOLE occurrence's notes and to-dos. Clipped to the
     * chunk, a 40-day fast loaded by a later chunk lost the note and "1 to-do" of a day the first chunk had (2026-11-11).
     *
     * @param  (Closure(string, string): array<int, array<string,int>>)|null  $openTodos
     */
    public static function itemsFor(int $userId, string $from, string $to, ?Closure $openTodos = null): array
    {
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > Occurrences::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Range must be at most '.Occurrences::MAX_RANGE_DAYS.' days.');
        }
        $events = static::query()->visibleTo($userId)->with(['shareSetting', 'shares'])
            ->whereDate('start_date', '<=', $to)
            ->where(fn ($q) => $q->where('repeat', '!=', 'none')->orWhereDate('end_date', '>=', $from))
            ->get();
        $people = self::peopleFor($events);
        $multi = $openTodos === null ? collect() : $events->filter->isMultiDay();
        $decor = [];
        $todoCounts = [];
        if ($multi->isNotEmpty()) {
            // Any occurrence touching [from, to] lies within [from - longest span, to + longest span].
            $longest = (int) $multi->max(fn (self $e) => $e->start_date->diffInDays($e->end_date));
            $lo = CarbonImmutable::parse($from)->subDays($longest)->toDateString();
            $hi = CarbonImmutable::parse($to)->addDays($longest)->toDateString();
            $decor = self::dayDecor($multi, $lo, $hi);
            $todoCounts = $openTodos($lo, $hi);
        }

        $items = [];
        foreach ($events as $e) {
            // Icon: the one picked, else a guess from the title, else the category's, else a plain calendar. Null
            // in the column means "never picked", so the guess keeps following the title. Once per event, not per
            // occurrence of a repeating one.
            $icon = $e->icon ?: IconGuess::first($e->title) ?: (CalendarCategory::lookup($e->category)['icon'] ?? 'calendar');
            $days = (int) $e->start_date->diffInDays($e->end_date);
            $editable = self::editableBy($e, $userId);
            foreach (Occurrences::starts($e->start_date->toDateString(), $e->end_date->toDateString(), $e->repeat, $e->repeat_interval, $e->repeat_until?->toDateString(), $from, $to) as $start) {
                $items[] = CalendarSources::item(
                    'event', $e->id, $e->title, $start, CarbonImmutable::parse($start)->addDays($days)->toDateString(),
                    href: "/m/calendar/events/{$e->id}", icon: $icon, color: $e->color, category: $e->category,
                    people: $people[$e->id] ?? [], startTime: $e->start_time, endTime: $e->end_time, editable: $editable,
                    // An event with only to-dos (no marks, no notes) still says "1 to-do" on its day card.
                    days: isset($decor[$e->id]) || isset($todoCounts[$e->id])
                        ? self::occurrenceDays($decor[$e->id] ?? ['marks' => [], 'notes' => []], $start, $days, $todoCounts[$e->id] ?? [])
                        : null,
                );
            }
        }

        return $items;
    }

    /**
     * Marks and notes of the given multi-day events in 2 queries however many (marks: all of them, notes: only those
     * dated inside the range), grouped by event. Events with neither are left out.
     *
     * @return array<int, array{marks: list<array>, notes: array<string,string>}>
     */
    private static function dayDecor($events, string $from, string $to): array
    {
        $ids = $events->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $marks = CalendarEventMark::whereIn('event_id', $ids)->orderBy('id')->get()->groupBy('event_id');
        $notes = CalendarEventDayNote::whereIn('event_id', $ids)->whereBetween('date', [$from, $to])->get()->groupBy('event_id');
        $out = [];
        foreach ($ids as $id) {
            if ($marks->has($id) || $notes->has($id)) {
                $out[$id] = [
                    'marks' => ($marks[$id] ?? collect())->map->toPayload()->all(),
                    'notes' => ($notes[$id] ?? collect())->pluck('body', 'date')->all(),
                ];
            }
        }

        return $out;
    }

    /**
     * One occurrence's day data, keyed by day index from ITS start: the event's marks apply to every occurrence
     * (that is what carrying them to next year means), notes and to-dos are about real dates so only the ones inside
     * this occurrence count. Null when the occurrence has nothing, to keep the payload small.
     *
     * @param  array{marks: list<array>, notes: array<string,string>}  $decor
     * @param  array<string,int>  $todosByDate
     */
    private static function occurrenceDays(array $decor, string $start, int $span, array $todosByDate): ?array
    {
        $first = CarbonImmutable::parse($start);
        $idx = fn (string $date) => (int) $first->diffInDays(CarbonImmutable::parse($date), false);
        $inside = fn (int $i) => $i >= 0 && $i <= $span;
        $notes = [];
        foreach ($decor['notes'] as $date => $body) {
            if ($inside($i = $idx($date))) {
                $notes[$i] = $body;
            }
        }
        $todos = [];
        foreach ($todosByDate as $date => $count) {
            if ($inside($i = $idx($date))) {
                $todos[$i] = $count;
            }
        }

        return $decor['marks'] || $notes || $todos ? ['marks' => $decor['marks'], 'notes' => (object) $notes, 'todos' => (object) $todos] : null;
    }

    /**
     * Dated to-dos (top-level + sub-tasks) of events $userId can see, each with its event, in 3 queries however
     * many: the small set first (listless dated tasks, narrowed by $scope), then their event links, then the
     * visible events among those. Listless tasks also include project tasks; they have no calendar link and drop out.
     *
     * @param  Closure(Builder): mixed  $scope  extra task constraints (range, open only)
     * @return list<array{0: Task, 1: self}>
     */
    public static function datedTodos(int $userId, Closure $scope): array
    {
        $tasks = Task::query()->whereNull('task_list_id')->whereNotNull('due_date')->tap($scope)->with('assignee')->get();
        if ($tasks->isEmpty()) {
            return [];
        }
        // A sub-task belongs to its parent's event (only top-level to-dos carry the link).
        $topId = fn (Task $t) => $t->parent_id ?? $t->id;
        $eventOf = CalendarEventLink::where('linkable_type', 'task')->whereIn('linkable_id', $tasks->map($topId)->unique()->values())
            ->pluck('event_id', 'linkable_id');
        $events = $eventOf->isEmpty() ? collect() : static::query()->visibleTo($userId)->whereIn('id', $eventOf->unique()->values())->get()->keyBy('id');

        $out = [];
        foreach ($tasks as $t) {
            $event = $events->get($eventOf->get($topId($t)));
            if ($event) {
                $out[] = [$t, $event];
            }
        }

        return $out;
    }

    /**
     * Same verdict as ShareAccess::canEdit(), but read from the `shares`/`shareSetting` relations
     * itemsFor() already eager-loaded instead of firing ShareAccess's own 2 queries per event (it does
     * `shares()->where(...)->value()` and `$user->families()->whereKey()->exists()`), which would turn
     * a month view into dozens of extra round trips. Membership for the `family` default is implied
     * here: $events is already scoped to the current family (BelongsToFamily) and filtered by
     * visibleTo($userId), so a family-visible row reaching this point means $userId can see it as a
     * family member already — no need to re-query family membership.
     */
    private static function editableBy(self $e, int $userId): bool
    {
        $role = $e->shares->firstWhere('user_id', $userId)?->role;

        return $role !== null ? in_array($role, ['owner', 'editor'], true) : $e->visibility() === 'family';
    }

    /** Detail page / MCP shape (to-dos are added by the controller, which also needs members). */
    public function toPayload(): array
    {
        // Only a project the viewer can see: a private project's name must not leak through the event.
        $projectId = $this->projectId();
        $project = self::projectVisible($projectId) ? Project::find($projectId) : null;

        return $this->formValues() + [
            'id' => $this->id,
            'people' => $this->people(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->name, 'url' => "/m/projects/{$project->id}"] : null,
            'visibility' => $this->visibility(),
            'url' => "/m/calendar/events/{$this->id}",
        ];
    }
}
