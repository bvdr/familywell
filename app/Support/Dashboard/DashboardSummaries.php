<?php

namespace App\Support\Dashboard;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Support\Facades\Log;

// One live line per module for the dashboard's "Your modules" grid ("6 to buy", "2 due today", "3 this week").
// A module registers a provider from its service provider boot(); it returns a translation key + params (rendered in
// the browser, in the viewer's language), or null for no line. Only enabled modules run, so a provider needn't check.
// Like DashboardWidgets: keyed by module id (idempotent under Octane), a failing provider is logged and skipped.
class DashboardSummaries
{
    /** @var array<string, Closure(int): (array{key: string, params?: array<string, int|string>}|null)> */
    private static array $providers = [];

    public static function register(string $moduleId, Closure $provider): void
    {
        self::$providers[$moduleId] = $provider;
    }

    /** @return array<string, array{key: string, params?: array<string, int|string>}> */
    public static function collect(): array
    {
        $userId = auth()->id();
        $familyId = CurrentFamily::id();
        if (! $userId || ! $familyId) {
            return [];
        }
        $out = [];
        foreach (app(ModuleRegistry::class)->enabledIds($familyId) as $moduleId) {
            if (! isset(self::$providers[$moduleId])) {
                continue;
            }
            try {
                if (($line = (self::$providers[$moduleId])($userId)) !== null) {
                    $out[$moduleId] = $line;
                }
            } catch (\Throwable $e) {
                Log::warning("dashboard-summaries provider [{$moduleId}] failed", ['error' => $e->getMessage()]);
            }
        }

        return $out;
    }
}
