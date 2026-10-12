<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Spending trash: deleted expenses and transfers on lists the person can see; restoring needs edit rights on the list
// (same as deleting). Titled like the expense feed: the note, else the shop, else the category.
class SpendingTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        $visible = SpendEvent::query()->visibleTo($user->id)->select('id');
        $expenses = Spend::onlyTrashed()->with('event')
            ->whereIn('spend_event_id', $visible)
            ->get()
            ->map(fn (Spend $s) => Trash::row($s, 'expense', self::title($s), $s->event?->name) + [
                'amount' => number_format($s->amount / 100, 2, '.', '').' '.$s->currency,
            ]);
        // Deleted transfers (settle-up payments), titled "From → To" with the list's currency.
        $transfers = SpendSettlement::onlyTrashed()->with('event')
            ->whereIn('spend_event_id', $visible)
            ->get()
            ->map(function (SpendSettlement $t) {
                $names = $t->event?->participantNames() ?? [];

                return Trash::row($t, 'transfer', ($names[$t->from_key] ?? '?').' → '.($names[$t->to_key] ?? '?'), $t->event?->name) + [
                    'amount' => number_format($t->amount / 100, 2, '.', '').' '.$t->event?->currency,
                ];
            });

        return $expenses->concat($transfers);
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        if ($type === 'transfer') {
            $transfer = SpendSettlement::onlyTrashed()->with('event')->find($id);
            if (! $transfer || ! $transfer->event || ! app(ShareAccess::class)->canEdit($user, $transfer->event)) {
                return null;
            }
            $transfer->restore();

            return "/m/spending/events/{$transfer->spend_event_id}";
        }
        $spend = $type === 'expense' ? Spend::onlyTrashed()->with('event')->find($id) : null;
        if (! $spend || ! $spend->event || ! app(ShareAccess::class)->canEdit($user, $spend->event)) {
            return null;
        }
        $spend->restore();

        return "/m/spending/events/{$spend->spend_event_id}";
    }

    private static function title(Spend $s): string
    {
        return trim((string) $s->note) ?: (trim((string) $s->store) ?: ucfirst($s->category));
    }
}
