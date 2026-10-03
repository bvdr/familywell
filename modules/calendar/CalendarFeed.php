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

    /** Issue a new token for $userId in the current family (create or rotate; the old URL dies). */
    public static function rotate(int $userId): self
    {
        $feed = static::firstOrNew(['user_id' => $userId]);
        $feed->token = bin2hex(random_bytes(24)); // 48 hex chars, unguessable
        $feed->save();

        return $feed;
    }
}
