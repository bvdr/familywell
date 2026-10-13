<?php

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Schema;

it('migrates every module and marks it installed', function () {
    $this->artisan('modules:migrate')->assertExitCode(0);

    $registry = app(ModuleRegistry::class);
    expect($registry->isInstalled('important_todos'))->toBeTrue()
        ->and(Schema::hasTable('important_todos_flags'))->toBeTrue();
});
