<?php

namespace App\Support\Tenancy;

use App\Models\Family;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * "Which day is it" for a family: its own calendar day in its own zone (families.timezone, Settings › Region & units),
 * not the server's UTC one. Between midnight and 02:00/03:00 in Romania, UTC is still on yesterday, so Home's Today,
 * overdue, "day N of M", birthdays and the Add reader's "tomorrow" were a day behind.
 *
 * NEVER fix that by changing config('app.timezone') or date_default_timezone_set() per request: Eloquent writes Carbon
 * values to the DB as wall-clock strings with no zone, so a per-request app zone would store created_at & co. in
 * mixed zones. Timestamps stay UTC; only day-boundary decisions ask this class.
 *
 * Zones are memoized per family on a scoped instance (AppServiceProvider): Octane and the queue worker flush scoped
 * instances between requests/jobs, so nothing leaks across requests. Family::saved drops a stale entry.
 */
final class FamilyClock
{
    /** @var array<int, ?string> family id => stored zone (null = not picked yet) */
    private array $zones = [];

    /** The zone as stored, null when the family hasn't got one yet (or there's no family). */
    public static function stored(?int $familyId = null): ?string
    {
        $familyId ??= CurrentFamily::id();
        if (! $familyId) {
            return null;
        }
        $clock = app(self::class);
        if (! array_key_exists($familyId, $clock->zones)) {
            $clock->zones[$familyId] = Family::whereKey($familyId)->value('timezone');
        }

        return $clock->zones[$familyId];
    }

    /** The family's zone, or the app's (UTC) when none is picked yet or there's no family (guests, console). */
    public static function zone(?int $familyId = null): string
    {
        return self::stored($familyId) ?? config('app.timezone');
    }

    /** Now on the family's wall clock. For "what time is it there"; store timestamps with now() as always. */
    public static function now(?int $familyId = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone($familyId));
    }

    /** The family's calendar day, Y-m-d. */
    public static function today(?int $familyId = null): string
    {
        return self::now($familyId)->toDateString();
    }

    /**
     * The family's calendar day as a midnight Carbon in the APP zone: the same shape a `date` cast column has, so
     * lt()/diffInDays() against due_date, expires_on & co. compare whole days. (A midnight in the family's own zone
     * would sit hours off a date column and break those comparisons.) Mutable, like the Carbon::today() it replaces.
     */
    public static function date(?int $familyId = null): Carbon
    {
        return Carbon::parse(self::today($familyId));
    }

    /** Family::saved: the next read sees the new zone. */
    public static function forget(int $familyId): void
    {
        unset(app(self::class)->zones[$familyId]);
    }
}
