<?php

namespace Modules\Vehicles;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Spending\SpendSetting;

/**
 * V1.1 Phase 3: per-vehicle COST & CONSUMPTION analytics. Derives everything from data already recorded in
 * earlier phases — the vehicle->Spending links (VehicleExpenseLink), the fuel log (FuelStats), the odometer
 * history (mileage_log) and the maintenance schedules — so this module never adds a second financial system.
 *
 * The money side is currency-consistent by construction: every car expense mirrors into ONE per-family
 * "Vehicles" spend event with a single currency (VehicleExpenses), so each linked spend's frozen
 * `base_amount` is already in that currency. Summing base_amount therefore needs no FX at all — the "display
 * currency" IS the Vehicles event currency (the family's spending default). All money is in MINOR UNITS
 * (cents), exactly like the Spending analytics screen it visually mirrors.
 *
 * Distance stays METRIC (km) here; the client converts to the family's unit for display (lib/vehicles.ts),
 * the same split the rest of the module uses. Estimates are explicitly derived and clearly labelled — we do
 * not pretend to cent/kilometre precision on anything projected into the future.
 */
class VehicleAnalytics
{
    /**
     * The expense TYPES the per-vehicle spend breaks down into, derived from which link FK is set on each
     * VehicleExpenseLink (a spend belongs to exactly one). A link with no typed FK is an ad-hoc car expense
     * (the Overview "Add expense", an obligation, …) and falls into 'other'. Order = display order.
     */
    public const TYPES = ['maintenance', 'repair', 'fuel', 'parts', 'other'];

    /**
     * The full analytics payload for a vehicle over a resolved [start, end] range. Pure read logic over the
     * already-family-scoped links + logs; the caller resolves the period (see range()).
     *
     * @return array{
     *   currency:string, total:int, count:int,
     *   byType:list<array{key:string, amount:int, pct:int}>,
     *   daily:list<array{date:string, amount:int}>, granularity:string,
     *   kmDriven:?int, costPerKm:?float
     * }
     */
    public static function forVehicle(Vehicle $vehicle, Carbon $start, Carbon $end): array
    {
        // Whole-day end bound: spent_at can carry a time, so a plain date end would drop same-day spends
        // (mirrors the Spending analytics query). Index still applies to the range.
        $endBound = $end->copy()->endOfDay();

        // Query the smaller dataset: only this vehicle's expense links whose spend falls in range, with just
        // the spend columns the roll-up needs. A vehicle rarely has more than a few dozen links.
        $links = VehicleExpenseLink::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereHas('spend', fn ($q) => $q->whereBetween('spent_at', [$start->toDateString(), $endBound->toDateTimeString()]))
            ->with('spend:id,base_amount,currency,spent_at')
            ->get();

        // Display currency = the Vehicles event's currency (all linked spends share it). Fall back to the
        // family's spending default when there are no spends yet, so the header still reads sensibly.
        $currency = $links->first()?->spend?->currency
            ?? SpendSetting::defaultCurrency((int) $vehicle->family_id)
            ?? 'EUR';

        // Long ranges group the trend by month, else by day — same 90-day threshold the Spending screen uses.
        $byMonth = $start->diffInDays($end) > 90;
        $granularity = $byMonth ? 'month' : 'day';

        $total = 0;
        $count = 0;
        $byType = array_fill_keys(self::TYPES, 0);
        $bucket = [];

        foreach ($links as $link) {
            $spend = $link->spend;
            if (! $spend) {
                continue;
            }
            $cents = (int) $spend->base_amount;
            $total += $cents;
            $count++;
            $byType[self::typeOf($link)] += $cents;

            $key = $byMonth
                ? $spend->spent_at->copy()->startOfMonth()->toDateString()
                : $spend->spent_at->toDateString();
            $bucket[$key] = ($bucket[$key] ?? 0) + $cents;
        }

        // byType as sorted rows with a percentage of the (period) total, dropping empty types so the chart
        // shows only what the car actually cost money on.
        $byTypeRows = collect(self::TYPES)
            ->map(fn (string $key) => [
                'key' => $key,
                'amount' => $byType[$key],
                'pct' => $total > 0 ? (int) round($byType[$key] / $total * 100) : 0,
            ])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->values()
            ->all();

        // Operating cost per km over the period: total spend / km driven in the window (null when we can't
        // measure the distance driven — e.g. too few odometer readings). Kept in cents-per-km so it shares
        // the money basis; the client formats it in the display currency + unit.
        $kmDriven = self::kmDriven($vehicle, $start, $endBound);
        $costPerKm = ($kmDriven !== null && $kmDriven > 0 && $total > 0)
            ? round($total / $kmDriven, 2)
            : null;

        return [
            'currency' => $currency,
            'total' => $total,
            'count' => $count,
            'byType' => $byTypeRows,
            'daily' => self::zeroFilled($bucket, $start, $end, $byMonth),
            'granularity' => $granularity,
            'kmDriven' => $kmDriven,
            'costPerKm' => $costPerKm,
        ];
    }

