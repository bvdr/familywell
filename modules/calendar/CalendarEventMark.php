<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

// A named, icon'd mark on a set of an event's days, stored by day index from the event's start (see the migration
// for why indexes, not dates). Rules for who may edit and how many there can be live in CalendarController.
class CalendarEventMark extends Model
{
    use BelongsToFamily;

    protected $table = 'calendar_event_marks';

    protected $fillable = ['event_id', 'name', 'icon', 'days'];

    protected $casts = ['days' => 'array'];

    /** The most marks one event can carry: keeps the item payload and the day sheet's chip row small. */
    public const MAX_PER_EVENT = 12;

    /** @return array{id:int,name:string,icon:?string,days:list<int>} */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'icon' => $this->icon, 'days' => array_values($this->days)];
    }
}
