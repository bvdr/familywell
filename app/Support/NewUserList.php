<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Every new account goes into a Resend segment ("new-users"), then a `user.signed_up` event starts the Resend
 * Automation that sends the 3-email welcome sequence (Automations trigger on events, not on segments). Added once the person has a name (Google/Apple: at sign-up; email code: at
 * "About you"), so the first email can greet them by first name. Off unless RESEND_KEY and
 * RESEND_NEW_USERS_SEGMENT are set (self-hosters, local, tests). After the response, never throws: a Resend
 * hiccup must not break sign-up. ponytail: no retry; a missed contact can be added by hand in Resend.
 */
class NewUserList
{
    /** The Resend Automation "FamilyWell welcome" triggers on this event name. */
    public const EVENT = 'user.signed_up';

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
                // Contact first: the emails greet by the contact's FIRST_NAME, which the event can't set.
                Http::withToken($key)->post('https://api.resend.com/contacts', [
                    'email' => $user->email,
                    'first_name' => $first,
                    'last_name' => $last,
                    'segments' => [['id' => $segment]],
                    // One Resend account holds Folio's contacts too, and segments are fixed lists (not filters), so
                    // contacts are tagged instead: the Automation only mails app=familywell + env=prod.
                    'properties' => ['app' => 'familywell', 'env' => app()->isProduction() ? 'prod' : 'dev'],
                ])->throw();
            } catch (Throwable $e) {
                report($e); // still start the sequence below (an existing contact is updated by the create, no error)
            }
            try {
                Http::withToken($key)->post('https://api.resend.com/events/send', ['event' => self::EVENT, 'email' => $user->email])->throw();
            } catch (Throwable $e) {
                report($e);
            }
        })->always();
    }
}
