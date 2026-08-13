<?php

use App\Support\Modules\ModuleManifest;

it('reads a module manifest from disk', function () {
    $m = ModuleManifest::fromPath(base_path('modules/hello'));

    expect($m->id)->toBe('hello')
        ->and($m->version)->toBe('1.0.0')
        ->and($m->provider)->toBe('Modules\\Hello\\HelloServiceProvider')
        ->and($m->tablePrefix())->toBe('hello_')
        // The modules page needs a human-readable blurb so a family knows what enabling this does.
        ->and($m->description)->toBeString()->not->toBeEmpty();
});
