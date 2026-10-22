<?php

// Boots the app the way an Octane worker does (HTTP kernel bootstrap + the services Octane pre-warms) and prints
// the real error if that fails. Without it a broken boot only shows FrankenPHP's "worker ... has not reached
// frankenphp_handle_request()", which never says why: a malformed APP_KEY (no base64: prefix, wrong length) did
// exactly that for a self-hoster after the empty-key case was fixed. Run by docker/start.sh before Octane.

require __DIR__.'/../vendor/autoload.php';

try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
    // Octane gives each worker a request before warming; services like `url` need one.
    $app->instance('request', Illuminate\Http\Request::create('/'));
    // Only what the app binds: Octane's list also names worker-only bindings (e.g. `routes`) that don't exist here.
    foreach ($app['config']->get('octane.warm', []) as $service) {
        if ($app->bound($service)) {
            $app->make($service);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nFamilyWell can't start: ".$e->getMessage()."\n");
    if (str_contains($e->getMessage(), 'cipher') || str_contains($e->getMessage(), 'key length')) {
        fwrite(STDERR, "APP_KEY is malformed. It must look like base64:<44 characters>. Remove the APP_KEY line from .env to let\n"
            ."the container generate one, or create one with: docker compose run --rm --no-deps app php artisan key:generate --show\n");
    }
    fwrite(STDERR, '('.get_class($e).' in '.$e->getFile().':'.$e->getLine().")\n\n");
    exit(1);
}
