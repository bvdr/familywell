<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Support\Modules\ModuleRegistry::class, function () {
            return new \App\Support\Modules\ModuleRegistry(base_path('modules'));
        });

        $this->app->singleton(\App\Support\Modules\Hooks::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
