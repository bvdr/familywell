<?php

namespace Modules\Hello;

use App\Support\Modules\Hooks;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HelloServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        // Backend logic reacting to a hook: record a family-scoped ping (uses the P1 tenant scope).
        $hooks->listen('hello.pinged', function () {
            \Illuminate\Support\Facades\DB::table('hello_pings')->insert([
                'family_id' => \App\Support\Tenancy\CurrentFamily::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Route::middleware(['web', 'auth', 'module:hello'])->group(function () use ($hooks) {
            Route::get('/m/hello', function () use ($hooks) {
                $hooks->fire('hello.pinged');

                return response()->json([
                    'message' => $hooks->filter('hello.greeting', 'Hello'),
                ]);
            })->name('hello.index');
        });
    }
}
