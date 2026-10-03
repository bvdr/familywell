<?php

namespace App\Support\Calendar;

use Closure;
use Illuminate\Support\Facades\Log;

// Dated items from every module for a date range, for the calendar (page, MCP, ICS excluded). Same shape as
// DashboardAgenda: modules register a provider keyed by module id (idempotent under Octane); a failing provider
// is logged and skipped so one module can't take the calendar down; $only limits to the modules an MCP token
// may read. A registry instead of a Hooks filter for exactly those two reasons.
// Each provider: fn (string $from, string $to, int $userId): list<item>; it must self-gate on its module.
class CalendarSources
{
    /** @var array<string, Closure> */
    private static array $providers = [];

    public static function register(string $moduleId, Closure $provider): void
    {
        self::$providers[$moduleId] = $provider;
    }

    /** @return list<string> module ids that registered a provider */
    public static function modules(): array
    {
        return array_keys(self::$providers);
    }

    public static function collect(string $from, string $to, int $userId, ?array $only = null): array
    {
        $items = [];
        foreach (self::$providers as $moduleId => $provider) {
            if ($only !== null && ! in_array($moduleId, $only, true)) {
                continue;
            }
            try {
                foreach ($provider($from, $to, $userId) as $item) {
                    $items[] = ['source' => $moduleId] + $item;
                }
            } catch (\Throwable $e) {
                Log::warning("calendar source [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }
        usort($items, fn ($a, $b) => [$a['start'], $a['startTime'] ?? '', $a['title']] <=> [$b['start'], $b['startTime'] ?? '', $b['title']]);

        return $items;
    }

    /** The unified item shape; providers call this so every source stays in step. */
    public static function item(
        string $kind, int $id, string $title, string $start, ?string $end = null, string $href = '', string $icon = 'calendar',
        ?string $color = null, array $people = [], bool $done = false, ?string $startTime = null, ?string $endTime = null, bool $editable = false,
    ): array {
        return [
            'kind' => $kind, 'id' => $id, 'title' => $title, 'start' => $start, 'end' => $end ?? $start,
            'startTime' => $startTime, 'endTime' => $endTime, 'href' => $href, 'icon' => $icon, 'color' => $color,
            'people' => $people, 'done' => $done, 'editable' => $editable,
        ];
    }
}
