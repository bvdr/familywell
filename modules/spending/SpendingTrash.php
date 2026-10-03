<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Spending trash: deleted expenses on lists the person can see; restoring needs edit rights on the list
// (same as deleting). Titled like the expense feed: the note, else the shop, else the category.
class SpendingTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return Spend::onlyTrashed()->with('event')
            ->whereIn('spend_event_id', SpendEvent::query()->visibleTo($user->id)->select('id'))
            ->get()
            ->map(fn (Spend $s) => Trash::row($s, 'expense', self::title($s), $s->event?->name) + [
                'amount' => number_format($s->amount / 100, 2, '.', '').' '.$s->currency,
            ]);
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
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
