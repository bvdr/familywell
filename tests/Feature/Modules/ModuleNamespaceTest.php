<?php

use App\Support\Modules\ModuleNamespace;

it('allows a table inside the module namespace', function () {
    ModuleNamespace::assertTable('hello', 'hello_pings');
})->throwsNoExceptions();

it('rejects a table outside the module namespace', function () {
    expect(fn () => ModuleNamespace::assertTable('hello', 'users'))
        ->toThrow(RuntimeException::class);
});
