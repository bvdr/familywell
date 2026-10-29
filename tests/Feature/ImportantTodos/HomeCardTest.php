<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Dashboard\DashboardData;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\ImportantTodos\ImportantFlag;
use Modules\Notifications\PushAlert;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

// Home's "Your important" card (important_todos module): yours vs the family's, urgency order, up to 3 per person
// (also enforced when starring), lifted out of the agenda, list visibility, and the Home layout section.

function importantFamily(int $members = 3): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach (['tasks', 'important_todos'] as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }

    return [$family, $users];
}

// A to-do in $list, starred through the real endpoint by $by (so the limit and starred_by apply).
function starred(User $by, TaskList $list, string $title, ?string $due = null, ?User $assignee = null): Task
{
    actingAs($by)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => $title, 'due_date' => $due, 'assigned_to' => $assignee?->id]);
    $task = Task::withoutGlobalScopes()->where('title', $title)->firstOrFail();
    actingAs($by)->patch("/m/tasks/tasks/{$task->id}", ['important' => true])->assertSessionHasNoErrors();

    return $task;
}

function homeList(User $by, string $name = 'Household'): TaskList
{
    actingAs($by)->post('/m/tasks/lists', ['name' => $name]);

    return TaskList::withoutGlobalScopes()->where('name', $name)->firstOrFail();
}

function card(User $as): ?array
{
    actingAs($as);
    request()->attributes->remove('important_todos.card'); // per-request memo: a fresh "request" per call

    return DashboardData::build()['widgets']['important_todos'] ?? null;
}

beforeEach(fn () => Carbon::setTestNow('2026-10-21 10:00')); // a Wednesday

it('shows yours most urgent first and the others grouped by owner', function () {
    [, [$ana, $marco, $dan]] = importantFamily();
    $list = homeList($ana);

    starred($ana, $list, 'Renew passport', '2026-10-23');
    starred($ana, $list, 'Sort photos'); // undated: last
    starred($ana, $list, 'Dentist', '2026-10-17'); // overdue: first
    starred($ana, $list, 'Gutter', '2026-10-22', $marco); // assigned to Marco: his
    starred($marco, $list, 'Car insurance', '2026-11-02');
    starred($dan, $list, 'Flights');

    $c = card($ana);
    expect(array_column($c['mine'], 'title'))->toBe(['Dentist', 'Renew passport', 'Sort photos'])
        ->and($c['mine'][0])->toMatchArray(['list' => 'Household', 'dueDate' => '2026-10-17', 'toggleUrl' => "/m/tasks/tasks/{$c['mine'][0]['id']}"])
        ->and(array_column(array_column($c['others'], 'owner'), 'id'))->toBe([$marco->id, $dan->id])
        ->and(array_column($c['others'][0]['items'], 'title'))->toBe(['Gutter', 'Car insurance']);

    // From Marco's side the same to-dos regroup: his are "mine", Ana's are the family's.
    expect(array_column(card($marco)['mine'], 'title'))->toBe(['Gutter', 'Car insurance']);
});

it('refuses a 4th open star per person, with the owner named, and frees the slot once one is done', function () {
    [, [$ana, $marco]] = importantFamily();
    $list = homeList($ana);
    $done = starred($ana, $list, 'One');
    starred($ana, $list, 'Two');
    starred($ana, $list, 'Three');

    actingAs($ana)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Four']);
    $four = Task::withoutGlobalScopes()->where('title', 'Four')->firstOrFail();
    actingAs($ana)->patchJson("/m/tasks/tasks/{$four->id}", ['important' => true])
        ->assertStatus(422)->assertJsonPath('errors.important.0', 'You already have 3 important to-dos open. Finish or unstar one first.');
    expect(ImportantFlag::withoutGlobalScopes()->where('task_id', $four->id)->exists())->toBeFalse();

    // Starring something assigned to a full person is refused in their name.
    actingAs($ana)->patch("/m/tasks/tasks/{$four->id}", ['assigned_to' => $marco->id]);
    foreach (['M1', 'M2', 'M3'] as $t) {
        starred($marco, $list, $t);
    }
    actingAs($ana)->patchJson("/m/tasks/tasks/{$four->id}", ['important' => true])
        ->assertStatus(422)->assertJsonPath('errors.important.0', "{$marco->name} already has 3 important to-dos open. One of them has to be finished or unstarred first.");

    // Done ones don't count.
    actingAs($ana)->patch("/m/tasks/tasks/{$done->id}", ['done' => true]);
    actingAs($ana)->patch("/m/tasks/tasks/{$four->id}", ['assigned_to' => null]);
    actingAs($ana)->patchJson("/m/tasks/tasks/{$four->id}", ['important' => true])->assertRedirect();
});

