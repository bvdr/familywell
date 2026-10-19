<?php

namespace Modules\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * ICS text → occurrence rows for calendar_subscription_events, between $from and $to (Y-m-d), as wall-clock
 * dates/times in $timezone (the shape calendar_events uses). sabre/vobject does the hard part: RRULE, EXDATE,
 * moved occurrences (RECURRENCE-ID) and VTIMEZONE. Cancelled events are skipped.
 *
 * Not a calendar (HTML login page, vCard, garbage) throws RuntimeException('not_calendar').
 */
class IcsImport
{
    // ponytail: cap per calendar so one huge feed can't fill the table; raise if a real calendar hits it.
    public const MAX_ROWS = 5000;

    /** @return list<array{title: string, location: ?string, notes: ?string, start_date: string, end_date: string, start_time: ?string, end_time: ?string}> */
    public static function occurrences(string $ics, string $timezone, string $from, string $to): array
    {
        $zone = new DateTimeZone($timezone);
        try {
            $cal = Reader::read($ics, Reader::OPTION_FORGIVING);
            if (! $cal instanceof VCalendar) {
                throw new RuntimeException('not_calendar');
            }
            // expand() turns every repeat into its own VEVENT inside the window; timed ones come back in UTC.
            $cal = $cal->expand(new DateTimeImmutable("{$from} 00:00:00", $zone), new DateTimeImmutable("{$to} 23:59:59", $zone), $zone);
        } catch (RuntimeException $e) {
            throw $e->getMessage() === 'not_calendar' ? $e : new RuntimeException('not_calendar');
        } catch (\Throwable) {
            throw new RuntimeException('not_calendar');
        }

        $rows = [];
        foreach ($cal->select('VEVENT') as $ev) {
            if (strtoupper((string) $ev->STATUS) === 'CANCELLED' || ! isset($ev->DTSTART)) {
                continue;
            }
            $allDay = ! $ev->DTSTART->hasTime();
            $start = DateTimeImmutable::createFromInterface($ev->DTSTART->getDateTime($zone))->setTimezone($zone);
            $end = match (true) {
                isset($ev->DTEND) => DateTimeImmutable::createFromInterface($ev->DTEND->getDateTime($zone))->setTimezone($zone),
                isset($ev->DURATION) => $start->add($ev->DURATION->getDateInterval()),
                $allDay => $start->add(new DateInterval('P1D')),
                default => $start,
            };
            $rows[] = self::row($ev, $start, $end, $allDay);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    private static function row($ev, DateTimeInterface $start, DateTimeInterface $end, bool $allDay): array
    {
        if ($allDay) {
            // DTEND is exclusive for all-day events: a one-day event ends the next day.
            $endDate = $end > $start ? (new DateTimeImmutable($end->format('Y-m-d')))->sub(new DateInterval('P1D')) : $start;
            [$startTime, $endTime] = [null, null];
        } else {
            $endDate = $end < $start ? $start : $end;
            [$startTime, $endTime] = [$start->format('H:i'), $endDate->format('H:i')];
            // Ends exactly at midnight: that's the end of the previous day, not a 00:00 on the next one.
            if ($endTime === '00:00' && $endDate->format('Y-m-d') > $start->format('Y-m-d')) {
                $endDate = (new DateTimeImmutable($endDate->format('Y-m-d')))->sub(new DateInterval('P1D'));
                $endTime = '23:59';
            }
        }

        $text = fn ($p, int $max) => isset($p) && trim((string) $p) !== '' ? mb_substr(trim((string) $p), 0, $max) : null;

        return [
            'title' => $text($ev->SUMMARY, 255) ?? '',
            'location' => $text($ev->LOCATION, 255),
            'notes' => $text($ev->DESCRIPTION, 5000),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }
}
