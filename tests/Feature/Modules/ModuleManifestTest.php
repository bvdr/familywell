<?php

use App\Support\Modules\ModuleManifest;

it('reads a module manifest from disk', function () {
    $m = ModuleManifest::fromPath(base_path('modules/important_todos'));

    expect($m->id)->toBe('important_todos')
        ->and($m->version)->toBe('1.0.0')
        ->and($m->provider)->toBe('Modules\\ImportantTodos\\ImportantTodosServiceProvider')
        ->and($m->tablePrefix())->toBe('important_todos_')
        // The modules page needs a human-readable blurb so a family knows what enabling this does.
        ->and($m->description)->toBeString()->not->toBeEmpty();
});
