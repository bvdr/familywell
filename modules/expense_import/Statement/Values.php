<?php

namespace Modules\ExpenseImport\Statement;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

// Reading the loose values banks and Apple Shortcuts send: "1.234,56", "-50.00", "50,00 RON", "€12.50",
// "05.01.2024", "05 ianuarie 2024", an Excel date serial... Pure functions, unit-tested on their own.
class Values
{
    private const SYMBOLS = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP', 'lei' => 'RON', 'ron' => 'RON'];

    // Romanian + English month names/abbreviations -> month number (ING RO writes "05 ianuarie 2024").
    private const MONTHS = [
        'ianuarie' => 1, 'ian' => 1, 'january' => 1, 'jan' => 1,
        'februarie' => 2, 'feb' => 2, 'february' => 2,
        'martie' => 3, 'mar' => 3, 'march' => 3,
        'aprilie' => 4, 'apr' => 4, 'april' => 4,
        'mai' => 5, 'may' => 5,
        'iunie' => 6, 'iun' => 6, 'june' => 6, 'jun' => 6,
        'iulie' => 7, 'iul' => 7, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'septembrie' => 9, 'sep' => 9, 'sept' => 9, 'september' => 9,
        'octombrie' => 10, 'oct' => 10, 'october' => 10,
        'noiembrie' => 11, 'noi' => 11, 'nov' => 11, 'november' => 11,
        'decembrie' => 12, 'dec' => 12, 'december' => 12,
    ];

    /**
     * A money string -> [amount as float (sign kept), currency code or null]. Handles both decimal styles:
     * the LAST separator followed by 1-2 digits is the decimal point ("1.234,56" and "1,234.56" both 1234.56).
     *
     * @return array{0: float|null, 1: string|null}
     */
    public static function money(string|int|float|null $raw): array
    {
        if (is_int($raw) || is_float($raw)) {
            return [(float) $raw, null];
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return [null, null];
        }

        $currency = null;
        if (preg_match('/\b([A-Z]{3})\b/', strtoupper($s), $m) && in_array($m[1], ['EUR', 'RON', 'USD', 'GBP', 'HUF', 'CHF', 'PLN', 'BGN', 'CZK', 'SEK', 'NOK', 'DKK'], true)) {
            $currency = $m[1];
        }
        foreach (self::SYMBOLS as $symbol => $code) {
            if ($currency === null && str_contains(mb_strtolower($s), $symbol)) {
                $currency = $code;
            }
        }

        // Unicode minus and accounting parentheses "(12.50)" mean negative.
        $negative = str_contains($s, '-') || str_contains($s, '−') || preg_match('/^\(.*\)$/', $s);
        $digits = preg_replace('/[^0-9.,]/', '', $s) ?? '';
        if ($digits === '' || ! preg_match('/\d/', $digits)) {
            return [null, $currency];
        }
        if (preg_match('/[.,](\d{1,2})$/', $digits, $m)) {
            $whole = preg_replace('/[.,]/', '', substr($digits, 0, -strlen($m[0])));
            $value = (float) ($whole.'.'.$m[1]);
        } else {
            $value = (float) preg_replace('/[.,]/', '', $digits); // only thousand separators
        }

        return [$negative ? -$value : $value, $currency];
    }

    /** A date in any of the usual statement shapes -> 'Y-m-d', or null. Day-first when it's ambiguous (EU banks). */
    public static function date(string|int|float|null $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        // Excel serial day number (XLSX stores dates as numbers): 45292 = 2024-01-01.
        if (preg_match('/^\d{5}(\.\d+)?$/', $s) && (float) $s > 20000 && (float) $s < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $s))->toDateString();
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) { // 20240105
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $s, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
            // Day first, unless the first number can't be a month and the second can't be a day... the other
            // way round ("12/25/2024" is US-style).
            [$day, $month] = $b > 12 && $a <= 12 ? [$b, $a] : [$a, $b];

            return self::ymd($y, $month, $day);
        }
        if (preg_match('/^(\d{1,2})\s+([[:alpha:]\x{0100}-\x{017F}]+)\.?,?\s+(\d{4})/u', Str::ascii(mb_strtolower($s)), $m)) {
            $month = self::MONTHS[$m[2]] ?? null;

            return $month ? self::ymd((int) $m[3], $month, (int) $m[1]) : null;
        }
        try {
            return CarbonImmutable::parse($s)->toDateString(); // last resort: "Jan 5, 2024" and friends
        } catch (Throwable) {
            return null;
        }
    }

    /** Header cell -> comparable key: lowercase ASCII, single spaces ("Dată tranzacție" -> "data tranzactie"). */
    public static function header(string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower($raw))) ?? '');
    }

    /**
     * A bank's transaction text -> a readable merchant. Card lines carry noise around the shop name
     * ("Plata la POS non-BT cu card VISA 4567XXXX1234 MEGA IMAGE 0123 BUCURESTI RO TID:..."); strip what is
     * clearly not the shop. Heuristic on purpose: it only has to read well on the review screen.
     */
    public static function merchant(string $raw): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        $s = preg_replace('/^(card transaction of [\d.,]+ [A-Z]{3} issued by|card payment to|payment to|plata la pos( non-bt)?( cu card)?( (visa|mastercard|maestro))?|cumparare pos|tranzactie comerciant)\s*/i', '', $s) ?? $s;
        $s = preg_replace('/\b\d{4,6}[X*]{2,}\d{2,4}\b/i', '', $s) ?? $s; // masked card number
        $s = preg_replace('/\b(tid|rrn|ref|referinta|auth|nr\.? card|valoare tranzactie|data tranzactiei?)\s*[:=]?\s*\S+/i', '', $s) ?? $s;
        $s = preg_replace('/\s*;.*$/', '', $s) ?? $s; // BT: details after the first ';'
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '', " \t-,.");

        return Str::limit($s !== '' ? $s : trim($raw), 120, '');
    }

    /** Merchant -> matching key: lowercase ASCII letters/digits only ("Mega Image 0123" -> "mega image 0123"). */
    public static function key(string $merchant): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($merchant))) ?? '');
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
