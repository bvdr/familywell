<?php

namespace Modules\Spending;

use Illuminate\Support\Collection;

// Formats an event's balances + settle-up suggestions for the frontend, across BOTH family-member
// and guest participants. Participant keys are namespaced ('u{id}' / 'g{id}') so a user id and a
// guest id can never collide in the balance sheet. Shared by the owner view and the guest view so
// the money math and names are computed one way only.
class EventBalances
{
    /**
     * @param  Collection<int,Spend>  $spends
     * @param  Collection<int,SpendSettlement>  $settlements
     * @return array{balances: array<int,array>, suggestions: array<int,array>}
     */
    public static function of(SpendEvent $event, Collection $spends, Collection $settlements): array
    {
        $names = $event->participantNames();
        $split = $event->splitSettings(); // ['weights' => key=>int, 'coveredBy' => key=>key]

        $result = Balances::for(
            $split['weights'],
            $spends->map(fn (Spend $s) => [
                'payer' => $s->participantKey(),
                'amount' => $s->base_amount,
                'shares' => self::sharesOf($s), // null unless this spend overrides its split
            ])->all(),
            // Settlements are recorded between users only (an authenticated action), so they're all 'u' keys.
            $settlements->map(fn (SpendSettlement $t) => [
                'from' => 'u'.$t->from_user_id, 'to' => 'u'.$t->to_user_id, 'amount' => $t->amount,
            ])->all(),
            $split['coveredBy'],
        );

        $name = fn (string $key) => $names[$key] ?? '?';

        return [
            'balances' => collect($result['net'])
                ->map(fn (int $cents, string $key) => ['id' => $key, 'name' => $name($key), 'net' => $cents])
                ->values()->all(),
            'suggestions' => collect($result['suggestions'])->map(fn ($s) => [
                'from' => ['id' => $s['from'], 'name' => $name($s['from'])],
                'to' => ['id' => $s['to'], 'name' => $name($s['to'])],
                'amount' => $s['amount'],
                // The one-click "mark paid" only records user-to-user payments; guest settle-up is manual.
                'canSettle' => str_starts_with($s['from'], 'u') && str_starts_with($s['to'], 'u'),
            ])->values()->all(),
        ];
    }

    /**
     * A spend's split override for the math, or null when it splits among everyone (the common case).
     * Rows with a null amount mean "weighted split among just these participants"; rows with an amount
     * mean an exact split. Relies on the caller eager-loading `shares` to avoid an N+1.
     *
     * @return array<string,int|null>|null
     */
    private static function sharesOf(Spend $spend): ?array
    {
        if ($spend->shares->isEmpty()) {
            return null;
        }

        return $spend->shares->mapWithKeys(fn (SpendShare $sh) => [$sh->participant_key => $sh->amount])->all();
    }
}
