<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Support\Realtime;
use App\Support\TypeSafe;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

// The on-add half of AI categorisation: an expense saved into the catch-all "Other" gets one Jev
// check right after the response is sent and moves to the category it picks (same categoriser and
// 0.6 threshold as the bulk "Sort Other" tool). Same shape as Modules\Shopping\AisleGuesser: a
// per-request buffer + one named defer(), so the add stays instant and Octane never re-flushes a
// stale buffer. Gated on TYPESAFE_API_KEY; without it expenses just stay where you put them.
class SpendAutoCategorizer
{
    /** @var array<int, Spend> expenses created during this request that sit in "other" */
    private static array $pending = [];

    public static function queue(Spend $spend): void
    {
        if (! TypeSafe::configured() || $spend->category !== 'other') {
            return;
        }
        self::$pending[$spend->id] = $spend;
        defer(fn () => self::flush(), 'spending.auto-categorize');
    }

    public static function flush(): void
    {
        $spends = self::$pending;
        self::$pending = []; // reset first: Octane keeps statics across requests

        $touched = [];
        // Eloquent collection (not collect()) so loadMissing() exists on each group.
        foreach (EloquentCollection::make($spends)->groupBy('family_id') as $familyId => $group) {
            SpendCategory::ensureDefaults((int) $familyId);
            $categories = SpendCategory::withoutGlobalScope('family')->where('family_id', $familyId)->orderBy('position')->get();
            $group->loadMissing('source');

            foreach (SpendCategorizer::categorize($group, $categories) as $id => $key) {
                // `category = other` guard: a manual choice made in the meantime wins.
                if (Spend::withoutGlobalScope('family')->whereKey($id)->where('category', 'other')->update(['category' => $key])) {
                    $touched[$familyId] = true;
                }
            }
        }

        foreach (array_keys($touched) as $familyId) {
            Realtime::broadcast(new SpendingChanged((int) $familyId));
        }
    }
}
