<?php

namespace App\Support\Dashboard;

use Closure;
use Illuminate\Support\Facades\Log;

// Keyed data for the dashboard's headline widgets (e.g. Spending's "this month" card), as opposed to
// DashboardItems (entity cards) and DashboardAgenda (dated rows). A module registers a provider from its
// service provider boot() and the dashboard page reads `widgets.<moduleId>`; a provider returns null to
// stay off the page (module disabled, nothing to show). Keyed by module id so re-registration under
// Octane is idempotent. The card itself is rendered by dashboard.tsx (the widget sits above Today, which
// the runtime `dashboard.widgets` Slot — rendered further down — cannot do).
class DashboardWidgets
{
    /** @var array<string, Closure> */
    private static array $providers = [];

    public static function register(string $moduleId, Closure $provider): void
    {
        self::$providers[$moduleId] = $provider;
    }

    /**
     * Run every provider; a failing one is logged and skipped so it can't take down the dashboard.
     *
     * @return array<string, array<string, mixed>> moduleId => payload (null payloads are dropped)
     */
    public static function collect(): array
    {
        $widgets = [];
        foreach (self::$providers as $moduleId => $provider) {
            try {
                if (($payload = $provider()) !== null) {
                    $widgets[$moduleId] = $payload;
                }
            } catch (\Throwable $e) {
                Log::warning("dashboard-widgets provider [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }

        return $widgets;
    }
}
