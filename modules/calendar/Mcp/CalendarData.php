<?php

namespace Modules\Calendar\Mcp;

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
            'title' => $s->string(),
            'notes' => $s->string(),
            'icon' => $s->string()->description('Lucide icon name, e.g. plane, car, briefcase, cake.'),
            'color' => $s->string()->description('One of: green, blue, orange, red, purple, yellow, gray.'),
            'start_date' => $s->string()->description('Y-m-d'),
            'end_date' => $s->string()->description('Y-m-d, inclusive; same as start_date for one day.'),
            'start_time' => $s->string()->description('H:i local time; omit (with end_time) for all-day.'),
            'end_time' => $s->string()->description('H:i local time.'),
            'repeat' => $s->string()->enum(Occurrences::REPEATS),
            'repeat_interval' => $s->integer()->description('Every N days/weeks/months/years (default 1).'),
            'repeat_until' => $s->string()->description('Y-m-d, last day an occurrence may start; omit for forever.'),
            'people' => $s->array()->items($s->object(['type' => $s->string()->enum(['user', 'person']), 'id' => $s->integer()]))
                ->description('Family members (type user, ids from get_family) and contacts (type person, ids from contacts_list).'),
            'project_id' => $s->integer(),
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
            $p['todos'] = Task::whereIn('id', $e->taskIds())->orderBy('position')->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'done' => $t->done, 'url' => $p['url']])->values()->all();
        }

        return $p;
    }
}
