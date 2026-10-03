<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Notes\Note;
use Modules\Shopping\ShoppingList;
use Modules\Spending\SpendEvent;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

// Settings > Recently deleted: the family's trash across modules, filtered by each module's sharing
// rules and by which modules the family has enabled, with Restore.

function trashFamily(array $modules = ['tasks', 'shopping', 'spending', 'notes']): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach ($modules as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }
    CurrentFamily::set($family->id);

    return [$family, $users];
}

it('lists deleted things from every module, newest first, and restores one', function () {
    [, $users] = trashFamily();
    $list = TaskList::createFor($users[0], ['name' => 'Chores']);
    $task = $list->addTask($users[0], ['title' => 'Dishes']);
    $shop = ShoppingList::createFor($users[0], ['name' => 'Groceries']);
    $item = $shop->items()->create(['name' => 'Milk', 'created_by' => $users[0]->id]);
    $event = SpendEvent::createFor($users[0], ['name' => 'Home', 'currency' => 'EUR']);
    $spend = $event->spends()->create([...$event->spendColumns(['payer_key' => 'u'.$users[0]->id, 'amount' => 12.5, 'currency' => 'EUR', 'category' => 'food', 'note' => 'Lunch', 'spent_at' => '2026-09-01']), 'created_by' => $users[0]->id]);
    $note = Note::createFor($users[0], ['title' => 'Wifi']);

    $task->delete();
    $this->travel(1)->minutes();
    $item->delete();
    $this->travel(1)->minutes();
    $spend->delete();
    $this->travel(1)->minutes();
    $note->delete();

    actingAs($users[0])->get('/settings/recently-deleted')->assertOk()->assertInertia(fn ($p) => $p
        ->component('settings/recently-deleted')
        ->where('days', 30)
        ->where('items', fn ($items) => collect($items)->map(fn ($i) => "{$i['module']}:{$i['type']}:{$i['title']}")->all() === [
            'notes:note:Wifi', 'spending:expense:Lunch', 'shopping:item:Milk', 'tasks:task:Dishes',
        ] && collect($items)->firstWhere('type', 'expense')['amount'] === '12.50 EUR'
          && collect($items)->firstWhere('type', 'task')['where'] === 'Chores'));

    actingAs($users[0])->post("/settings/recently-deleted/tasks/task/{$task->id}")->assertRedirect();
    expect(Task::find($task->id))->not->toBeNull();
    // Already restored: nothing to restore any more.
    actingAs($users[0])->post("/settings/recently-deleted/tasks/task/{$task->id}")->assertNotFound();
});

it('hides another member\'s private things and modules the family turned off', function () {
    [$family, $users] = trashFamily();
    $private = Note::createFor($users[1], ['title' => 'Diary']);
    $private->setVisibility('private');
    $private->delete();
    $list = TaskList::createFor($users[1], ['name' => 'Shared list']);
    $list->delete();

    actingAs($users[0])->get('/settings/recently-deleted')->assertInertia(fn ($p) => $p
        ->where('items', fn ($items) => collect($items)->pluck('title')->all() === ['Shared list']));
    actingAs($users[0])->post("/settings/recently-deleted/notes/note/{$private->id}")->assertNotFound();

    app(ModuleRegistry::class)->disable('tasks', $family->id);
    actingAs($users[0])->get('/settings/recently-deleted')->assertInertia(fn ($p) => $p->where('items', []));
    actingAs($users[0])->post("/settings/recently-deleted/tasks/list/{$list->id}")->assertNotFound();
});
