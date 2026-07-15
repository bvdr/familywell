<?php

use App\Models\Family;
use App\Support\Modules\ModuleRegistry;

beforeEach(fn () => $this->registry = app(ModuleRegistry::class));

it('discovers the hello module', function () {
    expect($this->registry->discover())->toHaveKey('hello');
});

it('cannot enable a module that is not installed', function () {
    $family = Family::factory()->create();

    expect(fn () => $this->registry->enable('hello', $family->id))
        ->toThrow(RuntimeException::class);
});

it('installs, then enables and disables per family without touching schema', function () {
    $family = Family::factory()->create();

    $this->registry->markInstalled('hello', '1.0.0');
    expect($this->registry->isInstalled('hello'))->toBeTrue()
        ->and($this->registry->isEnabled('hello', $family->id))->toBeFalse();

    $this->registry->enable('hello', $family->id);
    expect($this->registry->isEnabled('hello', $family->id))->toBeTrue();

    $this->registry->disable('hello', $family->id);
    expect($this->registry->isEnabled('hello', $family->id))->toBeFalse();
});
