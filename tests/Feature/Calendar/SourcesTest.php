<?php

// tests/Feature/Calendar/SourcesTest.php
use App\Support\Calendar\CalendarSources;
use App\Support\Modules\ModuleRegistry;
use Modules\Birthdays\BirthdayDate;
use Modules\Contacts\Person;
use Modules\Projects\Project;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;
use Modules\Vehicles\MaintenanceTasks;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleDocument;
use Modules\Vehicles\VehicleMaintenanceSchedule;

require_once __DIR__.'/helpers.php';

$kinds = fn (array $items) => collect($items)->map(fn ($i) => "{$i['source']}:{$i['kind']}:{$i['title']}:{$i['start']}")->all();

it('shows list tasks due in range, done ones too, with the assignee', function () use ($kinds) {
    [, $users] = calendarFamily();
    $list = TaskList::createFor($users[0], ['name' => 'Home']);
    Task::create(['task_list_id' => $list->id, 'title' => 'Pay rent', 'due_date' => '2026-07-05', 'assigned_to' => $users[1]->id, 'created_by' => $users[0]->id]);
    Task::create(['task_list_id' => $list->id, 'title' => 'Done', 'due_date' => '2026-07-06', 'done' => true, 'created_by' => $users[0]->id]);
    Task::create(['task_list_id' => $list->id, 'title' => 'Later', 'due_date' => '2026-09-01', 'created_by' => $users[0]->id]);

    $items = CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id);
    expect($kinds($items))->toBe(['tasks:task:Pay rent:2026-07-05', 'tasks:task:Done:2026-07-06'])
        ->and($items[0]['people'][0]['id'])->toBe($users[1]->id)->and($items[1]['done'])->toBeTrue();
});

it('repeats birthdays every year with the age when known', function () use ($kinds) {
    [, $users] = calendarFamily(['calendar', 'tasks', 'contacts', 'birthdays']);
    $ana = Person::create(['name' => 'Ana', 'created_by' => $users[0]->id]);
    BirthdayDate::create(['person_id' => $ana->id, 'month' => 7, 'day' => 20, 'birthdate' => '1986-07-20']);
    $leap = Person::create(['name' => 'Leo', 'created_by' => $users[0]->id]);
    BirthdayDate::create(['person_id' => $leap->id, 'month' => 2, 'day' => 29]);

    expect($kinds(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id)))->toBe(['birthdays:birthday:Ana turns 40:2026-07-20'])
        ->and(collect(CalendarSources::collect('2027-01-01', '2028-12-31', $users[0]->id))->where('kind', 'birthday')->pluck('start')->all())
        ->toBe(['2027-07-20', '2028-02-29', '2028-07-20']);
});

it('shows vehicle maintenance and document due dates', function () use ($kinds) {
    [, $users] = calendarFamily(['calendar', 'tasks', 'contacts', 'spending', 'vehicles']);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 50000, 'created_by' => $users[0]->id]);
    VehicleMaintenanceSchedule::create(['vehicle_id' => $car->id, 'name' => 'Oil change', 'type' => 'oil', 'due_on' => '2026-07-10', 'active' => true, 'created_by' => $users[0]->id]);
    VehicleDocument::create(['vehicle_id' => $car->id, 'type' => 'itp', 'title' => 'ITP', 'expires_on' => '2026-07-22']);

    expect($kinds(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id)))
        ->toBe(['vehicles:vehicle_maintenance:Dacia Duster: Oil change:2026-07-10', 'vehicles:vehicle_document:Dacia Duster: ITP:2026-07-22']);
});

it('shows a due schedule once when it already opened a maintenance task', function () use ($kinds) {
    [, $users] = calendarFamily(['calendar', 'tasks', 'contacts', 'spending', 'vehicles']);
    test()->actingAs($users[0]);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 50000, 'created_by' => $users[0]->id]);
    $s = VehicleMaintenanceSchedule::create(['vehicle_id' => $car->id, 'name' => 'Oil change', 'type' => 'oil', 'due_on' => '2026-07-10', 'active' => true, 'created_by' => $users[0]->id]);
    $task = MaintenanceTasks::ensureForDue($s, $car, $users[0]);

    expect($kinds(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id)))
        ->toBe(['tasks:task:Oil change — Dacia Duster:2026-07-10']);

    // Once the task is done (but the link not yet cleared), the schedule shows again as itself.
    $task->update(['done' => true]);
    expect($kinds(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id)))
        ->toContain('vehicles:vehicle_maintenance:Dacia Duster: Oil change:2026-07-10');
});

it('shows a project start as a one-day marker, not when archived', function () use ($kinds) {
    [, $users] = calendarFamily(['calendar', 'tasks', 'contacts', 'projects']);
    Project::createFor($users[0], ['name' => 'Kitchen', 'target_start' => '2026-07-15']);
    Project::createFor($users[0], ['name' => 'Old', 'target_start' => '2026-07-16'])->update(['archived_at' => now()]);
    expect($kinds(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id)))->toBe(['projects:project:Kitchen:2026-07-15']);
});

it('contributes nothing from a module the family has not enabled', function () {
    [$family, $users] = calendarFamily(['calendar', 'tasks', 'contacts', 'projects']);
    Project::createFor($users[0], ['name' => 'Kitchen', 'target_start' => '2026-07-15']);
    app(ModuleRegistry::class)->disable('projects', $family->id);
    expect(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id))->toBe([]);
});