it('lifts only your important ones out of Today, Tomorrow and Anytime', function () {
    [, [$ana, $marco]] = importantFamily();
    $list = homeList($ana);
    starred($ana, $list, 'My today', '2026-10-21');
    starred($ana, $list, 'My tomorrow', '2026-10-22');
    starred($ana, $list, 'My anytime');
    starred($marco, $list, 'His today', '2026-10-21');
    actingAs($ana)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Plain anytime']);

    actingAs($ana);
    request()->attributes->remove('important_todos.card');
    $d = DashboardData::build();
    expect(array_column($d['today'], 'title'))->toBe(['His today'])
        ->and(array_column($d['tomorrow'], 'title'))->toBe([])
        ->and($d['widgets']['tasks'])->toBe(['undated' => 1, 'titles' => ['Plain anytime']]);

    // Unstarred: back on the agenda.
    $mine = Task::withoutGlobalScopes()->where('title', 'My today')->first();
    actingAs($ana)->patch("/m/tasks/tasks/{$mine->id}", ['important' => false]);
    request()->attributes->remove('important_todos.card');
    expect(array_column(DashboardData::build()['today'], 'title'))->toContain('My today');
});

it('only shows to-dos on lists the viewer can see, and hides when nobody starred anything', function () {
    [, [$ana, $marco]] = importantFamily();
    expect(card($ana))->toBeNull();

    $private = homeList($marco, 'Marco only');
    actingAs($marco)->patch("/shares/task_list/{$private->id}/visibility", ['visibility' => 'private']);
    starred($marco, $private, 'Surprise party');

    expect(card($marco)['mine'])->toHaveCount(1)
        ->and(card($ana))->toBeNull();
});

it('is off with the module, or when hidden in Settings > Home dashboard (then nothing is lifted)', function () {
    [$family, [$ana]] = importantFamily();
    $list = homeList($ana);
    starred($ana, $list, 'My today', '2026-10-21');

    $ana->forceFill(['home_layout' => ['order' => [], 'hidden' => ['important']]])->save();
    expect(card($ana->fresh()))->toBeNull()
        ->and(array_column(DashboardData::build()['today'], 'title'))->toBe(['My today']);

    $ana->forceFill(['home_layout' => null])->save();
    app(ModuleRegistry::class)->disable('important_todos', $family->id);
    expect(card($ana->fresh()))->toBeNull();
});

it('puts the important section first, also for a layout saved before it existed', function () {
    [, [$ana]] = importantFamily();
    $ana->forceFill(['home_layout' => ['order' => ['money', 'today', 'week', 'anytime', 'modules', 'recent'], 'hidden' => ['recent']]])->save();

    actingAs($ana)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('sections', ['important', 'today', 'week', 'anytime', 'modules']));

    // Once placed, it stays where the person put it.
    actingAs($ana)->patch('/settings/home', ['order' => ['today', 'important'], 'hidden' => []]);
    actingAs($ana)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('sections', ['today', 'important', 'week', 'anytime', 'modules', 'recent']));
});

it('pings the owner when someone else ticks their important to-do off', function () {
    Notification::fake();
    [, [$ana, $marco]] = importantFamily();
    $list = homeList($ana);
    $task = starred($marco, $list, 'Fix the gutter');

    actingAs($ana)->patch("/m/tasks/tasks/{$task->id}", ['done' => true])->assertRedirect();

    Notification::assertSentTo($marco, PushAlert::class, fn (PushAlert $n) => $n->body === "{$ana->name} completed: Fix the gutter");
});
