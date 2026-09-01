<?php

namespace Modules\Vehicles;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The computed fuel metrics for a vehicle (V1.1). Pure functions over a set of fuel entries — no Eloquent
 * writes, no "now" dependence beyond the entries' own dates — so it is trivially testable.
 *
 * Consumption uses the FULL-to-FULL method, the only accurate way with periodic fills: L/100km is measured
 * between two consecutive FULL-tank fills, where the litres burned over that segment are everything pumped
 * AFTER the earlier full tank up to and including the later one, and the distance is the odometer delta.
 * A partial fill contributes its litres to the current segment but doesn't itself close a measurement.
 *
 * Every value is METRIC (km, litres, L/100km, currency-per-km). Display conversion to miles/gallons/mpg
 * happens client-side (lib/vehicles.ts), mirroring how distance is stored in km and converted only to show.
 */
class FuelStats
{
    /**
     * @param  Collection<int, VehicleFuelEntry>  $entries  the vehicle's fuel entries (any order)
     * @return array{
     *   entryCount:int, totalLiters:float, totalCost:float,
     *   latestConsumption:?float, avgConsumption:?float, costPerKm:?float,
     *   distanceTracked:?int, monthlyCost:?float, annualCost:?float
     * }
     */
    public static function forEntries(Collection $entries): array
    {
        // Order by odometer (then date, then id) — consumption reads fills in the order they were driven.
        $ordered = $entries->sortBy([['mileage', 'asc'], ['filled_on', 'asc'], ['id', 'asc']])->values();

        $entryCount = $ordered->count();
        $totalLiters = round((float) $ordered->sum('liters'), 2);

        $costEntries = $ordered->filter(fn (VehicleFuelEntry $e) => $e->cost !== null && $e->cost > 0);
        $totalCost = round((float) $costEntries->sum('cost'), 2);

        // ---- Consumption: walk full-to-full segments -------------------------------------------------
        $segments = [];        // each: ['distance' => int km, 'liters' => float]
        $lastFull = null;      // the previous FULL-tank fill
        $accum = 0.0;          // litres pumped since $lastFull (excludes $lastFull's own litres)

        foreach ($ordered as $fill) {
            if ($lastFull !== null) {
                // This fill's fuel refilled what was burned in the segment ending at it.
                $accum += (float) $fill->liters;
            }
            if ($fill->is_full_tank) {
                if ($lastFull !== null) {
                    $distance = (int) $fill->mileage - (int) $lastFull->mileage;
                    if ($distance > 0) {
                        $segments[] = ['distance' => $distance, 'liters' => $accum];
                    }
                }
                $lastFull = $fill;
                $accum = 0.0;   // start a fresh segment from this full tank
            }
        }

        $latestConsumption = null;
        $avgConsumption = null;
        if ($segments !== []) {
            $last = end($segments);
            $latestConsumption = round($last['liters'] / $last['distance'] * 100, 2);

            $segDistance = array_sum(array_column($segments, 'distance'));
            $segLiters = array_sum(array_column($segments, 'liters'));
            if ($segDistance > 0) {
                $avgConsumption = round($segLiters / $segDistance * 100, 2);
            }
        }

        // ---- Cost per km + monthly/annual projection -------------------------------------------------
        // Distance tracked = odometer span across all fills (needs at least two readings).
        $distanceTracked = $entryCount >= 2
            ? (int) $ordered->last()->mileage - (int) $ordered->first()->mileage
            : null;

        $costPerKm = ($distanceTracked !== null && $distanceTracked > 0 && $totalCost > 0)
            ? round($totalCost / $distanceTracked, 4)
            : null;

        // Monthly/annual fuel cost: average spend over the period the costed fills actually span. Requires a
        // month of history so a couple of close fills can't project a wild annual figure.
        $monthlyCost = null;
        $annualCost = null;
        if ($costEntries->count() >= 2 && $totalCost > 0) {
            $first = Carbon::parse($costEntries->min('filled_on'));
            $last = Carbon::parse($costEntries->max('filled_on'));
            $days = $first->diffInDays($last);
            if ($days >= 30) {
                $monthlyCost = round($totalCost / ($days / 30.44), 2);
                $annualCost = round($monthlyCost * 12, 2);
            }
        }

        return [
            'entryCount' => $entryCount,
            'totalLiters' => $totalLiters,
            'totalCost' => $totalCost,
            'latestConsumption' => $latestConsumption,
            'avgConsumption' => $avgConsumption,
            'costPerKm' => $costPerKm,
            'distanceTracked' => $distanceTracked,
            'monthlyCost' => $monthlyCost,
            'annualCost' => $annualCost,
        ];
    }
}
