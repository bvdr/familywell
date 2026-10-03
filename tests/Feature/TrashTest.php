<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Storage;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

// Reversible delete (App\Support\Trash): deletes soft-delete into a 30-day trash, children follow their
// parent with the same timestamp, restores bring the family of rows back, and trash:purge finishes the job.

function trashList(): array
{
    $users = User::factory()->count(1)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    $family->members()->attach($users[0], ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach (['tasks', 'spending'] as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }
    CurrentFamily::set($family->id);
    $list = TaskList::create(['name' => 'Chores', 'created_by' => $users[0]->id]);
    $list->shares()->create(['user_id' => $users[0]->id, 'role' => 'owner']);
    $top = $list->tasks()->create(['title' => 'Kitchen', 'created_by' => $users[0]->id]);
    $sub = $list->tasks()->create(['title' => 'Dishes', 'parent_id' => $top->id, 'created_by' => $users[0]->id]);

    return [$family, $users, $list, $top, $sub];
}

it('trashes a list with its tasks under one timestamp, and restores them together', function () {
    [, $users, $list, $top, $sub] = trashList();
    $earlier = $list->tasks()->create(['title' => 'Already gone', 'created_by' => $users[0]->id]);
    $this->travel(-1)->hours(fn () => $earlier->delete()); // trashed on its own, before the list

    actingAs($users[0])->delete("/m/tasks/lists/{$list->id}")->assertRedirect();

    $this->assertSoftDeleted($list);
    expect(Task::count())->toBe(0)
        ->and($top->fresh()->deleted_at->equalTo($list->fresh()->deleted_at))->toBeTrue()
        ->and($sub->fresh()->deleted_at->equalTo($list->fresh()->deleted_at))->toBeTrue();

    $list->fresh()->restore();
    // The list's own tasks come back; the one deleted earlier on its own stays in the trash.
    expect(Task::pluck('title')->sort()->values()->all())->toBe(['Dishes', 'Kitchen'])
        ->and($earlier->fresh()->trashed())->toBeTrue();
});

it('restoring a single task restores its trashed parent task and list first', function () {
    [, $users, $list, , $sub] = trashList();
    actingAs($users[0])->delete("/m/tasks/lists/{$list->id}");

    Task::withTrashed()->find($sub->id)->restore();

    expect(TaskList::find($list->id))->not->toBeNull()
        ->and(Task::pluck('title')->sort()->values()->all())->toBe(['Dishes', 'Kitchen']);
});

it('deleting a task trashes only it and its sub-tasks', function () {
    [, $users, $list, $top] = trashList();
    $other = $list->tasks()->create(['title' => 'Laundry', 'created_by' => $users[0]->id]);

    actingAs($users[0])->delete("/m/tasks/tasks/{$top->id}")->assertRedirect();

    expect(Task::pluck('title')->all())->toBe(['Laundry'])
        ->and(TaskList::find($list->id))->not->toBeNull()
        ->and($other->fresh()->trashed())->toBeFalse();
});

it('purges only what has been in the trash past the window', function () {
    [, , $list, $top] = trashList();
    $this->travel(-31)->days(fn () => $list->delete()); // old enough
    $fresh = TaskList::create(['name' => 'Recent', 'created_by' => $top->created_by]);
    $fresh->delete(); // just now

    $this->artisan('trash:purge')->assertSuccessful();

    expect(TaskList::withTrashed()->pluck('name')->all())->toBe(['Recent'])
        ->and(Task::withTrashed()->count())->toBe(0); // the old list's tasks went with it
});

it('keeps an expense receipt photo until the trash is purged', function () {
    Storage::fake('r2');
    [$family, $users] = trashList();
    $event = SpendEvent::create(['name' => 'Home', 'currency' => 'EUR', 'created_by' => $users[0]->id]);
    $spend = $event->spends()->create(['payer_id' => $users[0]->id, 'amount' => 500, 'currency' => 'EUR', 'base_amount' => 500, 'rate' => 1, 'spent_at' => '2026-09-01', 'created_by' => $users[0]->id]);
    Storage::disk('r2')->put('receipts/x.jpg', 'img');
    $spend->receipts()->create(['path' => 'receipts/x.jpg']);

    $spend->delete();
    Storage::disk('r2')->assertExists('receipts/x.jpg');

    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertSuccessful();
    Storage::disk('r2')->assertMissing('receipts/x.jpg');
    expect(Spend::withTrashed()->count())->toBe(0);
});
