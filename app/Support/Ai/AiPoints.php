<?php

namespace App\Support\Ai;

use App\Models\Family;
use App\Support\Billing\Billing;
use Illuminate\Support\Facades\DB;

/**
 * A family's monthly AI allowance (config familywell.tiers.*.ai_points): one point per finished AI action. The pool is
 * the family's, because the family pays; the ledger still says which member spent each point. Rules:
 *  - billing off (self-hosted): no limit;
 *  - comped (lifetime): Pro's allowance; otherwise the subscribed tier, and no tier (free period, never subscribed,
 *    the demo) reads as Basic, like Billing does.
 * Resets on the 1st (calendar month, the app's timezone).
 */
final class AiPoints
{
    /** The monthly allowance, or null for unlimited. */
    public static function limit(Family $family): ?int
    {
        if (! Billing::enabled()) {
            return null;
        }
        $tier = Billing::isLifetime($family) ? 'pro' : ($family->subscription_tier ?: 'basic');

        return (int) config("familywell.tiers.{$tier}.ai_points", config('familywell.tiers.basic.ai_points'));
    }

    public static function used(Family $family): int
    {
        return (int) DB::table('ai_usage')->where('family_id', $family->id)
            ->where('created_at', '>=', now()->startOfMonth())->sum('points');
    }

    /** Points left this month, or null for unlimited. */
    public static function left(Family $family): ?int
    {
        $limit = self::limit($family);

        return $limit === null ? null : max(0, $limit - self::used($family));
    }

    public static function canSpend(Family $family, int $points = 1): bool
    {
        $left = self::left($family);

        return $left === null || $left >= $points;
    }

    /**
     * Take points for a finished action (a ledger row with no model cost: the calls behind it were recorded as they
     * happened). ponytail: check-then-charge isn't atomic, so two simultaneous last actions can both pass; at one
     * point each that overshoots by a point, a row lock is the upgrade if it ever matters.
     */
    public static function charge(string $feature, int $points = 1): void
    {
        AiUsage::record($feature, 'familywell', 'points', 0, 0, $points);
    }
}
