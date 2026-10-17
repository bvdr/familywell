<?php

namespace App\Support\Auth;

use App\Notifications\LoginCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// The 6-digit code that signs people in (and up) by email. Kept in the cache, not a table: it lives 10
// minutes and only the latest one per address counts. Stored as an HMAC, never the digits, and dropped
// after 5 wrong guesses, so the 1-in-a-million code can't be brute-forced inside its window.
class EmailCode
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public static function send(string $email): void
    {
        $email = self::normalize($email);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(self::TTL_MINUTES);
        Cache::put(self::key($email), ['hash' => self::hash($email, $code), 'attempts' => 0, 'expires' => $expires->getTimestamp()], $expires);

        Notification::route('mail', $email)->notify((new LoginCode($code))->locale(app()->getLocale()));
    }

    /** True once for the right code; the code is used up either way after a match or too many misses. */
    public static function check(string $email, string $code): bool
    {
        $email = self::normalize($email);
        $entry = Cache::get(self::key($email));
        if (! is_array($entry)) {
            return false;
        }
        if (hash_equals($entry['hash'], self::hash($email, $code))) {
            Cache::forget(self::key($email));

            return true;
        }
        if (++$entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget(self::key($email));
        } else {
            // Keep the original expiry: a wrong guess must not extend the code's life.
            Cache::put(self::key($email), $entry, Carbon::createFromTimestamp($entry['expires']));
        }

        return false;
    }

    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    private static function key(string $email): string
    {
        return 'login-code:'.hash('sha256', $email);
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
