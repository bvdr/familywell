<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Notifications\NotificationCategories;
use Modules\Notifications\Notify;
use Modules\Notifications\PushAlert;
use Modules\Tasks\TaskList;
use NotificationChannels\WebPush\WebPushChannel;

use function Pest\Laravel\actingAs;

function notifFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('tasks', $family->id);

    return [$family, $users];
}

it('logs to everyone with access except the actor', function () {
    [$family, $users] = notifFamily();
    // A family-visible list is visible to every member.
    $list = new TaskList(['name' => 'Chores', 'created_by' => $users[0]->id]);
    $list->family_id = $family->id; // set directly (not fillable); no request context to auto-fill from
    $list->save();
    $list->shares()->create(['user_id' => $users[0]->id, 'role' => 'owner']);

    Notify::toAccessOf($list, 'tasks', $users[0]->id, 'New task', 'Vacuum', '/m/tasks');

    expect($users[0]->fresh()->notifications()->count())->toBe(0)  // actor is skipped
        ->and($users[1]->fresh()->notifications()->count())->toBe(1);
});

it('still logs a muted category but drops it from push', function () {
    $muted = User::factory()->create(['notification_prefs' => ['tasks' => false]]);
    $open = User::factory()->create(['notification_prefs' => ['tasks' => true]]);
    $alert = new PushAlert('t', 'b', '/m/tasks', 'tasks');

    // Muted -> in-app log only; unmuted -> log + web push.
    expect($alert->via($muted))->toBe(['database'])
        ->and($alert->via($open))->toContain(WebPushChannel::class)
        ->and($alert->via($open))->toContain('database');
});

it('serves the feed and marks read', function () {
    [, $users] = notifFamily();
    $u = $users[1];
    $u->notify(new PushAlert('Hello', 'world', '/dashboard', 'tasks'));
    $u->notify(new PushAlert('Second', 'thing', '/m/tasks', 'tasks'));

    actingAs($u)->get('/notifications')->assertOk();
    expect($u->unreadNotifications()->count())->toBe(2);

    $first = $u->notifications()->latest()->first();
    actingAs($u)->post("/notifications/{$first->id}/read")->assertNoContent();
    expect($u->fresh()->unreadNotifications()->count())->toBe(1);

    actingAs($u)->post('/notifications/read-all')->assertNoContent();
    expect($u->fresh()->unreadNotifications()->count())->toBe(0);
});

it('registers a category per feature module', function () {
    notifFamily(); // boot() runs the module providers
    expect(NotificationCategories::ids())->toContain('tasks')->toContain('sharing');
});

it('shares the bell prop on page responses', function () {
    // Regression guard: the `notifications` shared prop must be produced per request (in the Inertia
    // middleware), not once in a provider boot() where Octane drops it after the first request and the
    // bell goes permanently "all caught up". This asserts the prop reaches a normal page response.
    [, $users] = notifFamily();
    $u = $users[1];
    $u->notify(new PushAlert('Ping', 'body', '/dashboard', 'tasks'));

    actingAs($u)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('notifications.unread', 1)
        ->has('notifications.items', 1)
        ->where('notifications.items.0.title', 'Ping')
    );
});
