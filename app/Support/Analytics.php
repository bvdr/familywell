<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Server-side Umami events, for things that happen without a page view the browser tracker could see
 * (sign-up redirects, Stripe webhooks, assistant tool calls over MCP).
 *
 * Everything is gated on enabled() (UMAMI_WEBSITE_ID set), exactly like the <script> tag in app.blade.php:
 * a self-hosted install never reports anywhere. The person is identified ONLY by users.analytics_id (a
 * random UUID); event data is small and never personal (no names, emails or content).
 */
class Analytics
{
    // Umami silently drops requests without a User-Agent (it filters bots on it).
    private const USER_AGENT = 'FamilyWell/1.0 (+https://familywell.co)';

    public static function enabled(): bool
    {
        return filled(config('services.umami.website_id'));
    }

    /**
     * Queue an event for $user, sent AFTER the response so it never slows the request down.
     * app()->terminating() instead of a queued job: production runs no queue worker (only the scheduler),
     * so a queued job would never be sent. Under Octane the callback lives on the per-request sandbox app.
     *
     * @param  array<string, string|int>  $data
     * @param  string  $path  the "page" Umami files the event under, e.g. '/register', '/mcp'
     */
    public static function track(?User $user, string $event, array $data = [], string $path = '/'): void
    {
        if (! self::enabled() || ! $user?->analytics_id) {
            return;
        }

        $id = $user->analytics_id;
        // The app's terminating callbacks aren't cleared after they run, so where one app instance serves
        // several requests (the test suite) a later terminate() would replay this; send it only once.
        $sent = false;
        app()->terminating(function () use (&$sent, $id, $event, $data, $path) {
            if (! $sent) {
                $sent = true;
                self::send($id, $event, $data, $path);
            }
        });
    }

    /**
     * POST one event to Umami's collect API. Never throws: analytics must not break what it measures, so
     * a slow or broken Umami costs at most the 2s timeout, after the response has gone out.
     *
     * @param  array<string, string|int>  $data
     */
    public static function send(string $analyticsId, string $event, array $data = [], string $path = '/'): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            Http::timeout(2)->withUserAgent(self::USER_AGENT)->post(rtrim((string) config('services.umami.host'), '/').'/api/send', [
                'type' => 'event',
                'payload' => [
                    'website' => config('services.umami.website_id'),
                    // The app's own host (familywell.co in production), the same one the script's data-domains allows.
                    'hostname' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'familywell.co',
                    'url' => $path,
                    'name' => $event,
                    'data' => (object) $data,
                    // Umami >= 2.13 takes this as the distinct id, the same one umami.identify() sets in the
                    // browser, so server events land on the same person as their page views.
                    'id' => $analyticsId,
                ],
            ]);
        } catch (\Throwable) {
            // Swallowed on purpose (see above). Not even report(): an Umami outage would flood the logs.
        }
    }
}
