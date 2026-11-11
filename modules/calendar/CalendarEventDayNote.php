<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// One note about one date inside a multi-day event. `date` is deliberately NOT cast: a Carbon cast would write
// 'Y-m-d 00:00:00', which sqlite compares as a string (a 'Y-m-d' range end would miss the last day).
class CalendarEventDayNote extends Model
{
    use BelongsToFamily;
    use SoftDeletes; // 30-day trash (CalendarTrash); a trashed row still holds its (event_id, date) slot, see CalendarEvent::setDayNote

    protected $table = 'calendar_event_day_notes';

    protected $fillable = ['event_id', 'date', 'body'];
}
