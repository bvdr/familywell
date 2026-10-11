<?php

namespace Modules\Spending\Mcp;

use App\Events\SpendingChanged;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Database\Eloquent\Builder;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

// Shared lookups + payload shapes for the Spending MCP tools. Access follows the web UI: an expense is
// readable when its list is viewable and changeable when the list is editable (ShareAccess, as
// SpendingController does); the family scope comes from CurrentFamily.
class SpendData
{
    /** Lists (spend events) this person can see, archived included (a finished trip's spends still count). */
    public static function visibleEvents(User $user): Builder
    {
        return SpendEvent::query()->visibleTo($user->id);
    }

    public static function event(User $user, mixed $id, bool $edit = false): ?SpendEvent
    {
        $event = SpendEvent::find((int) $id);
        $access = app(ShareAccess::class);

        return $event && ($edit ? $access->canEdit($user, $event) : $access->canView($user, $event)) ? $event : null;
    }

    public static function spend(User $user, mixed $id, bool $edit = false): ?Spend
    {
        $spend = Spend::with(['event', 'payer', 'payerGuest'])->find((int) $id);

        return $spend && $spend->event && self::event($user, $spend->event->id, $edit) ? $spend : null;
    }

    /** Money as a decimal string (minor units in the DB). */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function payload(Spend $s): array
    {
        $payer = $s->payerGuest ?? $s->payer;

        return [
            'id' => $s->id,
            'amount' => self::money($s->amount),
            'currency' => $s->currency,
            // Converted into the list's own currency at the spend's date (what balances use).
            'amount_in_list_currency' => self::money((int) $s->base_amount),
            'list_currency' => $s->event?->currency,
            'title' => $s->note,
            'category' => $s->category,
            'shop' => $s->store,
            'date' => $s->spent_at?->toDateString(),
            'paid_by' => $payer ? ['key' => $s->participantKey(), 'name' => $payer->name] : null,
            'list' => $s->event ? ['id' => $s->event->id, 'name' => $s->event->name] : null,
            // Effective "count in monthly spending": the expense's own override, else its list's setting.
            'count_in_monthly' => $s->count_in_monthly ?? (bool) $s->event?->count_in_monthly,
            'url' => self::url("/m/spending/events/{$s->spend_event_id}"),
        ];
    }

    public static function trashInfo(Spend $s): array
    {
        return [
            'deleted_at' => $s->deleted_at->toIso8601String(),
            'purged_on' => $s->deleted_at->copy()->addDays(Trash::days())->toDateString(),
        ];
    }

    public static function changed(): void
    {
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