    /**
     * The compact headline block the vehicle Overview shows (spec: "a couple of headline stats"): the
     * year-to-date car spend, the operating cost per km, and the average fuel consumption. Consistent with
     * the full Costs screen because it comes from the same source.
     *
     * @return array{currency:string, ytdTotal:int, ytdCostPerKm:?float, avgConsumption:?float, hasData:bool}
     */
    public static function compact(Vehicle $vehicle, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $ytd = self::forVehicle($vehicle, $today->copy()->startOfYear(), $today->copy());
        $fuel = FuelStats::forEntries($vehicle->fuelEntries()->get());

        return [
            'currency' => $ytd['currency'],
            'ytdTotal' => $ytd['total'],
            'ytdCostPerKm' => $ytd['costPerKm'],
            'avgConsumption' => $fuel['avgConsumption'],
            // Whether there's anything worth showing at all (so the Overview can hide an empty panel).
            'hasData' => $ytd['total'] > 0 || $fuel['avgConsumption'] !== null,
        ];
    }

    /**
     * The ESTIMATIONS block — everything projected into the future, kept deliberately modest and clearly
     * labelled as estimates on the screen. Two derived guesses:
     *
     *  - nextService: the soonest active maintenance schedule (name + km/date remaining), straight from the
     *    existing due-logic rollup (VehicleOverview::next) — an estimate only in that "when" depends on how
     *    the car is actually driven.
     *  - nextFuelFill: from the average interval between the recorded fills (needs >= 2). estimatedDate =
     *    last fill + average gap; avgKmBetweenFills = the mean odometer step. A projection, not a promise.
     *
     * @return array{nextService:?array<string,mixed>, nextFuelFill:?array{estimatedDate:string, days:int, avgKmBetweenFills:?int}}
     */
    public static function estimates(Vehicle $vehicle, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        return [
            'nextService' => VehicleOverview::next($vehicle, $today)['maintenance'],
            'nextFuelFill' => self::nextFuelFill($vehicle->fuelEntries()->get(), $today),
        ];
    }

    /**
     * Resolve the analytics period into a [label, start, end] Carbon range (inclusive days). Mirrors the
     * Spending analytics presets so the two screens read identically: mtd (default), last_month, ytd,
     * all_time (bounded to the car's real expense history), custom (from/to).
     *
     * @return array{0:string, 1:Carbon, 2:Carbon}
     */
    public static function range(Vehicle $vehicle, ?string $period, ?string $from = null, ?string $to = null): array
    {
        $today = Carbon::now();

        switch ($period) {
            case 'last_month':
                $start = $today->copy()->subMonthNoOverflow()->startOfMonth();

                return ['last_month', $start, $start->copy()->endOfMonth()];
            case 'ytd':
                return ['ytd', $today->copy()->startOfYear(), $today->copy()];
            case 'all_time':
                // Span the car's whole expense history (min..max linked-spend date). One aggregate query; a
                // car with no spends yet falls back to this month so the range stays a single valid period.
                $bounds = VehicleExpenseLink::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->join('spending_spends', 'spending_spends.id', '=', 'vehicles_expense_links.spend_id')
                    ->whereNull('spending_spends.deleted_at') // a raw join skips the SoftDeletes scope: leave trashed spends out
                    ->selectRaw('MIN(spending_spends.spent_at) as mn, MAX(spending_spends.spent_at) as mx')
                    ->first();
                if (! $bounds || ! $bounds->mn || ! $bounds->mx) {
                    return ['all_time', $today->copy()->startOfMonth(), $today->copy()];
                }

                return ['all_time', Carbon::parse($bounds->mn)->startOfDay(), Carbon::parse($bounds->mx)->startOfDay()];
            case 'custom':
                $start = self::parseDate($from) ?? $today->copy()->startOfMonth();
                $end = self::parseDate($to) ?? $today->copy();
                if ($end->lt($start)) {
                    [$start, $end] = [$end, $start];
                }

                return ['custom', $start, $end];
            case 'mtd':
            default:
                return ['mtd', $today->copy()->startOfMonth(), $today->copy()];
        }
    }

