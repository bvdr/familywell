<?php

namespace Modules\Spending;

use Illuminate\Support\Collection;

// The "this month" figure: month-to-date spend across the viewer's visible, non-archived lists, rolled up
// into one display currency exactly like analytics(period=mtd). Shared by the Spending overview widget
// and the dashboard's Spending card (registered in SpendingServiceProvider) so the two numbers agree.
class MonthToDate
{
    /**
     * Active lists the viewer can see, as id => base currency. Family-visibility lists are visible to any
     * member (the BelongsToFamily scope limits to the current family); invited/private ones need a share.
     *
     * @return Collection<int, string>
     */
    public static function visibleEventCurrencies(int $userId): Collection
    {
        return SpendEvent::query()
            ->with(['shareSetting', 'shares'])
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $userId))
            ->pluck('currency', 'id');
    }

    // The family's default currency, else the most common base currency across the lists, else EUR.
    public static function displayCurrencyFor(Collection $eventCcy): string
    {
        return SpendSetting::defaultCurrency()
            ?: ($eventCcy->countBy()->sortDesc()->keys()->first() ?? Currencies::FALLBACK);
    }

    /**
     * Month-to-date total in $currency (display cents) over the given lists. One grouped SUM query (not
     * one row per spend) + one cached rate per distinct base currency.
     *
     * @param  Collection<int, string>  $eventCcy  id => base currency
     */
    public static function total(Collection $eventCcy, string $currency): int
    {
        if ($eventCcy->isEmpty()) {
            return 0;
        }
        $sums = Spend::query()
            ->whereIn('spend_event_id', $eventCcy->keys())
            ->countsInMonthly() // project-only expenses stay out of the monthly figure
            // Same bounds as analyticsPeriod('mtd'): start of month .. end of today (spent_at may carry a time).
            ->whereBetween('spent_at', [now()->startOfMonth()->toDateString(), now()->endOfDay()->toDateTimeString()])
            ->groupBy('spend_event_id')
            ->selectRaw('spend_event_id, SUM(base_amount) AS total')
            ->pluck('total', 'spend_event_id');

        $total = 0;
        foreach ($sums as $eventId => $baseCents) {
            $total += (int) round((int) $baseCents * Fx::rate($eventCcy[$eventId], $currency));
        }

        return $total;
    }

    /**
     * Month-to-date total PER SHOP in $currency (display cents), same lists/bounds/rounding as total(). Feeds
     * the "This month at Lidl: …" hint on the add-expense forms: the client looks up whatever shop is in
     * the form, so the hint recomputes as the shop changes without a round-trip. Only shops with a store
     * set are keyed (no-store spends have nothing to hint about). Amounts are the ALREADY-recorded spends,
     * so the expense being typed is never counted.
     *
     * @param  Collection<int, string>  $eventCcy  id => base currency
     * @return array<string, int> shop name => display cents
     */
    public static function byShop(Collection $eventCcy, string $currency): array
    {
        if ($eventCcy->isEmpty()) {
            return [];
        }
        $rows = Spend::query()
            ->whereIn('spend_event_id', $eventCcy->keys())
            ->countsInMonthly() // same exclusion as total(), so the hint and the widget agree
            ->whereNotNull('store')
            ->whereBetween('spent_at', [now()->startOfMonth()->toDateString(), now()->endOfDay()->toDateTimeString()])
            ->groupBy('store', 'spend_event_id')
            ->selectRaw('store, spend_event_id, SUM(base_amount) AS total')
            ->get();

        $totals = [];
        foreach ($rows as $r) {
            $totals[$r->store] = ($totals[$r->store] ?? 0) + (int) round((int) $r->total * Fx::rate($eventCcy[$r->spend_event_id], $currency));
        }

        return $totals;
    }

    /**
     * Home's spending card: how many expenses this month and the month-to-date split by week (Monday-start
     * weeks touching this month, up to the current one; display cents, same lists/bounds/rounding as total()).
     * The last entry is "this week".
     *
     * ponytail: fetches the month's rows instead of a SQL GROUP BY week, because week truncation differs between
     * sqlite (tests) and pgsql; a family's month is a few hundred rows at most. Move to SQL if that ever grows.
     *
     * @param  Collection<int, string>  $eventCcy  id => base currency
     * @return array{count: int, weeks: list<int>}
     */
    public static function weeks(Collection $eventCcy, string $currency): array
    {
        $first = now()->startOfMonth()->startOfWeek();
        $weeks = array_fill(0, (int) $first->diffInWeeks(now()->startOfWeek()) + 1, 0);
        if ($eventCcy->isEmpty()) {
            return ['count' => 0, 'weeks' => $weeks];
        }
        $rows = Spend::query()
            ->whereIn('spend_event_id', $eventCcy->keys())
            ->countsInMonthly()
            ->whereBetween('spent_at', [now()->startOfMonth()->toDateString(), now()->endOfDay()->toDateTimeString()])
            ->get(['spend_event_id', 'spent_at', 'base_amount']);

        foreach ($rows as $r) {
            $i = (int) $first->diffInWeeks($r->spent_at->copy()->startOfWeek());
            $weeks[$i] += (int) round($r->base_amount * Fx::rate($eventCcy[$r->spend_event_id], $currency));
        }

        return ['count' => $rows->count(), 'weeks' => $weeks];
    }
}
