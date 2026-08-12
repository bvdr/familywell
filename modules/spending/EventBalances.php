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
            // Settlements are stored on namespaced participant keys, so members and guests both settle.
            $settlements->map(fn (SpendSettlement $t) => [
                'from' => $t->from_key, 'to' => $t->to_key, 'amount' => $t->amount,
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

        return $spend->shares->mapWithKeys(function (SpendShare $sh) use ($spend) {
            // 'some' rows have a null amount (weighted split among the listed participants). 'exact' rows
            // store the amount in the SPEND's currency, so convert to base proportionally to the spend's
            // frozen conversion; the shares then reconcile to base_amount (Balances parks any cent remainder).
            $amount = $sh->amount === null || $spend->amount <= 0
                ? $sh->amount
                : (int) round($sh->amount * $spend->base_amount / $spend->amount);

            return [$sh->participant_key => $amount];
        })->all();
    }
}
