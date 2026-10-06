<?php

namespace App\Support\Mcp;

use App\Support\Modules\ModuleRegistry;

// Registry of MCP tools, one entry per module (follows DashboardItems / NotificationCategories). A module
// registers its tool classes from its service provider's boot(); the MCP server serves every registered
// class and each tool decides per request whether it is visible (App\Mcp\Tool::shouldRegister).
// The module list also drives the OAuth scopes (`<module>:read` / `<module>:write`) and the rows on the
// consent screen, so a module that registers nothing never appears there. Keyed by module id so
// re-registration under Octane is idempotent.
class McpTools
{
    /** Pseudo-module for the always-available family/today tools. Not a scope, never on the consent screen. */
    public const CORE = 'core';

    /** @var array<string, array{name: string, tools: list<class-string>}> */
    private static array $modules = [];

    /** @param  list<class-string>  $tools */
    public static function register(string $moduleId, string $name, array $tools): void
    {
        self::$modules[$moduleId] = ['name' => $name, 'tools' => $tools];
    }

    /** @return array<string, string> module id => display name, core excluded (consent screen + scopes). */
    public static function modules(): array
    {
        return collect(self::$modules)->except(self::CORE)->map(fn ($m) => $m['name'])->all();
    }

    /** @return list<class-string> every registered tool class, core first. */
    public static function all(): array
    {
        return collect(self::$modules)->sortKeysUsing(fn ($a, $b) => ($b === self::CORE) <=> ($a === self::CORE) ?: strcmp($a, $b))
            ->flatMap(fn ($m) => $m['tools'])->values()->all();
    }

    /** @return array<string, string> every module scope with its description, for Passport::tokensCan(). */
    public static function scopes(): array
    {
        $scopes = [];
        foreach (self::modules() as $id => $name) {
            $scopes["{$id}:read"] = "Read your {$name}";
            $scopes["{$id}:write"] = "Change your {$name}";
        }

        return $scopes;
    }

    /**
     * Token scopes for a consent decision. `write` implies read (Read & write issues both).
     *
     * @param  array<string, string>  $access  module id => none|read|write
     * @return list<string>
     */
    public static function scopesFor(int $familyId, array $access): array
    {
        $scopes = ['mcp:use', "family:{$familyId}"];
        foreach ($access as $module => $level) {
            if ($level === 'read' || $level === 'write') {
                $scopes[] = "{$module}:read";
            }
            if ($level === 'write') {
                $scopes[] = "{$module}:write";
            }
        }

        return $scopes;
    }

    /** The family id carried by a token's scopes (`family:<id>`), or null. */
    public static function familyFromScopes(array $scopes): ?int
    {
        foreach ($scopes as $scope) {
            if (preg_match('/^family:(\d+)$/', (string) $scope, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Per-module access a token grants, for display (connected apps, get_family).
     *
     * @return array<string, 'read'|'write'> only modules with some access
     */
    public static function accessFromScopes(array $scopes): array
    {
        $out = [];
        foreach (array_keys(self::modules()) as $id) {
            if (in_array("{$id}:write", $scopes, true)) {
                $out[$id] = 'write';
            } elseif (in_array("{$id}:read", $scopes, true)) {
                $out[$id] = 'read';
            }
        }

        return $out;
    }

    /**
     * Modules turned on in the family that have MCP tools but that this token cannot touch. A token's
     * module access is frozen in its scopes at consent time, so a module enabled later stays invisible to
     * the connection until the person reconnects; get_family and Settings > Connected apps surface it.
     * Uses ModuleRegistry::enabledIds (one memoised query per family per request).
     *
     * @return list<string> module ids
     */
    public static function notConnected(int $familyId, array $scopes): array
    {
        $enabled = app(ModuleRegistry::class)->enabledIds($familyId);

        return array_values(array_diff(
            array_intersect(array_keys(self::modules()), $enabled),
            array_keys(self::accessFromScopes($scopes)),
        ));
    }
}
