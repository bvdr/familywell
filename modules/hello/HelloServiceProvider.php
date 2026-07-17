<?php

namespace Modules\Hello;

use App\Events\FamilyPinged;
use App\Support\Modules\Hooks;
use App\Support\Realtime;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HelloServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        // Backend logic reacting to a hook: record a family-scoped ping (uses the P1 tenant scope)
        // and broadcast it live to the family's private channel.
        $hooks->listen('hello.pinged', function () {
            $familyId = (int) CurrentFamily::id();
            DB::table('hello_pings')->insert([
                'family_id' => $familyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Realtime::broadcast(new FamilyPinged($familyId));
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
