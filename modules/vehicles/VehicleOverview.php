<?php

namespace Modules\Vehicles;

use Illuminate\Support\Carbon;

/**
 * The per-car Overview roll-up (spec 15): one overall Good/Attention state, the "Next" up items (soonest
 * maintenance + soonest document/obligation expiry) and the "Recent" events feed. Pure read logic over the
 * vehicle's already-family-scoped relations, shared by the Overview screen AND the dashboard widget so the
 * two never disagree about what "needs attention" means.
 *
 * The overall state combines BOTH concerns the spec calls out — maintenance due/overdue AND document
 * expiries — into a single dot: "attention" when either has something live, "good" otherwise. This is a
 * strictly coarser view than the maintenance-only card dot (Vehicle::maintenanceSummary), which stays as
 * the distance/status rollup; Overview adds documents on top.
 */
class VehicleOverview
{
    /** Recent events shown on the Overview by default. */
    public const RECENT_LIMIT = 5;

    /**
     * The combined status: 'good' | 'attention', plus the two counts that drive it. Reads the loaded
     * maintenance schedules + documents; callers rendering many cars should eager-load both.
     *
     * @return array{overall:string, maintenanceAttention:int, documentsExpiring:int}
     */
    public static function status(Vehicle $vehicle, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $mileage = (int) $vehicle->current_mileage;

        $maintenanceAttention = 0;
        foreach ($vehicle->maintenanceSchedules as $schedule) {
            if (! $schedule->active) {
                continue;
            }
            if (MaintenanceStatus::needsAttention($schedule->statusFor($mileage, $today)['status'])) {
                $maintenanceAttention++;
            }
        }

        $documentsExpiring = $vehicle->documents
            ->filter(fn (VehicleDocument $d) => $d->isExpiring($today))
            ->count();

        return [
            'overall' => ($maintenanceAttention > 0 || $documentsExpiring > 0) ? 'attention' : 'good',
            'maintenanceAttention' => $maintenanceAttention,
            'documentsExpiring' => $documentsExpiring,
        ];
    }

    /**
     * The "Next" items: the single most-pressing active maintenance schedule (with its km/date remaining)
     * and the soonest-expiring document. Either may be null when there's nothing to show.
     *
     * @return array{maintenance:?array<string,mixed>, document:?array<string,mixed>}
     */
    public static function next(Vehicle $vehicle, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $mileage = (int) $vehicle->current_mileage;

        return [
            'maintenance' => self::nextMaintenance($vehicle, $mileage, $today),
            'document' => self::nextDocument($vehicle),
        ];
    }

    /**
     * Recent events across maintenance completions + repairs, newest first, each with its cost/mileage.
     * (Deliberately just these two — the Overview "Recent" is the service story, not the raw odometer feed;
     * the full cross-type history lives on the Timeline.)
     *
     * @return list<array{id:string, type:string, date:?string, title:string, cost:?float, mileage:?int}>
     */
    public static function recent(Vehicle $vehicle, int $limit = self::RECENT_LIMIT): array
    {
        $events = collect();

        foreach ($vehicle->maintenanceHistory as $h) {
            $events->push([
                'id' => 'maintenance-'.$h->id,
                'type' => 'maintenance',
                'date' => optional($h->performed_at)->toDateString(),
                'title' => $h->name,
                'cost' => $h->cost !== null ? (float) $h->cost : null,
                'mileage' => $h->mileage,
            ]);
        }
        foreach ($vehicle->repairs as $r) {
            $events->push([
                'id' => 'repair-'.$r->id,
                'type' => 'repair',
                'date' => optional($r->occurred_on)->toDateString(),
                'title' => $r->title,
                'cost' => $r->cost !== null ? (float) $r->cost : null,
                'mileage' => $r->mileage,
            ]);
        }

        return $events
            ->sortByDesc(fn (array $e) => ($e['date'] ?? '').'-'.$e['id'])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The most-pressing active schedule: the most urgent by status, tie-broken by the soonest trigger. Null
     * when the vehicle has no active schedule.
     *
     * @return array<string,mixed>|null
     */
    private static function nextMaintenance(Vehicle $vehicle, int $mileage, Carbon $today): ?array
    {
        $best = null;
        $bestKey = null;

        foreach ($vehicle->maintenanceSchedules as $schedule) {
            if (! $schedule->active) {
                continue;
            }
            $s = $schedule->statusFor($mileage, $today);

            // Sort key: status rank (more urgent first), then the smallest signed "remaining" across the
            // two triggers (soonest by distance or time — negative = already past). A never-performed
            // schedule (no computable remaining) still ranks via its DUE status, sorting near the top.
            $remaining = self::soonestRemaining($s);
            $key = [MaintenanceStatus::rank($s['status']), -($remaining ?? PHP_INT_MAX)];

            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = [
                    'id' => $schedule->id,
                    'name' => $schedule->name,
                    'type' => $schedule->type,
                    'status' => $s['status'],
                    'remainingKm' => $s['remainingKm'],
                    'remainingDays' => $s['remainingDays'],
                    'nextDueDate' => $s['nextDueDate'],
                    'nextDueMileage' => $s['nextDueMileage'],
                    'neverPerformed' => $s['neverPerformed'],
                ];
            }
        }

        return $best;
    }

    /** The soonest-expiring document (by expiry date), or null when none has an expiry. */
    private static function nextDocument(Vehicle $vehicle): ?array
    {
        $doc = $vehicle->documents
            ->filter(fn (VehicleDocument $d) => $d->expires_on !== null)
            ->sortBy(fn (VehicleDocument $d) => $d->expires_on->toDateString())
            ->first();

        if (! $doc) {
            return null;
        }

        return [
            'id' => $doc->id,
            'title' => $doc->title,
            'type' => $doc->type,
            'expiresOn' => optional($doc->expires_on)->toDateString(),
            'expiring' => $doc->isExpiring(),
        ];
    }

    /**
     * The smaller of a schedule's two signed "remaining" values (km, days) — whichever trigger is nearer
     * its due point (or further past it). Null when neither trigger is active. Used only for tie-breaking,
     * so mixing km and days on one axis is acceptable: both measure "how soon" and a smaller number is more
     * pressing either way.
     */
    private static function soonestRemaining(array $status): ?int
    {
        $values = array_values(array_filter(
            [$status['remainingKm'], $status['remainingDays']],
            fn ($v) => $v !== null,
        ));

        return $values === [] ? null : min($values);
    }
}
