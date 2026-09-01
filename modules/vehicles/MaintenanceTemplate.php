<?php

namespace Modules\Vehicles;

/**
 * Predefined maintenance templates for quick "add a schedule" (spec 4). Each is a starting point — a key,
 * a default distance interval and/or a default time interval — that pre-fills the form; the user can then
 * adjust the intervals and the baseline before saving. Kept as a code list (not a DB table) so the
 * catalogue ships with the app and adding one is a one-line change; the customisable per-family default
 * intervals of spec 20 are a P5 concern layered on top of these.
 *
 * Intervals are deliberate but conservative manufacturer-ish defaults; km-only items (brake pads, tires)
 * leave `months` null, time-only items (wipers, lights) leave `km` null. Labels are localised client-side
 * (vehicles.mtpl_<key>), so only the numeric defaults live here.
 */
class MaintenanceTemplate
{
    /** @var array<string, array{km:?int, months:?int}> */
    public const TEMPLATES = [
        'oil_change' => ['km' => 15000, 'months' => 12],
        'oil_filter' => ['km' => 15000, 'months' => 12],
        'air_filter' => ['km' => 30000, 'months' => 24],
        'cabin_filter' => ['km' => 15000, 'months' => 12],
        'fuel_filter' => ['km' => 30000, 'months' => 24],
        'brake_inspection' => ['km' => 20000, 'months' => 12],
        'brake_pads' => ['km' => 40000, 'months' => null],
        'brake_discs' => ['km' => 80000, 'months' => null],
        'tires' => ['km' => 50000, 'months' => null],
        'wheel_alignment' => ['km' => 20000, 'months' => 12],
        'battery' => ['km' => null, 'months' => 48],
        'coolant' => ['km' => 60000, 'months' => 36],
        'brake_fluid' => ['km' => 40000, 'months' => 24],
        'transmission_fluid' => ['km' => 60000, 'months' => 48],
        'spark_plugs' => ['km' => 40000, 'months' => 48],
        'timing_belt' => ['km' => 90000, 'months' => 60],
        'timing_chain_inspection' => ['km' => 100000, 'months' => 60],
        'ac_service' => ['km' => null, 'months' => 24],
        'wipers' => ['km' => null, 'months' => 12],
        'lights' => ['km' => null, 'months' => 12],
    ];

    /**
     * The template catalogue for the client's picker: [{key, intervalKm, intervalMonths}, …] in the
     * declared order. The label is resolved client-side from the key.
     *
     * @return list<array{key:string, intervalKm:?int, intervalMonths:?int}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $key => $def) {
            $out[] = ['key' => $key, 'intervalKm' => $def['km'], 'intervalMonths' => $def['months']];
        }

        return $out;
    }
}
