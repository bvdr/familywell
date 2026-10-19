<?php

use App\Support\Dashboard\DashboardData;
use Illuminate\Support\Carbon;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/Calendar/helpers.php';

// The dashboard's tick sends each row's own toggleBody (shopping says `bought`, tasks say `done`), never an
// empty "flip": a second tap, or a retried request, must leave the item done instead of undoing it.

it('gives every tickable row a body that sets, never flips', function () {
    Carbon::setTestNow('2026-10-19 10:00');
    [, [$user]] = calendarFamily(['tasks', 'shopping', 'calendar', 'contacts']);
    actingAs($user);

    $this->post('/m/tasks/lists', ['name' => 'House']);
    $list = TaskList::withoutGlobalScopes()->firstOrFail();
    $this->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Call the plumber']);
    Task::withoutGlobalScopes()->where('title', 'Call the plumber')->update(['due_date' => '2026-10-19']);

    $this->post('/m/shopping/lists', ['name' => 'Groceries']);
    $shop = ShoppingList::withoutGlobalScopes()->firstOrFail();
    $this->post("/m/shopping/lists/{$shop->id}/items", ['name' => 'Milk', 'due_date' => '2026-10-19']);

    $rows = collect(DashboardData::build()['today'])->whereNotNull('toggleUrl')->keyBy('kind');
    expect($rows['task']['toggleBody'])->toBe(['done' => true])
        ->and($rows['shopping_item']['toggleBody'])->toBe(['bought' => true]);

    foreach ($rows as $row) {
        $this->patch($row['toggleUrl'], $row['toggleBody'])->assertRedirect();
        $this->patch($row['toggleUrl'], $row['toggleBody'])->assertRedirect(); // a double tap / retry
    }
    expect(Task::withoutGlobalScopes()->where('title', 'Call the plumber')->value('done'))->toBeTrue()
        ->and(ShoppingItem::withoutGlobalScopes()->where('name', 'Milk')->value('bought'))->toBeTrue()
        ->and(collect(DashboardData::build()['today'])->whereNotNull('toggleUrl'))->toBeEmpty();
});
