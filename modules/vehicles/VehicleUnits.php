<?php

namespace Modules\Vehicles;

/**
 * The single place distance crosses between storage and display. Every odometer/interval value in the
 * module is STORED in kilometres; this converts to the family's chosen display unit (see
 * VehicleSetting::unit_system). Vehicles has no larger unit framework — one axis (distance), two systems.
 *
 * Kept as pure scalar functions (no Eloquent, no DB) so it is trivially testable and can be reused by the
 * server-rendered dashboard-widget feed and by tests. The client mirror lives in resources/js/lib/vehicles.ts.
 */
class VehicleUnits
{
    // 1 mile = 1.60934 km. One constant, used both ways.
    public const KM_PER_MILE = 1.60934;

    /** The short distance-unit label for a unit system ("km" / "mi"). */
    public static function label(string $unitSystem): string
    {
        return $unitSystem === 'imperial' ? 'mi' : 'km';
    }

    /**
     * Convert a stored km distance into the display unit's numeric value (rounded to a whole unit — an
     * odometer is never shown with decimals). Metric is identity; imperial divides by KM_PER_MILE.
     */
    public static function fromKm(int $km, string $unitSystem): int
    {
        return $unitSystem === 'imperial'
            ? (int) round($km / self::KM_PER_MILE)
            : $km;
    }

    /**
     * A ready-to-show distance string in the family's unit ("142,340 km" / "88,447 mi"). Thousands-grouped
     * with the en-US separator, matching the client's fmtMileage so server + client read identically.
     */
    public static function distanceText(int $km, string $unitSystem): string
    {
        return number_format(self::fromKm($km, $unitSystem)).' '.self::label($unitSystem);
    }
}
