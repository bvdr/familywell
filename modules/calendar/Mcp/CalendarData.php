<?php

namespace Modules\Calendar\Mcp;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Modules\Calendar\CalendarEvent;
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
            'icon' => $s->string()->description('Lucide icon name, e.g. plane, car, briefcase, cake.'),
            'color' => $s->string()->description('One of: green, blue, orange, red, purple, yellow, gray.'),
            'start_date' => $s->string()->description('Y-m-d'),
            'end_date' => $s->string()->description('Y-m-d, inclusive; same as start_date for one day.'),
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
