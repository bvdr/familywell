<?php

namespace Modules\ExpenseImport\Statement;

use Illuminate\Support\Str;

// Statement table -> transactions. Known banks are recognised by their header row; anything else is read
// with a column mapping the family made once (ImportMapping). A transaction is:
//   ['date' => 'Y-m-d', 'amount' => int minor units, NEGATIVE = money out, 'currency' => ?string,
//    'merchant' => string, 'reference' => ?string, 'kind' => expense|income|refund|transfer]
//
// The Wise and Revolut layouts are their documented CSV exports. The BT and ING (Romania) layouts follow
// their Internet-banking CSV exports as commonly shared; if a bank changes its columns, the review screen
// falls back to the one-time mapping, so nothing breaks, it just asks once.
class Formats
{
    /** Known formats: label + the header names (normalised, see Values::header) that must all be present. */
    private const KNOWN = [
        'wise' => ['label' => 'Wise', 'requires' => ['date', 'amount', 'currency', 'description'], 'any' => ['transferwise id', 'id']],
        'revolut' => ['label' => 'Revolut', 'requires' => ['type', 'started date', 'description', 'amount', 'currency', 'state']],
        'bt' => ['label' => 'BT', 'requires' => ['data tranzactie', 'descriere', 'debit', 'credit']],
        'ing' => ['label' => 'ING', 'requires' => ['data', 'detalii tranzactie'], 'any' => ['debit', 'credit']],
    ];

    // Own-account moves, currency exchanges and cash withdrawals: real money movements, but not household
    // expenses, so they come in unticked as "transfer" (still tickable on review).
    private const TRANSFER = '/\b(converted|moved|exchange|schimb valutar|transfer intre conturi|conturi proprii|top.?up|alimentare|retragere numerar|cash withdrawal|atm|sent money to|transfer home.?bank|transfer to|to pocket|savings|economii)\b/i';

    private const REFUND = '/\b(refund|retur|rambursare|anulare|reversal|chargeback)\b/i';

    /**
     * Find the header row and say which known format it is (or null = unknown, map it by hand).
     *
     * @param  list<list<string>>  $table
     * @return array{format: ?string, label: ?string, header: int, signature: string}|null null = no header row at all
     */
    public static function detect(array $table): ?array
    {
        $fallback = null;
        foreach (array_slice($table, 0, 30, true) as $i => $row) {
            $headers = array_map(Values::header(...), $row);
            foreach (self::KNOWN as $key => $f) {
                $hasAll = array_diff($f['requires'], $headers) === [];
                $hasAny = ! isset($f['any']) || array_intersect($f['any'], $headers) !== [];
                if ($hasAll && $hasAny) {
                    return ['format' => $key, 'label' => $f['label'], 'header' => $i, 'signature' => self::signature($row)];
                }
            }
            // An unknown bank's header: the first row with 3+ cells where at most one looks like a number.
            $filled = array_filter($row, fn ($c) => $c !== '');
            $numeric = array_filter($filled, fn ($c) => preg_match('/^[-+(]?[\d.,\s]+\)?$/', $c));
            if ($fallback === null && count($filled) >= 3 && count($numeric) <= 1) {
                $fallback = ['format' => null, 'label' => null, 'header' => $i, 'signature' => self::signature($row)];
            }
        }

        return $fallback;
    }

    /** @param  list<string>  $headerRow */
    public static function signature(array $headerRow): string
    {
        return sha1(implode('|', array_map(Values::header(...), $headerRow)));
    }

    /**
     * @param  list<list<string>>  $table
     * @return list<array<string, mixed>>
     */
    public static function read(string $format, array $table, int $header): array
    {
        $cols = array_flip(array_map(Values::header(...), $table[$header]));
        $cell = fn (array $row, string ...$names) => collect($names)->map(fn ($n) => isset($cols[$n]) ? trim($row[$cols[$n]] ?? '') : '')->first(fn ($v) => $v !== '') ?? '';
        $rows = array_slice($table, $header + 1);
        $currency = self::preambleCurrency(array_slice($table, 0, $header));

        $out = [];
        foreach ($rows as $row) {
            $tx = match ($format) {
                'wise' => self::wise($row, $cell),
                'revolut' => self::revolut($row, $cell),
                'bt' => self::debitCredit($row, $cell, 'data tranzactie', 'descriere', 'referinta tranzactiei', $currency),
                'ing' => self::debitCredit($row, $cell, 'data', 'detalii tranzactie', null, $currency),
                default => null,
            };
            if ($tx === null && $format === 'ing' && $out !== [] && $cell($row, 'data') === '') {
                // ING puts a transaction's details on the lines below it: fold them into the description.
                $more = trim(implode(' ', array_filter($row)));
                $last = array_key_last($out);
                $out[$last]['details'] = trim(($out[$last]['details'] ?? '').' '.$more);

                continue;
            }
            if ($tx !== null) {
                $out[] = $tx;
            }
        }

        // ING: the shop name is usually in the folded detail lines ("Terminal: MEGA IMAGE ..."), not line one.
        foreach ($out as &$tx) {
            if (! empty($tx['details'])) {
                if (preg_match('/(?:terminal|beneficiar|ordonator)\s*:\s*([^:]+?)(?=\s+\w+\s*:|$)/i', $tx['details'], $m)) {
                    $tx['merchant'] = Values::merchant($m[1]);
                }
                $tx['kind'] = self::kind($tx['amount'], $tx['merchant'].' '.$tx['details']);
            }
            unset($tx['details']);
        }

        return $out;
    }

