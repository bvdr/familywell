<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

// A user's private ICS subscribe token in this family (Task 10 adds reset + the feed itself).
class CalendarFeed extends Model
{
    use BelongsToFamily;

    protected $table = 'calendar_feeds';

    protected $fillable = ['user_id', 'token', 'family_id'];

    public static function urlFor(int $userId): ?string
    {
        $token = static::where('user_id', $userId)->value('token');

        return $token ? route('calendar.feed', $token) : null;
    }
}
