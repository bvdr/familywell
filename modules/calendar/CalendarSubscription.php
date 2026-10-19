<?php

namespace Modules\Calendar;

use App\Models\User;
use App\Support\Calendar\CalendarSources;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Another calendar (ICS / webcal URL) shown read-only in the family calendar. See the migration for the shape.
 * Visible to the person who added it, or to the whole family when `shared`. Only its owner sees or changes the URL.
 */
class CalendarSubscription extends Model
{
    use BelongsToFamily;

    protected $table = 'calendar_subscriptions';

    protected $fillable = ['user_id', 'name', 'url', 'color', 'timezone', 'shared', 'enabled'];

    protected $casts = ['url' => 'encrypted', 'shared' => 'boolean', 'enabled' => 'boolean', 'synced_at' => 'datetime'];

    // The stored window: a little history (so last month still shows) and as far ahead as a calendar view can ask
    // (Occurrences::MAX_RANGE_DAYS). Refreshed hourly, so it rolls forward with today.
    public const PAST_DAYS = 90;

    public const FUTURE_DAYS = 400;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CalendarSubscriptionEvent::class, 'subscription_id');
    }

    public function scopeVisibleTo(Builder $q, int $userId): Builder
    {
        return $q->where(fn ($w) => $w->where('user_id', $userId)->orWhere('shared', true));
    }

    /**
     * Fetch, expand and replace the stored events. A failure keeps the last good events (a calendar host being
     * down for an hour shouldn't blank the calendar) and records the reason for the settings sheet.
     */
    public function sync(): bool
    {
        try {
            $rows = IcsImport::occurrences(
                IcsFetcher::get($this->url),
                $this->timezone,
                now($this->timezone)->subDays(self::PAST_DAYS)->toDateString(),
                now($this->timezone)->addDays(self::FUTURE_DAYS)->toDateString(),
            );
        } catch (RuntimeException $e) {
            $this->forceFill(['error' => in_array($e->getMessage(), ['blocked', 'not_calendar'], true) ? $e->getMessage() : 'unreachable'])->save();

            return false;
        }

        DB::transaction(function () use ($rows) {
            $this->events()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                CalendarSubscriptionEvent::insert(array_map(fn ($r) => $r + ['subscription_id' => $this->id], $chunk));
            }
            $this->forceFill(['synced_at' => now(), 'error' => null])->save();
        });

        return true;
    }

    /** Calendar items for $userId between $from and $to, in the CalendarSources shape. */
    public static function itemsFor(int $userId, string $from, string $to): array
    {
        $subs = static::query()->visibleTo($userId)->where('enabled', true)->get(['id', 'name', 'color'])->keyBy('id');
        if ($subs->isEmpty()) {
            return [];
        }

        return CalendarSubscriptionEvent::whereIn('subscription_id', $subs->keys())
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)
            ->get()
            ->map(fn (CalendarSubscriptionEvent $e) => CalendarSources::item(
                'external', $e->id, $e->title !== '' ? $e->title : $subs[$e->subscription_id]->name,
                $e->start_date->toDateString(), $e->end_date->toDateString(),
                href: "/m/calendar/external/{$e->id}", icon: 'calendar-days', color: $subs[$e->subscription_id]->color,
                startTime: $e->start_time, endTime: $e->end_time,
            ))
            ->all();
    }
}
