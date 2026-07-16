<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;

beforeEach(fn () => $this->artisan('modules:migrate')->assertExitCode(0));

it('scopes items by family and visibility', function () {
    $fam = Family::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    CurrentFamily::set($fam->id);
    ShoppingItem::create(['name' => 'Milk', 'visibility' => 'shared', 'created_by' => $alice->id]);
    ShoppingItem::create(['name' => 'Gift', 'visibility' => 'private', 'created_by' => $alice->id]);

    $names = ShoppingItem::visibleTo($bob->id)->pluck('name')->all();
    expect($names)->toContain('Milk')->not->toContain('Gift');
});

it('installs the shopping module', function () {
    expect(app(ModuleRegistry::class)->isInstalled('shopping'))->toBeTrue();
});
