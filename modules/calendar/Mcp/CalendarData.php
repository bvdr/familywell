<?php

namespace Modules\Calendar\Mcp;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Modules\Calendar\CalendarCategory;
use Modules\Calendar\CalendarEvent;
use Modules\Calendar\CalendarEventMark;
use Modules\Calendar\Occurrences;
use Modules\Tasks\Task;

// Shared schema + payload for the calendar tools, so create/update/get agree on field names (= columns).
final class CalendarData
{
    public static function schema(JsonSchema $s): array
    {
        return [
            'title' => $s->string()->description('Event title, e.g. "Trip to Vienna".'),
            'notes' => $s->string()->description('Free-text notes.'),
            'icon' => $s->string()->description('Lucide icon name, e.g. plane, car, briefcase, cake. Leave it out and the calendar picks one from the title.'),
            'color' => $s->string()->description('One of: '.implode(', ', CalendarCategory::COLORS).'. Leave it out and the event takes its category\'s colour.'),
            'category' => $s->string()->description('What kind of event it is; the calendar filters by it. A slug from calendar_list_categories: trips, faith, family, school, work, or one the family added.'),
            'start_date' => $s->string()->description('Y-m-d'),
            'end_date' => $s->string()->description('Y-m-d, inclusive; same as start_date for one day. An event longer than one day has days that can carry marks, notes and to-dos.'),
            'start_time' => $s->string()->description('H:i local time; omit (with end_time) for all-day.'),
            'end_time' => $s->string()->description('H:i local time.'),
            'repeat' => $s->string()->description('How it repeats; omit for a one-time event.')->enum(Occurrences::REPEATS),
            'repeat_interval' => $s->integer()->description('Every N days/weeks/months/years (default 1).'),
            'repeat_until' => $s->string()->description('Y-m-d, last day an occurrence may start; omit for forever.'),
            'people' => $s->array()->items($s->object(['type' => $s->string()->description('user = a family member, person = a contact.')->enum(['user', 'person']), 'id' => $s->integer()->description('The member id (get_family) or contact id (contacts_list).')]))
                ->description('Family members (type user, ids from get_family) and contacts (type person, ids from contacts_list).'),
            'project_id' => $s->integer()->description('Project to link it to, from projects_list.'),
        ];
    }

    /** Picking a category without a colour gives the event that category's colour, like the event sheet does. */
    public static function input(array $in): array
    {
        if (! empty($in['category']) && ! array_key_exists('color', $in)) {
            $in['color'] = CalendarCategory::lookup($in['category'])['color'] ?? null;
        }

        return $in;
    }

    public static function payload(CalendarEvent $e, callable $url, bool $withTodos = false): array
    {
        $p = $e->toPayload();
        $p['url'] = $url($p['url']);
        if ($p['project']) {
            $p['project']['url'] = $url($p['project']['url']);
        }
        if ($withTodos) {
            // To-dos have no page of their own: they live on the event's page.
            // Sub-tasks included so calendar_update_todo / calendar_delete_todo have their ids too.
            $p['todos'] = Task::whereIn('id', $e->taskIds())->whereNull('parent_id')->with(['assignee', 'subtasks.assignee'])->orderBy('position')->get()
                ->map(fn (Task $t) => self::todoPayload($t, $e, $url) + ['subtasks' => $t->subtasks->map(fn (Task $s) => self::todoPayload($s, $e, $url))->values()->all()])
                ->values()->all();
        }

        return $p;
    }

    /**
     * The days inside one occurrence of a multi-day event (design C15): its dates, the marks (stored by day index, so
     * the same in every occurrence; `dates` spells them out for this one) and the notes dated inside it. A day's
     * to-dos are the event to-dos due on it. Null for a one-day event, which has no days to mark.
     */
    public static function days(CalendarEvent $e, ?string $day = null): ?array
    {
        if (! $e->isMultiDay()) {
            return null;
        }
        [$start, $end] = $e->occurrenceAt($day);

        return [
            'start' => $start,
            'end' => $end,
            'marks' => $e->marks->map(fn (CalendarEventMark $m) => self::markPayload($m, $start))->values()->all(),
            'notes' => $e->dayNotes()->whereBetween('date', [$start, $end])->orderBy('date')->get(['date', 'body'])
                ->map(fn ($n) => ['date' => $n->date, 'body' => $n->body])->all(),
        ];
    }

    /** A mark with its day indexes and, for the occurrence starting on $start, the dates they fall on. */
    public static function markPayload(CalendarEventMark $m, string $start): array
    {
        $first = CarbonImmutable::parse($start);

        return $m->toPayload() + ['event_id' => $m->event_id, 'dates' => array_map(fn (int $i) => $first->addDays($i)->toDateString(), array_values($m->days))];
    }

    /**
     * Dates (Y-m-d) inside the event's occurrences as day indexes, which is how marks are stored (so they carry over to
     * the next occurrence of a repeating event). Null if any date is outside every occurrence.
     *
     * @param  list<string>  $dates
     * @return list<int>|null
     */
    public static function dayIndexes(CalendarEvent $e, array $dates): ?array
    {
        $out = [];
        foreach ($dates as $date) {
            if (! $e->coversDate($date)) {
                return null;
            }
            $out[] = (int) CarbonImmutable::parse($e->occurrenceAt($date)[0])->diffInDays(CarbonImmutable::parse($date));
        }

        return array_values(array_unique($out));
    }

    /**
     * A mark the user may edit, with its event, or null. Looked up family-scoped, then edit rights on its event,
     * like CalendarController::updateMark.
     *
     * @return array{0: CalendarEventMark, 1: CalendarEvent}|null
     */
    public static function mark(User $user, mixed $id): ?array
    {
        $mark = CalendarEventMark::find((int) $id);
        $event = $mark ? CalendarEvent::find($mark->event_id) : null;

        return $event && app(ShareAccess::class)->canEdit($user, $event) ? [$mark, $event] : null;
    }

    /** The multi-day event $user may edit, or null (a one-day event has no days to mark or write on). */
    public static function editableMultiDay(User $user, mixed $id): ?CalendarEvent
    {
        $e = CalendarEvent::find((int) $id);

        return $e && $e->isMultiDay() && app(ShareAccess::class)->canEdit($user, $e) ? $e : null;
    }

    /**
     * An event to-do (top-level or sub-task) the user may edit, with its event, or null. Editing a to-do needs
     * edit rights on the event, like the web routes (CalendarController::eventForTask). Task::find is
     * family-scoped and skips trashed tasks.
     *
     * @return array{0: Task, 1: CalendarEvent}|null
     */
    public static function todo(User $user, mixed $id): ?array
    {
        $task = Task::find((int) $id);
        $event = $task ? CalendarEvent::forTask($task) : null;

        return $event && app(ShareAccess::class)->canEdit($user, $event) ? [$task, $event] : null;
    }

    public static function todoPayload(Task $t, CalendarEvent $e, callable $url): array
    {
        return [
            'id' => $t->id,
            'parent_id' => $t->parent_id,
            'title' => $t->title,
            'note' => $t->note,
            'done' => $t->done,
            'due_date' => $t->due_date?->toDateString(),
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'event_id' => $e->id,
            'url' => $url("/m/calendar/events/{$e->id}"), // to-dos have no page of their own
        ];
    }
}
