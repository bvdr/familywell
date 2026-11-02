<?php

namespace Modules\Calendar;

use Carbon\CarbonImmutable;

// The date window a view shows, computed server-side so the browser only lays out [from, to] (one source of
// truth for both the query and the grid). Weeks start on Monday (Romanian/European convention, like the app).
// Views (design j6QDy4 04 / 04b, 2026-11-03):
//   agenda              Monday..Sunday of `date` (the week strip and its days)
//   month               6 full weeks around the month (the classic grid)
//   timeline + week     one week from the Monday of `date` (1w)
//   timeline + twoweeks two weeks from the Monday of `date` (2w, the phone's default)
//   timeline + month    five weeks from the Monday of `date` (desktop's month zoom)
final class CalendarRange
{
    public const VIEWS = ['agenda', 'month', 'timeline'];

    public const ZOOMS = ['week', 'twoweeks', 'month'];

    /** @return array{0: string, 1: string} */
    public static function for(string $view, string $zoom, string $date): array
    {
        $d = CarbonImmutable::parse($date)->startOfDay();
        $monday = $d->startOfWeek(CarbonImmutable::MONDAY);
        if ($view === 'agenda') {
            return [$monday->toDateString(), $monday->addDays(6)->toDateString()];
        }
        if ($view === 'timeline') {
            $days = match ($zoom) {
                'week' => 7,
                'month' => 35,
                default => 14,
            };

            return [$monday->toDateString(), $monday->addDays($days - 1)->toDateString()];
        }
        $start = $d->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);

        return [$start->toDateString(), $start->addDays(41)->toDateString()];
    }
}
