<?php

namespace App\Support\Dashboard;

use Closure;
use Illuminate\Support\Facades\Log;

// Sibling to DashboardItems, but for *dated* leaf items (a task or a shopping item due on a day),
// not whole entities. Modules register a provider that returns their open, dated, viewable items;
// the dashboard buckets them into Today / This Week. Keyed by module id so re-registration under
// Octane is idempotent.
class DashboardAgenda
{
    /** @var array<string, Closure> */
    private static array $providers = [];

    public static function register(string $moduleId, Closure $provider): void
    {
        self::$providers[$moduleId] = $provider;
    }

    /**
     * Every provider's items, flattened. A failing provider is logged and skipped so one module
     * can't take down the dashboard.
     *
     * @return array<int, array{title: string, subtitle: string, href: string, icon: string, dueDate: string}>
     */
    public static function collect(): array
    {
        $items = [];
        foreach (self::$providers as $moduleId => $provider) {
            try {
                foreach ($provider() as $item) {
                    $items[] = $item;
                }
            } catch (\Throwable $e) {
                Log::warning("dashboard-agenda provider [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }

        return $items;
    }
}
