<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Hands a signed-in session to another device: the onboarding's "Now take it to your phone" QR code and its
 * "email me a sign-in link". The link signs whoever opens it in as this person, so it is treated like a password:
 *  - 40 random characters, kept only as a SHA-256 in the cache (a leaked cache table can't be replayed),
 *  - single use (Cache::pull), and
 *  - gone after TTL_MINUTES, short enough for "scan it now", long enough to find the phone.
 * Creating one needs the person signed in; using one is throttled per IP on its route.
 */
final class Handoff
{
    public const TTL_MINUTES = 10;

    public static function create(User $user): string
    {
        $token = Str::random(40);
        Cache::put(self::key($token), $user->id, now()->addMinutes(self::TTL_MINUTES));

        return route('handoff', $token);
    }

    /** The user the token signs in, consuming it. Null when unknown, used or expired. */
    public static function consume(string $token): ?User
    {
        $id = Cache::pull(self::key($token));

        return $id ? User::find($id) : null;
    }

    private static function key(string $token): string
    {
        return 'handoff:'.hash('sha256', $token);
    }
}
