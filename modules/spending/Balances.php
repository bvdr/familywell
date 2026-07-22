<?php

namespace Modules\Spending;

// Pure balance + settle-up math for one event. Everything is integer minor units (cents); the numbers
// must reconcile exactly, so no floats and remainders are distributed deterministically.
//
// Each spend is split among a set of participants. By default that's everyone, weighted by headcount;
// a spend can override this to a subset (still weighted) or to exact per-participant amounts. After all
// spends are tallied, a participant's owed share can be reassigned to whoever "covers" them.
class Balances
{
    /**
     * @param  array<string,int>  $participants  participant key => headcount weight (>=1)
     * @param  array<array{payer:string, amount:int, shares?:array<string,int|null>|null}>  $spends
     *                                                                                               shares: null            => split among all $participants, weighted;
     *                                                                                               [key => null]   => weighted split among just those participants;
     *                                                                                               [key => int]    => exact amounts (base cents) each listed participant owes.
     * @param  array<array{from:string, to:string, amount:int}>  $settlements
     * @param  array<string,string>  $coveredBy  participant key => the participant who covers their share
     * @return array{net: array<string,int>, suggestions: array<array{from:string, to:string, amount:int}>}
     */
    public static function for(array $participants, array $spends, array $settlements, array $coveredBy = []): array
    {
        $keys = array_keys($participants);
        sort($keys); // deterministic order for remainder distribution

        $paid = array_fill_keys($keys, 0);
        $owed = array_fill_keys($keys, 0);
        // A payer or share key not in the participant set still needs a slot (defensive; shouldn't happen).
        $ensure = function (string $k) use (&$paid, &$owed) {
            if (! array_key_exists($k, $paid)) {
                $paid[$k] = 0;
                $owed[$k] = 0;
            }
        };

        foreach ($spends as $s) {
            $ensure($s['payer']);
            $paid[$s['payer']] += $s['amount']; // the payer fronted the whole amount

            foreach (self::owingFor($s['amount'], $s['shares'] ?? null, $participants, $keys) as $k => $cents) {
                $ensure($k);
                $owed[$k] += $cents;
            }
        }

        // Covered participants: their consumption is billed to whoever covers them (follow the chain to a
        // root, guarding against cycles). They still keep credit for anything they personally paid.
        foreach (array_keys($owed) as $k) {
            if ($owed[$k] === 0) {
                continue;
            }
            $root = self::coverRoot($k, $coveredBy);
            if ($root !== $k) {
                $ensure($root);
                $owed[$root] += $owed[$k];
                $owed[$k] = 0;
            }
        }

        $net = [];
        foreach (array_keys($paid) as $k) {
            $net[$k] = $paid[$k] - $owed[$k];
        }

        // A settlement is one participant paying another: the payer's debt shrinks, the receiver's credit shrinks.
        foreach ($settlements as $t) {
            $net[$t['from']] = ($net[$t['from']] ?? 0) + $t['amount'];
            $net[$t['to']] = ($net[$t['to']] ?? 0) - $t['amount'];
        }

        return ['net' => $net, 'suggestions' => self::suggest($net)];
    }

    /**
     * How much each participant owes for one spend.
     *
     * @param  array<string,int|null>|null  $shares
     * @param  array<string,int>  $participants
     * @param  array<int,string>  $sortedKeys
     * @return array<string,int>
     */
    private static function owingFor(int $amount, ?array $shares, array $participants, array $sortedKeys): array
    {
        // Exact amounts given: trust them, but if they don't sum to the spend (rounding), park the
        // difference on the first listed participant so the split still reconciles to the exact total.
        if (is_array($shares) && self::hasExactAmount($shares)) {
            $out = [];
            $sum = 0;
            foreach ($shares as $k => $v) {
                $out[$k] = (int) $v;
                $sum += $out[$k];
            }
            if ($out !== [] && $sum !== $amount) {
                $out[array_key_first($out)] += $amount - $sum;
            }

            return $out;
        }

        // Weighted split: over the listed subset if given, else over everyone.
        if (is_array($shares)) {
            $weights = [];
            foreach (array_keys($shares) as $k) {
                $weights[$k] = $participants[$k] ?? 1;
            }
        } else {
            $weights = $participants;
        }

        return self::splitWeighted($amount, $weights, $sortedKeys);
    }

    /**
     * Split an integer amount across weighted keys, distributing leftover cents deterministically to the
     * first keys (in sorted order) so the shares sum back to the exact amount.
     *
     * @param  array<string,int>  $weights
     * @param  array<int,string>  $sortedKeys
     * @return array<string,int>
     */
    private static function splitWeighted(int $amount, array $weights, array $sortedKeys): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return [];
        }

        // Order = the caller's sorted keys filtered to this weight set, then any extras (defensive).
        $order = array_values(array_filter($sortedKeys, fn ($k) => isset($weights[$k])));
        foreach (array_keys($weights) as $k) {
            if (! in_array($k, $order, true)) {
                $order[] = $k;
            }
        }

        $out = [];
        $assigned = 0;
        foreach ($order as $k) {
            $out[$k] = intdiv($amount * $weights[$k], $total);
            $assigned += $out[$k];
        }
        $remainder = $amount - $assigned;
        foreach ($order as $k) {
            if ($remainder <= 0) {
                break;
            }
            $out[$k]++;
            $remainder--;
        }

        return $out;
    }

    /** @param  array<string,int|null>  $shares */
    private static function hasExactAmount(array $shares): bool
    {
        foreach ($shares as $v) {
            if ($v !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Follow covered_by to the paying root, guarding against cycles.
     *
     * @param  array<string,string>  $coveredBy
     */
    private static function coverRoot(string $key, array $coveredBy): string
    {
        $seen = [];
        while (isset($coveredBy[$key]) && ! isset($seen[$key])) {
            $seen[$key] = true;
            $key = $coveredBy[$key];
        }

        return $key;
    }

    /**
     * Greedy min-cash-flow: repeatedly settle the biggest debtor against the biggest creditor.
     *
     * @param  array<string,int>  $net
     * @return array<array{from:string, to:string, amount:int}>
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
