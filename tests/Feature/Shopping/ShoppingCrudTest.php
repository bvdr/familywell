<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Shopping\ShoppingItem;

function shoppingFamily(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('shopping', $family->id);

    return [$user, $family];
}

it('404s when not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');
    $this->actingAs($user)->getJson('/m/shopping/items')->assertNotFound();
});

it('creates, lists, toggles bought, deletes', function () {
    [$user] = shoppingFamily();
    $this->actingAs($user)->postJson('/m/shopping/items', ['name' => 'Eggs', 'visibility' => 'shared'])
        ->assertCreated()->assertJsonPath('name', 'Eggs');
    $item = ShoppingItem::withoutGlobalScopes()->first();
    $this->actingAs($user)->getJson('/m/shopping/items')->assertOk()->assertJsonFragment(['name' => 'Eggs']);
    $this->actingAs($user)->patchJson("/m/shopping/items/{$item->id}")->assertOk()->assertJsonPath('bought', true);
    $this->actingAs($user)->deleteJson("/m/shopping/items/{$item->id}")->assertOk();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(0);
});

it('hides another member private item', function () {
    [$alice, $family] = shoppingFamily();
    $bob = User::factory()->create();
    $family->members()->attach($bob, ['role' => 'member']);
    $this->actingAs($bob)->postJson('/m/shopping/items', ['name' => 'secret', 'visibility' => 'private'])->assertCreated();
    $this->actingAs($alice)->getJson('/m/shopping/items')->assertOk()->assertJsonMissing(['name' => 'secret']);
});
