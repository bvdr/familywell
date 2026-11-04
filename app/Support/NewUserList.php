<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Every new account goes into a Resend segment ("new-users"); a Resend Automation on that segment sends
 * the 3-email welcome sequence. Added once the person has a name (Google/Apple: at sign-up; email code: at
 * "About you"), so the first email can greet them by first name. Off unless RESEND_KEY and
 * RESEND_NEW_USERS_SEGMENT are set (self-hosters, local, tests). After the response, never throws: a Resend
 * hiccup must not break sign-up. ponytail: no retry; a missed contact can be added by hand in Resend.
 */
class NewUserList
{
    public static function add(User $user): void
    {
        $key = config('services.resend.key');
        $segment = config('services.resend.new_users_segment');
        if (! $key || ! $segment || blank($user->name)) {
            return;
        }

        [$first, $last] = explode(' ', trim($user->name), 2) + [1 => null];

        defer(function () use ($user, $key, $segment, $first, $last) {
            try {
                Http::withToken($key)->post('https://api.resend.com/contacts', [
                    'email' => $user->email,
                    'first_name' => $first,
                    'last_name' => $last,
                    'segments' => [['id' => $segment]],
                ])->throw();
            } catch (Throwable $e) {
                report($e);
            }
        })->always();
    }
}
