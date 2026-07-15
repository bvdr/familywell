<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Todos\TodoItem;

beforeEach(function () {
    $this->artisan('modules:migrate')->assertExitCode(0); // creates todos_items
});

it('scopes items to the active family and by visibility', function () {
    $famA = Family::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    CurrentFamily::set($famA->id);
    TodoItem::create(['title' => 'shared task', 'visibility' => 'shared', 'created_by' => $alice->id]);
    TodoItem::create(['title' => 'alice private', 'visibility' => 'private', 'created_by' => $alice->id]);
    TodoItem::create(['title' => 'bob private', 'visibility' => 'private', 'created_by' => $bob->id]);

    // Bob sees shared + his own private, not alice's private.
    $titles = TodoItem::visibleTo($bob->id)->pluck('title')->all();
    expect($titles)->toContain('shared task', 'bob private')
        ->not->toContain('alice private');
});

it('installs the todos module', function () {
    expect(app(ModuleRegistry::class)->isInstalled('todos'))->toBeTrue();
});
