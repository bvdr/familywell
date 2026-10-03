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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Contacts\Person;
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
        'title', 'notes', 'icon', 'color', 'start_date', 'end_date', 'start_time', 'end_time',
        'repeat', 'repeat_interval', 'repeat_until', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'repeat_until' => 'date', 'repeat_interval' => 'integer'];

    protected $attributes = ['repeat' => 'none', 'repeat_interval' => 1];

    public const COLUMNS = ['title', 'notes', 'icon', 'color', 'start_date', 'end_date', 'start_time', 'end_time', 'repeat', 'repeat_interval', 'repeat_until'];

    protected static function booted(): void
    {
        // To-dos aren't a HasMany (they're linked rows), so CascadesTrash can't reach them: trash the event's
        // top-level tasks with the event's exact deleted_at (Task's own CascadesTrash takes their sub-tasks),
        // and on restore bring back the ones that share that timestamp (ones trashed on their own stay trashed).
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

    /** @return list<int> */
    public function taskIds(): array
    {
        return $this->links()->where('linkable_type', 'task')->pluck('linkable_id')->all();
    }

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'icon' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
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
            'title' => $this->title, 'notes' => $this->notes, 'icon' => $this->icon, 'color' => $this->color,
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

        return $event;
    }

    /** Partial update: merge onto current values, validate the whole, save, sync links that were sent. */
    public function applyUpdate(array $input): void
    {
        $data = self::validated(array_merge($this->formValues(), $input));
        $this->update(self::onlyColumns($data));
        $this->syncLinks(array_intersect_key($data, array_flip(array_keys($input))));
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
            $this->links()->where('linkable_type', 'project')->delete();
            if ($data['project_id'] && self::projectsOn() && Project::whereKey($data['project_id'])->visibleTo((int) auth()->id())->exists()) {
                $this->links()->create(['linkable_type' => 'project', 'linkable_id' => (int) $data['project_id']]);
            }
        }
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
        $family = Family::find(CurrentFamily::id());
        $users = $family ? $family->members()->whereIn('users.id', $links->where('linkable_type', 'user')->pluck('linkable_id'))->pluck('name', 'users.id') : collect();
        $persons = Person::whereIn('id', $links->where('linkable_type', 'person')->pluck('linkable_id'))->pluck('name', 'id');

        $out = [];
        foreach ($links as $l) {
            $name = $l->linkable_type === 'user' ? $users->get($l->linkable_id) : $persons->get($l->linkable_id);
            if ($name !== null) {
                $out[$l->event_id][] = ['type' => $l->linkable_type, 'id' => (int) $l->linkable_id, 'name' => $name];
            }
        }

        return $out;
    }

    /** Occurrences of the events $userId may see, overlapping [from, to], as unified calendar items. */
    public static function itemsFor(int $userId, string $from, string $to): array
    {
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > Occurrences::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Range must be at most '.Occurrences::MAX_RANGE_DAYS.' days.');
        }
        $events = static::query()->visibleTo($userId)->with(['shareSetting', 'shares'])
            ->whereDate('start_date', '<=', $to)
            ->where(fn ($q) => $q->where('repeat', '!=', 'none')->orWhereDate('end_date', '>=', $from))
            ->get();
        $people = self::peopleFor($events);

        $items = [];
        foreach ($events as $e) {
            $days = (int) $e->start_date->diffInDays($e->end_date);
            $editable = self::editableBy($e, $userId);
            foreach (Occurrences::starts($e->start_date->toDateString(), $e->end_date->toDateString(), $e->repeat, $e->repeat_interval, $e->repeat_until?->toDateString(), $from, $to) as $start) {
                $items[] = CalendarSources::item(
                    'event', $e->id, $e->title, $start, CarbonImmutable::parse($start)->addDays($days)->toDateString(),
                    href: "/m/calendar/events/{$e->id}", icon: $e->icon ?: 'calendar', color: $e->color,
                    people: $people[$e->id] ?? [], startTime: $e->start_time, endTime: $e->end_time, editable: $editable,
                );
            }
        }

        return $items;
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
        $projectId = $this->links()->where('linkable_type', 'project')->value('linkable_id');
        $project = $projectId && self::projectsOn() ? Project::find($projectId) : null;

        return $this->formValues() + [
            'id' => $this->id,
            'people' => $this->people(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->name, 'url' => "/m/projects/{$project->id}"] : null,
            'visibility' => $this->visibility(),
            'url' => "/m/calendar/events/{$this->id}",
        ];
    }
}
