<?php

namespace App\Support\Dashboard;

use App\Support\Tenancy\FamilyClock;
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
     * The last day providers return items for: the end of this week, but never before tomorrow. Home shows
     * Today, Tomorrow and the rest of the week; on a Sunday "tomorrow" is already next week, and without the
     * floor the Tomorrow group would always be empty that day. The family's week (FamilyClock), like Today.
     */
    public static function until(): string
    {
        $today = FamilyClock::date();

        return max($today->copy()->endOfWeek()->toDateString(), $today->copy()->addDay()->toDateString());
    }

    /**
     * Every provider's items, flattened. A failing provider is logged and skipped so one module
     * can't take down the dashboard.
     *
     * @return array<int, array{title: string, subtitle: string, href: string, icon: string, dueDate: string}>
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
                Log::warning("dashboard-agenda provider [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }

        return $items;
    }
}