    /**
     * Read with a hand-made mapping (column indexes). `expenses_positive`: the bank writes money out as a
     * positive number in its single amount column.
     *
     * @param  list<list<string>>  $table
     * @param  array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    public static function readMapped(array $map, array $table, int $header): array
    {
        $get = fn (array $row, ?string $key) => isset($map[$key]) && $map[$key] !== null && $map[$key] !== '' ? trim($row[(int) $map[$key]] ?? '') : '';
        $out = [];
        foreach (array_slice($table, $header + 1) as $row) {
            $date = Values::date($get($row, 'date'));
            if (! $date) {
                continue;
            }
            if (($map['amount'] ?? null) !== null && $map['amount'] !== '') {
                [$value, $ccy] = Values::money($get($row, 'amount'));
                if ($value !== null && ! empty($map['expenses_positive'])) {
                    $value = -$value;
                }
            } else {
                [$debit, $ccy] = Values::money($get($row, 'debit'));
                [$credit] = Values::money($get($row, 'credit'));
                $value = abs((float) $credit) - abs((float) $debit);
            }
            if (! $value) {
                continue;
            }
            $text = $get($row, 'merchant');
            $currency = strtoupper($get($row, 'currency')) ?: ($ccy ?? ($map['fixed_currency'] ?? null));
            $out[] = self::tx($date, $value, $currency, $text, $get($row, 'reference') ?: null);
        }

        return $out;
    }

    public static function label(string $format): string
    {
        return self::KNOWN[$format]['label'] ?? Str::headline($format);
    }

    private static function wise(array $row, callable $cell): ?array
    {
        $date = Values::date($cell($row, 'date'));
        [$value] = Values::money($cell($row, 'amount'));
        if (! $date || ! $value) {
            return null;
        }
        $description = $cell($row, 'description');
        $merchant = $cell($row, 'merchant') ?: $description;

        return self::tx($date, $value, strtoupper($cell($row, 'currency')) ?: null, $merchant, $cell($row, 'transferwise id', 'id') ?: null, $description);
    }

    private static function revolut(array $row, callable $cell): ?array
    {
        $state = strtoupper($cell($row, 'state'));
        if (! in_array($state, ['COMPLETED', 'PENDING'], true)) {
            return null; // reverted / declined / failed: no money moved
        }
        $date = Values::date($cell($row, 'completed date', 'started date'));
        [$value] = Values::money($cell($row, 'amount'));
        [$fee] = Values::money($cell($row, 'fee'));
        if (! $date || ! $value) {
            return null;
        }
        $value -= abs((float) $fee); // the fee is money out on top of the amount
        $type = strtoupper($cell($row, 'type'));
        $tx = self::tx($date, $value, strtoupper($cell($row, 'currency')) ?: null, $cell($row, 'description'), null);
        $tx['kind'] = match (true) {
            in_array($type, ['REFUND', 'CARD_REFUND'], true) => 'refund',
            in_array($type, ['TOPUP', 'EXCHANGE', 'TRANSFER', 'ATM'], true) => 'transfer',
            $value > 0 => 'income',
            default => 'expense',
        };

        return $tx;
    }

    private static function debitCredit(array $row, callable $cell, string $dateCol, string $textCol, ?string $refCol, ?string $currency): ?array
    {
        $date = Values::date($cell($row, $dateCol));
        [$debit, $ccyD] = Values::money($cell($row, 'debit'));
        [$credit, $ccyC] = Values::money($cell($row, 'credit'));
        $value = abs((float) $credit) - abs((float) $debit);
        if (! $date || ! $value) {
            return null;
        }
        $text = $cell($row, $textCol);

        return self::tx($date, $value, $ccyD ?? $ccyC ?? $currency, $text, $refCol ? ($cell($row, $refCol) ?: null) : null);
    }

    private static function tx(string $date, float $value, ?string $currency, string $text, ?string $reference, ?string $description = null): array
    {
        $cents = (int) round($value * 100);

        return [
            'date' => $date,
            'amount' => $cents,
            'currency' => $currency && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
            'merchant' => Values::merchant($text),
            'reference' => $reference ? Str::limit($reference, 120, '') : null,
            'kind' => self::kind($cents, ($description ?? '').' '.$text),
        ];
    }

    private static function kind(int $cents, string $text): string
    {
        return match (true) {
            preg_match(self::REFUND, $text) === 1 && $cents > 0 => 'refund',
            preg_match(self::TRANSFER, $text) === 1 => 'transfer',
            $cents > 0 => 'income',
            default => 'expense',
        };
    }

    /** BT/ING put the account currency in the lines above the table ("Valuta: RON", "Cont RO.. EUR"). */
    private static function preambleCurrency(array $rows): ?string
    {
        foreach ($rows as $row) {
            if (preg_match('/\b(RON|EUR|USD|GBP)\b/', strtoupper(implode(' ', $row)), $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
