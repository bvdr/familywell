<?php

namespace Modules\Calendar;

use Carbon\CarbonImmutable;

/**
 * Repeat expansion, kept as a pure function of strings so it is unit-testable without a DB. Used by the
 * calendar page, the dashboard, MCP and (indirectly) everything that lists events. The ICS feed does NOT
 * use it: it emits the rule itself (RRULE) and lets the subscriber expand.
 *
 * Rules: an occurrence keeps the event's duration (a repeating Fri-Sun block repeats as a block) and is
 * returned when that block overlaps [from, to]. weekly = start weekday; monthly = start day-of-month, months
 * without it are skipped (no Feb 31); yearly = start month+day, Feb 29 only in leap years (same as RFC 5545).
 */
final class Occurrences
{
    public const MAX_RANGE_DAYS = 400;

    public const REPEATS = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    /** @return list<string> occurrence start dates (Y-m-d), ascending */
    public static function starts(string $start, string $end, string $repeat, int $interval, ?string $until, string $from, string $to): array
    {
        $s = CarbonImmutable::parse($start)->startOfDay();
        $duration = (int) $s->diffInDays(CarbonImmutable::parse($end)->startOfDay());
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $last = $until ? CarbonImmutable::parse($until)->startOfDay() : $to; // last day an occurrence may START
        if ($last->gt($to)) {
            $last = $to;
        }
        $interval = max(1, $interval);
        // An occurrence starting on or after this day reaches into the range.
        $earliest = $from->subDays($duration);

        if ($repeat === 'none') {
            return $s->lte($to) && $s->gte($earliest) ? [$s->toDateString()] : [];
        }

        $out = [];
        if ($repeat === 'daily' || $repeat === 'weekly') {
            $step = $repeat === 'daily' ? $interval : 7 * $interval;
            // Jump straight to the first step that can reach the range instead of walking from the start.
            $k = max(0, (int) ceil($s->diffInDays($earliest, false) / $step));
            for ($d = $s->addDays($k * $step); $d->lte($last); $d = $d->addDays($step)) {
                $out[] = $d->toDateString();
            }

            return $out;
        }

        // monthly / yearly: step in months, rebuilding the date from the start's day so a skipped month
        // (31st) doesn't shift every later occurrence.
        $stepMonths = $repeat === 'monthly' ? $interval : 12 * $interval;
        $monthsToEarliest = ($earliest->year - $s->year) * 12 + ($earliest->month - $s->month);
        $k = max(0, intdiv(max(0, $monthsToEarliest), $stepMonths) - 1);
        while (true) {
            $base = $s->startOfMonth()->addMonths($k * $stepMonths);
            if ($base->gt($last)) {
                break;
            }
            $k++;
            if (! checkdate($base->month, $s->day, $base->year)) {
                continue;
            }
            $d = $base->setDay($s->day);
            if ($d->gte($earliest) && $d->lte($last) && $d->gte($s)) {
                $out[] = $d->toDateString();
            }
        }

        return $out;
    }
}
