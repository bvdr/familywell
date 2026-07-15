<?php

namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Runtime PSR-4: map each module's provider namespace to its (lowercase) folder, so modules
        // do not have to live in StudlyCase dirs. This is what lets a module be dropped in and loaded.
        /** @var ClassLoader $loader */
        $loader = require base_path('vendor/autoload.php');

        foreach ($this->app->make(ModuleRegistry::class)->discover() as $manifest) {
            $namespace = substr($manifest->provider, 0, strrpos($manifest->provider, '\\') + 1);
            $loader->addPsr4($namespace, $manifest->path);
        }
    }

    public function boot(ModuleRegistry $registry): void
    {
        // Register every discovered module's provider so its routes/hooks load once, at boot.
        // Routes are static (Octane-safe); per-family enablement is enforced by the `module` middleware.
        foreach ($registry->discover() as $manifest) {
            if (class_exists($manifest->provider)) {
                $this->app->register($manifest->provider);
            }
        }
    }
}
