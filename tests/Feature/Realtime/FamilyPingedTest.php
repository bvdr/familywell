<?php

use App\Events\FamilyPinged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts on the private family channel', function () {
    $event = new FamilyPinged(7);
    expect($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-family.7')
        ->and($event->broadcastAs())->toBe('FamilyPinged');
});

it('dispatches FamilyPinged when the hello route is hit', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $this->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('hello', $family->id);

    Event::fake([FamilyPinged::class]);
    $this->actingAs($user)->get('/m/hello')->assertOk();
    Event::assertDispatched(FamilyPinged::class, fn ($e) => $e->familyId === $family->id);
});
