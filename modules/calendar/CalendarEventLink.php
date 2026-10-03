<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

// An event's link to a person (user | person), a to-do (task) or a project. Same shape as projects_links.
class CalendarEventLink extends Model
{
    use BelongsToFamily;

    protected $table = 'calendar_event_links';

    protected $fillable = ['event_id', 'linkable_type', 'linkable_id', 'position', 'family_id'];
}
