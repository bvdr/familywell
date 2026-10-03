<?php

use App\Support\Tenancy\CurrentFamily;
use Modules\ImportantTodos\ImportantFlag;
use Modules\RecurringTodos\RecurringRule;
use Modules\Vehicles\MaintenanceTasks;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleMaintenanceHistory;

// The Tasks tools fire the same task.serialize / task.saved hooks as the web editor, and extensions add
// their parameters through task.mcp.schema — so Important and Recurring to-dos (and Vehicles' "maintenance
// task done") work over MCP without the Tasks module knowing about them.

function toolSchema(string $token, string $tool): array
{
    return collect(mcpCall($token, 'tools/list')->json('result.tools'))->firstWhere('name', $tool)['inputSchema']['properties'];
}

it('offers important + recurrence only when those modules are on, and persists them', function () {
    [$family, $users] = mcpFamily(['tasks', 'important_todos', 'recurring_todos']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];

    expect(toolSchema($token, 'tasks_create_task'))->toHaveKeys(['important', 'recurrence'])
        ->and(toolSchema($token, 'tasks_update_task'))->toHaveKeys(['important', 'recurrence']);

    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Home']);
    [, $task] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Bins out', 'important' => true, 'recurrence' => 'weekly', 'due_date' => '2026-10-05']);
    expect($task['important'])->toBeTrue()->and($task['recurrence'])->toBe('weekly')
        ->and(ImportantFlag::withoutGlobalScopes()->where('task_id', $task['id'])->value('important'))->toBeTrue()
        ->and(RecurringRule::withoutGlobalScopes()->where('task_id', $task['id'])->value('next_occurrence')->toDateString())->toBe('2026-10-12');

    [, $upd] = mcpTool($token, 'tasks_update_task', ['task_id' => $task['id'], 'important' => false, 'recurrence' => 'none']);
    expect($upd['important'])->toBeFalse()->and($upd['recurrence'])->toBeNull();

    [, $got] = mcpTool($token, 'tasks_get_list', ['list_id' => $list['id']]);
    expect($got['tasks'][0])->toHaveKeys(['important', 'recurrence']);
});

it('leaves the parameters out when the extensions are off', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];

    expect(toolSchema($token, 'tasks_create_task'))->not->toHaveKey('important')->not->toHaveKey('recurrence');
});

it('completes a car service when its reminder task is ticked off over MCP', function () {
    [$family, $users] = mcpFamily(['tasks', 'vehicles', 'spending']);
    CurrentFamily::set($family->id);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 150000]);
    $oil = $car->maintenanceSchedules()->create(['name' => 'Oil & filter', 'type' => 'oil_change', 'interval_km' => 15000, 'last_performed_mileage' => 134000, 'last_performed_at' => '2025-01-01']);
    MaintenanceTasks::ensureForDue($oil, $car, $users[0]);
    $taskId = $oil->fresh()->open_task_id;
    expect($taskId)->not->toBeNull();

    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];
    mcpTool($token, 'tasks_update_task', ['task_id' => $taskId, 'done' => true]);

    expect(VehicleMaintenanceHistory::withoutGlobalScopes()->where('schedule_id', $oil->id)->count())->toBe(1)
        ->and($oil->fresh()->open_task_id)->toBeNull();
});
