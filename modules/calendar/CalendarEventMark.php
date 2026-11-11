<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// A named, icon'd mark on a set of an event's days, stored by day index from the event's start (see the migration
// for why indexes, not dates). Rules for who may edit and how many there can be live in CalendarController.
class CalendarEventMark extends Model
{
    use BelongsToFamily;
    use SoftDeletes; // 30-day trash (CalendarTrash), so an MCP delete can be undone

    protected $table = 'calendar_event_marks';

    protected $fillable = ['event_id', 'name', 'icon', 'days'];

    protected $casts = ['days' => 'array'];

    /** The most marks one event can carry: keeps the item payload and the day sheet's chip row small. */
    public const MAX_PER_EVENT = 12;

    /**
     * Validate a mark body against $event's CURRENT length (shared by the web routes and the MCP tools). A mark that
     * outlives a shortened event keeps its stale indexes harmlessly (nothing renders them) until it is next saved.
     * $partial = an update: only the fields sent are checked.
     */
    public static function validated(CalendarEvent $event, array $input, bool $partial): array
    {
        $last = (int) $event->start_date->diffInDays($event->end_date);
        $req = $partial ? 'sometimes' : 'required';
        $data = validator($input, [
            'name' => [$req, 'string', 'max:60'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'days' => [$partial ? 'sometimes' : 'present', 'array', 'max:'.($last + 1)],
            'days.*' => ['integer', "between:0,{$last}", 'distinct'],
        ])->validate();
        if (isset($data['days'])) {
            sort($data['days']); // stored sorted: stable for diffs and for the legend's count
        }

        return $data;
    }

    /** @return array{id:int,name:string,icon:?string,days:list<int>} */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'icon' => $this->icon, 'days' => array_values($this->days)];
    }
}
