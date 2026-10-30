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

    /** How many days Home's strip shows after Tomorrow. */
    public const STRIP_DAYS = 3;

    /**
     * The last day providers return items for: Today, Tomorrow, then the next 3 days (Home's strip). It used to be
     * "the end of this week", which left a one-day strip on Fridays and none at the weekend (2026-10-30).
     * On the family's clock (FamilyClock), like Today.
     */
    public static function until(): string
    {
        return FamilyClock::date()->addDays(1 + self::STRIP_DAYS)->toDateString();
    }

    /**
     * The strip's days (the day after tomorrow onwards), from the same clock that sorts Today / Tomorrow / later,
     * so a strip day and the items under it always agree (a phone's own date can differ from the server's).
     *
     * @return list<string>
     */
    public static function stripDays(): array
    {
        $today = FamilyClock::date();

        return array_map(fn ($n) => $today->copy()->addDays(2 + $n)->toDateString(), range(0, self::STRIP_DAYS - 1));
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
