<?php

namespace Modules\Spending;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Currency conversion via ECB reference rates (frankfurter.dev, free, no key), with a fallback.
// All supported currencies have 2 minor digits, so converting minor units is a straight multiply.
class Fx
{
    /**
     * Convert an integer minor-unit amount from one currency to another.
     * $date freezes the rate to a historical day (used when a spend is captured); null = latest.
     */
    public static function convert(int $amountMinor, string $from, string $to, ?string $date = null): int
    {
        if ($from === $to) {
            return $amountMinor;
        }

        return (int) round($amountMinor * self::rate($from, $to, $date));
    }

    public static function rate(string $from, string $to, ?string $date = null): float
    {
        if ($from === $to) {
            return 1.0;
        }

        // Historical rates never change, so cache them forever; "latest" for 12h.
        $key = "fx:{$from}:{$to}:".($date ?? 'latest');
        $ttl = $date ? null : now()->addHours(12);

        return Cache::remember($key, $ttl, fn () => self::fetch($from, $to, $date));
    }

    private static function fetch(string $from, string $to, ?string $date): float
    {
        // Primary: ECB via frankfurter (supports historical dates).
        try {
            $path = $date ?: 'latest';
            $r = Http::timeout(8)->get("https://api.frankfurter.dev/v1/{$path}", ['base' => $from, 'symbols' => $to]);
            $rate = $r->json("rates.{$to}");
            if ($rate) {
                return (float) $rate;
            }
        } catch (\Throwable $e) {
            Log::warning('fx frankfurter failed', ['e' => $e->getMessage()]);
        }

        // Fallback: latest only (no historical). Approximates a historical rate with the latest one.
        try {
            $r = Http::timeout(8)->get("https://open.er-api.com/v6/latest/{$from}");
            $rate = $r->json("rates.{$to}");
            if ($rate) {
                return (float) $rate;
            }
        } catch (\Throwable $e) {
            Log::warning('fx fallback failed', ['e' => $e->getMessage()]);
        }

        // Last resort: 1:1 so a spend still records rather than erroring. Logged so it is visible.
        Log::error('fx unavailable, using 1:1', compact('from', 'to', 'date'));

        return 1.0;
    }

    /** Latest rates from a base to each supported currency, for the display-currency selector. */
    public static function ratesFrom(string $base, array $currencies): array
    {
        $out = [];
        foreach ($currencies as $c) {
            $out[$c] = self::rate($base, $c);
        }

        return $out;
    }
}
