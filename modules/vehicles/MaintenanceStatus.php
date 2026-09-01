<?php

namespace Modules\Vehicles;

use Carbon\CarbonInterface;

/**
 * The maintenance due-logic engine — the heart of the module. A schedule has TWO simultaneous triggers,
 * distance (every `interval_km`) and time (every `interval_months`), measured from the last-performed
 * baseline. It becomes due when EITHER trigger is reached; the overall status is the MORE urgent of the
 * two. Kept as a pure function of scalars (no Eloquent, no DB) so the logic is trivially unit-testable —
 * VehicleMaintenanceSchedule::statusFor() just feeds it the model's columns + the vehicle odometer.
 *
 * Worked example (spec 3): oil change, last done at 134,200 km / Mar 12 2026, interval 15,000 km OR 12
 * months → next due 149,200 km OR Mar 12 2027. At 142,340 km today the km trigger has 6,860 km remaining
 * (good), the time trigger months remaining (good) → overall "good".
 */
class MaintenanceStatus
{
    // "Due soon" windows — a trigger within this distance / time of its next-due point is flagged amber
    // (spec: <= 1000 km or <= 30 days). The same window, on the far side of the due point, separates a
    // freshly-"due" service from one that is now "overdue" (past due by more than the window).
    public const SOON_KM = 1000;

    public const SOON_DAYS = 30;

    // The four statuses, ordered by urgency (good < due_soon < due < overdue). RANK is used both to pick
    // the worse of a schedule's two triggers and to roll many schedules up into one vehicle status.
    public const GOOD = 'good';

    public const DUE_SOON = 'due_soon';

    public const DUE = 'due';

    public const OVERDUE = 'overdue';

    private const RANK = [self::GOOD => 0, self::DUE_SOON => 1, self::DUE => 2, self::OVERDUE => 3];

    /**
     * Compute the full due picture for one schedule.
     *
     * A trigger is "active" only when both its interval AND its baseline are known (you can't measure km
     * remaining without the last-performed odometer). A schedule with no active trigger at all — the
     * never-performed case — is surfaced as DUE: it exists because the maintenance matters, so it needs
     * its first record rather than being hidden.
     *
     * @return array{status:string, nextDueMileage:?int, nextDueDate:?string, remainingKm:?int, remainingDays:?int, neverPerformed:bool}
     */
    public static function compute(
        ?int $intervalKm,
        ?int $intervalMonths,
        ?int $lastMileage,
        ?CarbonInterface $lastAt,
        int $currentMileage,
        CarbonInterface $today,
    ): array {
        $kmActive = $intervalKm !== null && $lastMileage !== null;
        $timeActive = $intervalMonths !== null && $lastAt !== null;

        $nextDueMileage = $kmActive ? $lastMileage + $intervalKm : null;
        $remainingKm = $kmActive ? $nextDueMileage - $currentMileage : null;

        $nextDueDate = $timeActive ? $lastAt->copy()->startOfDay()->addMonths($intervalMonths) : null;
        // Signed whole days from today to the due date (positive = still ahead, negative = past). Both
        // sides normalised to start-of-day so the count is calendar days, not affected by the time part.
        $remainingDays = $timeActive
            ? (int) $today->copy()->startOfDay()->diffInDays($nextDueDate, false)
            : null;

        $neverPerformed = $lastMileage === null && $lastAt === null;

        $statuses = [];
        if ($kmActive) {
            $statuses[] = self::triggerStatus($remainingKm, self::SOON_KM);
        }
        if ($timeActive) {
            $statuses[] = self::triggerStatus($remainingDays, self::SOON_DAYS);
        }

        // Overall = the most urgent active trigger (EITHER reaching threshold makes the service due). With
        // no computable trigger (never performed, or the only interval set has no baseline), it is due.
        $status = $statuses === []
            ? self::DUE
            : array_reduce($statuses, fn (string $carry, string $s) => self::RANK[$s] > self::RANK[$carry] ? $s : $carry, self::GOOD);

        return [
            'status' => $status,
            'nextDueMileage' => $nextDueMileage,
            'nextDueDate' => $nextDueDate?->toDateString(),
            'remainingKm' => $remainingKm,
            'remainingDays' => $remainingDays,
            'neverPerformed' => $neverPerformed,
        ];
    }

    /**
     * One trigger's status from how much is left before its next-due point (km or days). At or past the
     * point (<= 0) it is DUE, and OVERDUE once past by more than the "soon" window; still ahead, it is
     * DUE_SOON within the window and GOOD beyond it.
     */
    private static function triggerStatus(int $remaining, int $soon): string
    {
        if ($remaining <= 0) {
            return $remaining < -$soon ? self::OVERDUE : self::DUE;
        }

        return $remaining <= $soon ? self::DUE_SOON : self::GOOD;
    }

    /** The more urgent of two statuses (used to roll schedules up into a vehicle-level status). */
    public static function worse(string $a, string $b): string
    {
        return self::RANK[$a] >= self::RANK[$b] ? $a : $b;
    }

    /** Numeric urgency of a status (good=0 … overdue=3), for ordering "which service is next". */
    public static function rank(string $status): int
    {
        return self::RANK[$status] ?? 0;
    }

    /**
     * Map an engine status onto the coarser three-state vehicle card dot (good / service_due / overdue):
     * both "due soon" and "due" read as the amber "service due", only past-due is red.
     */
    public static function toVehicleState(string $status): string
    {
        return match ($status) {
            self::OVERDUE => 'overdue',
            self::DUE, self::DUE_SOON => 'service_due',
            default => 'good',
        };
    }

    /** Whether a status counts as "needs attention now" (due or overdue), for the card/widget counts. */
    public static function needsAttention(string $status): bool
    {
        return $status === self::DUE || $status === self::OVERDUE;
    }
}
