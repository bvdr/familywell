<?php

namespace Modules\Calendar;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Events in Settings > Recently deleted. Restoring brings back the event's to-dos (CalendarEvent::booted).
class CalendarTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return CalendarEvent::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (CalendarEvent $e) => Trash::row($e, 'calendar_event', $e->title, 'Calendar'));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $event = $type === 'calendar_event' ? CalendarEvent::onlyTrashed()->find($id) : null;
        if (! $event || app(ShareAccess::class)->roleFor($user, $event) === null) {
            return null;
        }
        $event->restore();

        return "/m/calendar/events/{$event->id}";
    }
}
