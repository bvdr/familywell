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
            ?: ($eventCcy->countBy()->sortDesc()->keys()->first() ?? SpendingController::CURRENCIES[0]);
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
}
