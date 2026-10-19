<?php

namespace Modules\Calendar;

use Carbon\CarbonImmutable;

// The date window a view shows, computed server-side so the browser only lays out [from, to] (one source of
// truth for both the query and the grid). Weeks start on Monday (Romanian/European convention, like the app).
//   month            6 full weeks around the month (the classic grid)
//   timeline + week  two weeks from the Monday of `date`
//   timeline + month the calendar month of `date`
//   timeline + year  the calendar year of `date` (365/366 days, inside Occurrences::MAX_RANGE_DAYS)
//   week             Monday..Sunday of `date` (the week view)
final class CalendarRange
{
    /** @return array{0: string, 1: string} */
    public static function for(string $view, string $zoom, string $date): array
    {
        $d = CarbonImmutable::parse($date)->startOfDay();
        if ($view === 'week') {
            return [$d->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $d->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)->toDateString()];
        }
        if ($view === 'timeline') {
            return match ($zoom) {
                'week' => [$d->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $d->startOfWeek(CarbonImmutable::MONDAY)->addDays(13)->toDateString()],
                'year' => [$d->startOfYear()->toDateString(), $d->endOfYear()->toDateString()],
                default => [$d->startOfMonth()->toDateString(), $d->endOfMonth()->toDateString()],
            };
        }
        $start = $d->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);

        return [$start->toDateString(), $start->addDays(41)->toDateString()];
    }
}
