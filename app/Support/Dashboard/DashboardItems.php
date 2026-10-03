<?php

namespace App\Support\Dashboard;

use Closure;
use Illuminate\Support\Facades\Log;

// A place for modules to surface their own entities (not just a link to the module) on the dashboard,
// e.g. the "Greece 2026" spend event rather than "Spending". A module registers a provider from its
// service provider boot(); the dashboard collects them. Keyed by module id so registration is
// idempotent under Octane (a provider re-registered each request just overwrites its slot).
class DashboardItems
{
    /** @var array<string, Closure> */
    private static array $providers = [];

    public static function register(string $moduleId, Closure $provider): void
    {
        self::$providers[$moduleId] = $provider;
    }

    /**
     * Run every provider and flatten their items. A failing provider is logged and skipped so one
     * module can't take down the dashboard.
     *
     * @return array<int, array{title: string, subtitle: string, href: string, category: string, icon: string, color?: ?string, visitedAt: string}> `category` is the module id (groups the dashboard cards); `icon`/`color` are the ENTITY's own (a spend event's lucide key, a list's icon slug + swatch), so a card shows what the user picked, not a generic module glyph.
     */
    public static function collect(?array $only = null): array
    {
        $items = [];
        foreach (self::$providers as $moduleId => $provider) {
            // $only: restrict to these module ids (the MCP get_today tool passes the modules its token may read).
            if ($only !== null && ! in_array($moduleId, $only, true)) {
                continue;
            }
            try {
                foreach ($provider() as $item) {
                    $items[] = $item;
                }
            } catch (\Throwable $e) {
                Log::warning("dashboard-items provider [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }

        return $items;
    }
}
