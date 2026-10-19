<?php

namespace App\Support\Modules;

class ModuleManifest
{
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $provider,
        public array $requires,
        public string $path,
        public ?string $frontend = null,
        public string $description = '',
        // A module still in preview: hidden from everyone not opted in (Preview, ModuleRegistry::enabledIds).
        public bool $preview = false,
    ) {}

    public static function fromPath(string $dir): self
    {
        $data = json_decode(file_get_contents($dir.'/module.json'), true);

        return new self(
            id: $data['id'],
            name: $data['name'] ?? $data['id'],
            version: $data['version'],
            provider: $data['provider'],
            requires: $data['requires'] ?? [],
            path: $dir,
            frontend: $data['frontend'] ?? null,
            description: $data['description'] ?? '',
            preview: (bool) ($data['preview'] ?? false),
        );
    }

    public function frontendFile(): ?string
    {
        return $this->frontend ? $this->path.'/'.$this->frontend : null;
    }

    public function migrationsPath(): string
    {
        return $this->path.'/database/migrations';
    }

    public function tablePrefix(): string
    {
        return $this->id.'_';
    }
}
