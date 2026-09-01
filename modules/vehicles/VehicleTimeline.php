<?php

namespace Modules\Vehicles;

use Illuminate\Support\Collection;

/**
 * The Vehicle Timeline (spec 16): the life of one car as a single chronological feed across ALL event
 * types — mileage readings, maintenance performed, repairs, documents added and (ad-hoc) expenses —
 * merged and sorted newest-first. Each entry is a flat, uniform row the client groups by year/month.
 *
 * Money is shown ONCE per real-world event: a maintenance completion and a repair carry their own cost,
 * so the only expenses surfaced as their own entries are the ad-hoc ones (an "Add expense" not tied to a
 * maintenance record, and not a repair's mirrored cost — filtered via maintenance_history_id / repair_id).
 * Everything reads through the family-scoped relations, so the feed is inherently family-scoped.
 */
class VehicleTimeline
{
    /**
     * The full timeline for a vehicle, newest first. Pure data (no HTML) — labels are localised client-side
     * from `type`, so this stays testable and small.
     *
     * @return list<array{id:string, type:string, date:?string, title:string, subtitle:?string, mileage:?int, cost:?float}>
     */
    public static function forVehicle(Vehicle $vehicle): array
    {
        $entries = collect();

        // Maintenance performed — the service history rows (name snapshotted, may carry cost + provider).
        foreach ($vehicle->maintenanceHistory as $h) {
            $entries->push([
                'id' => 'maintenance-'.$h->id,
                'type' => 'maintenance',
                'date' => optional($h->performed_at)->toDateString(),
                'title' => $h->name,
                'subtitle' => $h->providerName(),
                'mileage' => $h->mileage,
                'cost' => $h->cost !== null ? (float) $h->cost : null,
            ]);
        }

        // Repairs — unplanned fixes (permanent history).
        foreach ($vehicle->repairs as $r) {
            $entries->push([
                'id' => 'repair-'.$r->id,
                'type' => 'repair',
                'date' => optional($r->occurred_on)->toDateString(),
                'title' => $r->title,
                'subtitle' => $r->providerName(),
                'mileage' => $r->mileage,
                'cost' => $r->cost !== null ? (float) $r->cost : null,
            ]);
        }

        // Documents added — dated by when the paperwork was filed (created_at), not its expiry.
        foreach ($vehicle->documents as $d) {
            $entries->push([
                'id' => 'document-'.$d->id,
                'type' => 'document',
                'date' => optional($d->created_at)->toDateString(),
                'title' => $d->title,
                'subtitle' => $d->type,     // localised client-side (doctype_<type>)
                'mileage' => null,
                'cost' => null,
            ]);
        }

        // Ad-hoc expenses only: a Spend linked to the car that is NOT already represented by a maintenance
        // completion (maintenance_history_id), a repair (repair_id) or a fuel fill (fuel_entry_id). Eager-
        // loads the spend so the cost + date + note come from the real Spending record.
        $expenses = VehicleExpenseLink::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('maintenance_history_id')
            ->whereNull('repair_id')
            ->whereNull('fuel_entry_id')
            ->with('spend')
            ->get();
        foreach ($expenses as $link) {
            $spend = $link->spend;
            if (! $spend) {
                continue;
            }
            $entries->push([
                'id' => 'expense-'.$link->id,
                'type' => 'expense',
                'date' => optional($spend->spent_at)->toDateString(),
                'title' => $spend->note ?: '',
                'subtitle' => null,
                'mileage' => null,
                'cost' => $spend->base_amount !== null ? $spend->base_amount / 100 : null,   // minor units -> decimal
            ]);
        }

        // Tyre changes (V1.1) — each mounted set stamps when/at-what-mileage it went on; surface that as a
        // "Winter tyres" change event. Only sets that were actually mounted (have an install point) appear.
        foreach ($vehicle->tireSets as $set) {
            if ($set->installed_on === null) {
                continue;
            }
            $season = ucfirst(str_replace('_', ' ', (string) $set->season));
            $entries->push([
                'id' => 'tire-'.$set->id,
                'type' => 'tire',
                'date' => optional($set->installed_on)->toDateString(),
                'title' => trim("{$season} tyres".($set->brand ? " · {$set->brand}" : '')),
                'subtitle' => $set->size,
                'mileage' => $set->installed_mileage,
                'cost' => null,
            ]);
        }

        // Mileage readings — the odometer history (the opening reading + every quick update / correction).
        foreach ($vehicle->mileageLogs as $log) {
            $entries->push([
                'id' => 'mileage-'.$log->id,
                'type' => 'mileage',
                'date' => optional($log->recorded_at)->toDateString(),
                'title' => '',           // the mileage IS the content; client renders it from `mileage`
                'subtitle' => null,
                'mileage' => $log->mileage,
                'cost' => null,
            ]);
        }

        return self::sorted($entries)->values()->all();
    }

    /**
     * Newest-first, with a stable tie-break so entries on the same day keep a deterministic order
     * (dateless entries sort last). Sorting a plain array here — the sets are per-vehicle and small.
     */
    private static function sorted(Collection $entries): Collection
    {
        return $entries->sort(function (array $a, array $b) {
            // Nulls last: an entry with no date can't be placed in time.
            if ($a['date'] === $b['date']) {
                return strcmp($b['id'], $a['id']);   // stable, deterministic within a day
            }
            if ($a['date'] === null) {
                return 1;
            }
            if ($b['date'] === null) {
                return -1;
            }

            return strcmp($b['date'], $a['date']);   // Y-m-d strings compare chronologically
        });
    }
}
