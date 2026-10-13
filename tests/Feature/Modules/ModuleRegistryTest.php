<?php

use App\Models\Family;
use App\Support\Modules\ModuleRegistry;

beforeEach(fn () => $this->registry = app(ModuleRegistry::class));

it('discovers the bundled modules', function () {
    expect($this->registry->discover())->toHaveKey('important_todos');
});

it('cannot enable a module that is not installed', function () {
    $family = Family::factory()->create();

    expect(fn () => $this->registry->enable('important_todos', $family->id))
        ->toThrow(RuntimeException::class);
});

it('installs, then enables and disables per family without touching schema', function () {
    $family = Family::factory()->create();

    $this->registry->markInstalled('important_todos', '1.0.0');
    expect($this->registry->isInstalled('important_todos'))->toBeTrue()
        ->and($this->registry->isEnabled('important_todos', $family->id))->toBeFalse();

    $this->registry->enable('important_todos', $family->id);
    expect($this->registry->isEnabled('important_todos', $family->id))->toBeTrue();

    $this->registry->disable('important_todos', $family->id);
    expect($this->registry->isEnabled('important_todos', $family->id))->toBeFalse();
});
