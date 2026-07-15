<?php

namespace App\Support\Modules;

class ModuleNamespace
{
    public static function assertTable(string $moduleId, string $table): void
    {
        $prefix = $moduleId.'_';
        if (! str_starts_with($table, $prefix)) {
            throw new \RuntimeException(
                "Module [{$moduleId}] may not touch table [{$table}] outside its namespace [{$prefix}]."
            );
        }
    }

    /** @return list<string> table names created in a migration file */
    public static function tablesInMigration(string $file): array
    {
        $src = file_get_contents($file);
        preg_match_all('/Schema::create\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $src, $m);

        return $m[1] ?? [];
    }
}
