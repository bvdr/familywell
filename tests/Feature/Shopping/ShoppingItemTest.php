<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

beforeEach(fn () => $this->artisan('modules:migrate')->assertExitCode(0));

it('scopes lists and items to the current family', function () {
    $one = Family::factory()->create();
    $two = Family::factory()->create();
    $author = User::factory()->create();

    CurrentFamily::set($one->id);
    $list = ShoppingList::create(['name' => 'Ours', 'created_by' => $author->id]);
    $list->items()->create(['name' => 'Milk', 'created_by' => $author->id]);

    CurrentFamily::set($two->id);
    ShoppingList::create(['name' => 'Theirs', 'created_by' => $author->id]);

    CurrentFamily::set($one->id);
    expect(ShoppingList::pluck('name')->all())->toBe(['Ours'])
        ->and(ShoppingItem::pluck('name')->all())->toBe(['Milk']);
});

it('installs the shopping module', function () {
    expect(app(ModuleRegistry::class)->isInstalled('shopping'))->toBeTrue();
});
