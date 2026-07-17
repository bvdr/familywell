<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

// Live-sync broadcasts are best-effort: a Reverb outage must never fail the write that triggered
// them. The module events are ShouldBroadcastNow (sent synchronously during the request), so a
// broadcast failure would otherwise bubble up as a 500. This swallows and logs that failure so the
// mutation still succeeds; clients reconcile on their next load or reconnect.
class Realtime
{
    public static function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('realtime broadcast failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }
}
