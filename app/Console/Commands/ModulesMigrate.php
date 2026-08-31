<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleNamespace;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class ModulesMigrate extends Command
{
    protected $signature = 'modules:migrate';

    protected $description = 'Run migrations for all discovered modules and mark them installed';

    public function handle(ModuleRegistry $registry): int
    {
        foreach ($this->orderByDependencies($registry->discover()) as $manifest) {
            $path = $manifest->migrationsPath();
            if (is_dir($path)) {
                // Namespace validation before any DDL (spec 12.3).
                foreach (glob($path.'/*.php') as $file) {
                    foreach (ModuleNamespace::tablesInMigration($file) as $table) {
                        ModuleNamespace::assertTable($manifest->id, $table);
                    }
                }
                $this->call('migrate', [
                    '--path' => str_replace(base_path().'/', '', $path),
                    '--force' => true,
                ]);
            }
            $registry->markInstalled($manifest->id, $manifest->version);
            $this->info("Installed {$manifest->id} {$manifest->version}");
        }

        return self::SUCCESS;
    }

    /**
     * Order modules so each module's `requires` dependencies migrate BEFORE it. A module may FK
     * another module's tables (birthdays -> contacts_people, important_todos -> tasks_tasks), but
     * discover() returns alphabetical order, which can run the dependent first and fail the FK on a
     * fresh database. Topological (DFS) sort; unknown deps and cycles are skipped, not fatal.
     *
     * @param  array<string, ModuleManifest>  $manifests
     * @return list<ModuleManifest>
     */
    private function orderByDependencies(array $manifests): array
    {
        $ordered = [];
        $visiting = [];

        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, $manifests): void {
            if (isset($ordered[$id]) || ! isset($manifests[$id]) || isset($visiting[$id])) {
                return; // already placed, unknown dependency, or a cycle — skip
            }
            $visiting[$id] = true;
            foreach ($manifests[$id]->requires as $dep) {
                $visit($dep);
            }
            unset($visiting[$id]);
            $ordered[$id] = $manifests[$id];
        };

        foreach (array_keys($manifests) as $id) {
            $visit($id);
        }

        return array_values($ordered);
    }
}
