<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleNamespace;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class ModulesMigrate extends Command
{
    protected $signature = 'modules:migrate';

    protected $description = 'Run migrations for all discovered modules and mark them installed';

    public function handle(ModuleRegistry $registry): int
    {
        foreach ($registry->discover() as $manifest) {
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
}
