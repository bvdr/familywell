<?php

use App\Events\ShoppingChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts on the family channel', function () {
    $e = new ShoppingChanged(4);
    expect($e->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($e->broadcastOn()[0]->name)->toBe('private-family.4')
        ->and($e->broadcastAs())->toBe('ShoppingChanged');
});

it('dispatches on create', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $this->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('shopping', $family->id);

    Event::fake([ShoppingChanged::class]);
    $this->actingAs($user)->postJson('/m/shopping/items', ['name' => 'x', 'visibility' => 'shared'])->assertCreated();
    Event::assertDispatched(ShoppingChanged::class, fn ($e) => $e->familyId === $family->id);
});
