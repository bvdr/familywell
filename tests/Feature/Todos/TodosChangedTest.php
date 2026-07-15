<?php

use App\Events\TodosChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts on the family channel', function () {
    $e = new TodosChanged(9);
    expect($e->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($e->broadcastOn()[0]->name)->toBe('private-family.9')
        ->and($e->broadcastAs())->toBe('TodosChanged');
});

it('dispatches TodosChanged when an item is created', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $this->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('todos', $family->id);

    Event::fake([TodosChanged::class]);
    $this->actingAs($user)->postJson('/m/todos/items', ['title' => 'x', 'visibility' => 'shared'])->assertCreated();
    Event::assertDispatched(TodosChanged::class, fn ($e) => $e->familyId === $family->id);
});
