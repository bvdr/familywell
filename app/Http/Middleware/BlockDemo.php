<?php

namespace App\Http\Middleware;

use App\Support\Translations;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account- and family-level actions a demo visitor must not take. The demo family is public (/demo logs
 * anyone in as its owner, and app-directory reviewers know its password), so these would hand a stranger
 * the account or a megaphone:
 *  - email change -> "forgot password" to the new address = the account is theirs, and the hourly reset
 *    (DemoSeeder matches users by email) then leaves that hijacked user behind for good;
 *  - password change / account delete / unlinking sign-ins / disconnecting apps -> locks out the next
 *    visitor or the reviewers' MCP connection;
 *  - invites (emails to any address, with visitor-written family name), billing, public share/guest links.
 * Everything inside the modules (and every MCP tool) stays writable: that's what the demo is for, and the
 * hourly demo:reset puts it back.
 *
 * Usage: ->middleware('not-demo:email') — the param is the form field the message shows under (Inertia
 * forms render validation errors per field; the app has no global flash).
 */
class BlockDemo
{
    public function handle(Request $request, Closure $next, string $field = 'demo'): Response
    {
        if ($request->user()?->isDemo()) {
            throw ValidationException::withMessages([$field => Translations::get('family.demo_blocked')]);
        }

        return $next($request);
    }
}
