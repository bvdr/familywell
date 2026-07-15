<?php

namespace Modules\Hello;

use App\Support\Modules\Hooks;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HelloServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
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
