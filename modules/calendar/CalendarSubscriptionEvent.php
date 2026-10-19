<?php

namespace Modules\Calendar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence of an event from a subscribed calendar, already expanded (see CalendarSubscription::sync).
 * Replaced wholesale on every sync, so ids aren't stable across syncs. No family_id: always reached through its
 * subscription, which carries the family scope.
 */
class CalendarSubscriptionEvent extends Model
{
    protected $table = 'calendar_subscription_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CalendarSubscription::class, 'subscription_id');
    }
}
