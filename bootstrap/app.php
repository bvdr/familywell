<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireFamily;
use App\Http\Middleware\SetActiveFamily;
use App\Http\Middleware\ShareModules;
use App\Http\Middleware\ShareRealtime;
use App\Http\Middleware\ShareTheme;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // App Platform terminates TLS at its load balancer and forwards plain HTTP to the container,
        // so without this Laravel builds http:// URLs and secure cookies never stick. '*' is safe here:
        // the container is only reachable through the LB, never directly.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\SetLocale::class,
            SetActiveFamily::class,
            ShareTheme::class,
            ShareModules::class,
            ShareRealtime::class,
        ]);

        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
            'family' => RequireFamily::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
