<?php

namespace Modules\Calendar;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;
use Modules\Tasks\Task;

// Events, to-dos, marks and day notes in Settings > Recently deleted. Restoring an event brings back the to-dos trashed
// with it (CalendarEvent::booted), so only to-dos of live events are listed on their own. Marks and notes stay live
// when their event is trashed (they come back with it for free), so only ones trashed on their own are listed.
class CalendarTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        $events = CalendarEvent::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (CalendarEvent $e) => Trash::row($e, 'calendar_event', $e->title, 'Calendar'));

        $live = CalendarEvent::visibleTo($user->id)->get(['id', 'title'])->keyBy('id');
        $links = CalendarEventLink::where('linkable_type', 'task')->whereIn('event_id', $live->keys())->pluck('event_id', 'linkable_id');
        $todos = Task::onlyTrashed()->whereNull('task_list_id')
            ->where(fn ($q) => $q->whereIn('id', $links->keys())->orWhereIn('parent_id', $links->keys()))->get()
            ->map(fn (Task $t) => Trash::row($t, 'todo', $t->title, $live[$links[$t->parent_id ?? $t->id]]->title));

        $marks = CalendarEventMark::onlyTrashed()->whereIn('event_id', $live->keys())->get()
            ->map(fn (CalendarEventMark $m) => Trash::row($m, 'mark', $m->name, $live[$m->event_id]->title));
        $notes = CalendarEventDayNote::onlyTrashed()->whereIn('event_id', $live->keys())->get()
            ->map(fn (CalendarEventDayNote $n) => Trash::row($n, 'day_note', $n->body, $live[$n->event_id]->title));

        return $events->concat($todos)->concat($marks)->concat($notes);
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        if ($type === 'todo') {
            $task = Task::onlyTrashed()->whereNull('task_list_id')->find($id);
            $link = $task ? CalendarEventLink::where(['linkable_type' => 'task', 'linkable_id' => $task->parent_id ?? $task->id])->first() : null;
            $event = $link ? CalendarEvent::find($link->event_id) : null; // a trashed event: restore the event instead
            if (! $event || app(ShareAccess::class)->roleFor($user, $event) === null) {
                return null;
            }
            $task->restore(); // brings back its trashed parent to-do first (CascadesTrash)

            return "/m/calendar/events/{$event->id}";
        }

        if ($type === 'mark' || $type === 'day_note') {
            $row = ($type === 'mark' ? CalendarEventMark::class : CalendarEventDayNote::class)::onlyTrashed()->find($id);
            $event = $row ? CalendarEvent::find($row->event_id) : null; // a trashed event: restore the event instead
            if (! $event || app(ShareAccess::class)->roleFor($user, $event) === null) {
                return null;
            }
            $row->restore();

            return "/m/calendar/events/{$event->id}";
        }

        $event = $type === 'calendar_event' ? CalendarEvent::onlyTrashed()->find($id) : null;
        if (! $event || app(ShareAccess::class)->roleFor($user, $event) === null) {
            return null;
        }
        $event->restore();

        return "/m/calendar/events/{$event->id}";
    }
}
