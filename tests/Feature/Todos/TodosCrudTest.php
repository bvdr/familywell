<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Todos\TodoItem;

function todosFamily(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('todos', $family->id);

    return [$user, $family];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    $this->actingAs($user)->getJson('/m/todos/items')->assertNotFound();
});

it('creates, lists, toggles, and deletes an item', function () {
    [$user] = todosFamily();

    $this->actingAs($user)->postJson('/m/todos/items', ['title' => 'Buy milk', 'visibility' => 'shared'])
        ->assertCreated()->assertJsonPath('title', 'Buy milk');

    $item = TodoItem::withoutGlobalScopes()->first();
    $this->actingAs($user)->getJson('/m/todos/items')->assertOk()->assertJsonFragment(['title' => 'Buy milk']);

    $this->actingAs($user)->patchJson("/m/todos/items/{$item->id}")->assertOk()->assertJsonPath('done', true);
    $this->actingAs($user)->deleteJson("/m/todos/items/{$item->id}")->assertOk();

    expect(TodoItem::withoutGlobalScopes()->count())->toBe(0);
});

it('hides another member private item', function () {
    [$alice, $family] = todosFamily();
    $bob = User::factory()->create();
    $family->members()->attach($bob, ['role' => 'member']);

    $this->actingAs($bob)->postJson('/m/todos/items', ['title' => 'bob secret', 'visibility' => 'private'])->assertCreated();

    // Alice cannot see bob's private item.
    $this->actingAs($alice)->getJson('/m/todos/items')->assertOk()->assertJsonMissing(['title' => 'bob secret']);
});
