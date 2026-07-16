<?php

namespace Modules\Spending;

// Pure balance + settle-up math for one event. Everything is integer minor units (cents); the
// numbers must reconcile exactly, so no floats and remainders are distributed deterministically.
class Balances
{
    /**
     * @param  int[]  $memberIds
     * @param  array<array{payer_id:int, amount:int}>  $spends
     * @param  array<array{from_user_id:int, to_user_id:int, amount:int}>  $settlements
     * @return array{net: array<int,int>, suggestions: array<array{from:int, to:int, amount:int}>}
     */
    public static function for(array $memberIds, array $spends, array $settlements): array
    {
        sort($memberIds);
        $n = count($memberIds);
        $net = array_fill_keys($memberIds, 0);

        foreach ($spends as $s) {
            // The payer fronted the whole amount...
            $net[$s['payer_id']] += $s['amount'];

            // ...and every member owes an equal share. Distribute the remainder cent-by-cent to the
            // first members so the shares sum back to the exact amount.
            if ($n > 0) {
                $base = intdiv($s['amount'], $n);
                $rem = $s['amount'] % $n;
                foreach ($memberIds as $i => $m) {
                    $net[$m] -= $base + ($i < $rem ? 1 : 0);
                }
            }
        }

        // A settlement is one member paying another: the payer's debt shrinks, the receiver's credit shrinks.
        foreach ($settlements as $t) {
            $net[$t['from_user_id']] += $t['amount'];
            $net[$t['to_user_id']] -= $t['amount'];
        }

        return ['net' => $net, 'suggestions' => self::suggest($net)];
    }

    /**
     * Greedy min-cash-flow: repeatedly settle the biggest debtor against the biggest creditor.
     *
     * @param  array<int,int>  $net
     * @return array<array{from:int, to:int, amount:int}>
     */
    private static function suggest(array $net): array
    {
        $creditors = [];
        $debtors = [];
        foreach ($net as $id => $bal) {
            if ($bal > 0) {
                $creditors[$id] = $bal;
            } elseif ($bal < 0) {
                $debtors[$id] = -$bal;
            }
        }

        $out = [];
        while ($creditors && $debtors) {
            arsort($creditors);
            arsort($debtors);
            $cId = array_key_first($creditors);
            $dId = array_key_first($debtors);
            $amount = min($creditors[$cId], $debtors[$dId]);

            $out[] = ['from' => $dId, 'to' => $cId, 'amount' => $amount];

            $creditors[$cId] -= $amount;
            $debtors[$dId] -= $amount;
            if ($creditors[$cId] === 0) {
                unset($creditors[$cId]);
            }
            if ($debtors[$dId] === 0) {
                unset($debtors[$dId]);
            }
        }

        return $out;
    }
}
