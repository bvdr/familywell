<?php

use App\Support\Modules\ModuleNamespace;

it('allows a table inside the module namespace', function () {
    ModuleNamespace::assertTable('important_todos', 'important_todos_flags');
})->throwsNoExceptions();

it('rejects a table outside the module namespace', function () {
    expect(fn () => ModuleNamespace::assertTable('important_todos', 'users'))
        ->toThrow(RuntimeException::class);
});