    // ---- internals -------------------------------------------------------------------------------------

    /** Which expense TYPE a link represents, from the single typed FK it carries (else 'other'). */
    private static function typeOf(VehicleExpenseLink $link): string
    {
        return match (true) {
            $link->fuel_entry_id !== null => 'fuel',
            $link->repair_id !== null => 'repair',
            $link->part_request_id !== null => 'parts',
            $link->maintenance_history_id !== null => 'maintenance',
            default => 'other',
        };
    }

    /**
     * Kilometres driven across [start, end], from the odometer history: the reading in effect at the window
     * end minus the reading in effect at its start. Returns null when there aren't two comparable readings
     * (so the cost-per-km headline reads "—" rather than a fabricated number).
     */
    private static function kmDriven(Vehicle $vehicle, Carbon $start, Carbon $endBound): ?int
    {
        $logs = $vehicle->mileageLogs()->get(['mileage', 'recorded_at'])
            ->sortBy('recorded_at')->values();
        if ($logs->isEmpty()) {
            return null;
        }

        // Odometer entering the window: the last reading before it starts; if there's none (the window
        // reaches back before any reading), use the earliest reading inside it as the opening value.
        $entering = $logs->last(fn ($l) => $l->recorded_at < $start);
        $odoStart = $entering
            ? (int) $entering->mileage
            : (int) ($logs->first(fn ($l) => $l->recorded_at <= $endBound)->mileage ?? $logs->first()->mileage);

        // Odometer at the window end: the last reading up to it (fall back to the current odometer).
        $atEnd = $logs->last(fn ($l) => $l->recorded_at <= $endBound);
        $odoEnd = $atEnd ? (int) $atEnd->mileage : (int) $vehicle->current_mileage;

        $driven = $odoEnd - $odoStart;

        return $driven > 0 ? $driven : null;
    }

    /**
     * Estimate the next fill from the average gap between recorded fills. Needs >= 2 fills. `days` is the
     * whole-day count from today to the projected date (clamped at 0 — a car overdue for a fill reads
     * "~0 days", never negative). avgKmBetweenFills is the mean odometer step, or null if it can't be read.
     *
     * @param  Collection<int, VehicleFuelEntry>  $entries
     * @return array{estimatedDate:string, days:int, avgKmBetweenFills:?int}|null
     */
    private static function nextFuelFill(Collection $entries, Carbon $today): ?array
    {
        $ordered = $entries->sortBy([['filled_on', 'asc'], ['mileage', 'asc'], ['id', 'asc']])->values();
        if ($ordered->count() < 2) {
            return null;
        }

        $first = Carbon::parse($ordered->first()->filled_on);
        $last = Carbon::parse($ordered->last()->filled_on);
        $spanDays = $first->diffInDays($last);
        if ($spanDays <= 0) {
            return null; // all fills on one day — no interval to average
        }

        $gaps = $ordered->count() - 1;
        $avgGapDays = $spanDays / $gaps;
        $estimatedDate = $last->copy()->addDays((int) round($avgGapDays));

        // Average odometer step between fills, when the readings are monotonic enough to mean anything.
        $kmSpan = (int) $ordered->last()->mileage - (int) $ordered->first()->mileage;
        $avgKm = $kmSpan > 0 ? (int) round($kmSpan / $gaps) : null;

        return [
            'estimatedDate' => $estimatedDate->toDateString(),
            'days' => max(0, (int) $today->copy()->startOfDay()->diffInDays($estimatedDate, false)),
            'avgKmBetweenFills' => $avgKm,
        ];
    }

    /**
     * Zero-fill the trend series across the whole range so the curve spans the period rather than hopping
     * between only the buckets that had spending — daily buckets, or one point per month for long ranges.
     *
     * @param  array<string,int>  $bucket
     * @return list<array{date:string, amount:int}>
     */
    private static function zeroFilled(array $bucket, Carbon $start, Carbon $end, bool $byMonth): array
    {
        $daily = [];
        if ($byMonth) {
            for ($m = $start->copy()->startOfMonth(); $m <= $end; $m->addMonthNoOverflow()) {
                $key = $m->toDateString();
                $daily[] = ['date' => $key, 'amount' => $bucket[$key] ?? 0];
            }
        } else {
            for ($d = $start->copy(); $d <= $end; $d->addDay()) {
                $key = $d->toDateString();
                $daily[] = ['date' => $key, 'amount' => $bucket[$key] ?? 0];
            }
        }

        return $daily;
    }

    /** Parse a Y-m-d string into a start-of-day Carbon, or null when absent/malformed. */
    private static function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
