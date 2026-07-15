<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\DB;

class ModuleRegistry
{
    public function __construct(protected string $modulesPath) {}

    public function discover(): array
    {
        $out = [];
        foreach (glob($this->modulesPath.'/*/module.json') as $file) {
            $manifest = ModuleManifest::fromPath(dirname($file));
            $out[$manifest->id] = $manifest;
        }

        return $out;
    }

    public function manifest(string $id): ?ModuleManifest
    {
        return $this->discover()[$id] ?? null;
    }

    public function isInstalled(string $id): bool
    {
        return DB::table('modules')->where('module_id', $id)->exists();
    }

    public function markInstalled(string $id, string $version): void
    {
        DB::table('modules')->updateOrInsert(['module_id' => $id], ['version' => $version]);
    }

    public function isEnabled(string $id, int $familyId): bool
    {
        return DB::table('family_modules')
            ->where(['family_id' => $familyId, 'module_id' => $id, 'enabled' => true])
            ->exists();
    }

    public function enable(string $id, int $familyId): void
    {
        if (! $this->isInstalled($id)) {
            throw new \RuntimeException("Module [{$id}] is not installed.");
        }
        // Only a row flip. Never DDL (spec 12.2).
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => true],
        );
    }

    public function disable(string $id, int $familyId): void
    {
        DB::table('family_modules')->updateOrInsert(
            ['family_id' => $familyId, 'module_id' => $id],
            ['enabled' => false],
        );
    }
}
