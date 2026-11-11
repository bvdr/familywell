<?php

use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Notification;
use Modules\Calendar\CalendarEvent;
use Modules\Projects\Project;
use Modules\Tasks\Task;

function calendarToken(array $access = ['calendar' => 'write'], array $modules = ['calendar', 'tasks', 'contacts']): array
{
    [$family, $users] = mcpFamily($modules, 2);
    $token = mcpConnect($users[0], $family->id, $access)['access_token'];

    return [$family, $users, $token];
}

it('creates an event with people and lists it in a range', function () {
    [, $users, $token] = calendarToken();
    [$err, $e] = mcpTool($token, 'calendar_create_event', [
        'title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25', 'people' => [['type' => 'user', 'id' => $users[1]->id]],
    ]);
    expect($err)->toBeFalse()->and($e)->toMatchArray(['title' => 'Grecia', 'start_date' => '2026-07-17'])
        ->and($e['people'][0]['id'])->toBe($users[1]->id)->and($e['url'])->toStartWith('http');

    [$err, $list] = mcpTool($token, 'calendar_list_entries', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    expect($err)->toBeFalse()->and(collect($list['items'])->pluck('title')->all())->toBe(['Grecia']);
});

it('filters the list by person and rejects ranges over 400 days', function () {
    [, $users, $token] = calendarToken();
    mcpTool($token, 'calendar_create_event', ['title' => 'A', 'start_date' => '2026-07-01', 'end_date' => '2026-07-01', 'people' => [['type' => 'user', 'id' => $users[1]->id]]]);
    mcpTool($token, 'calendar_create_event', ['title' => 'B', 'start_date' => '2026-07-02', 'end_date' => '2026-07-02']);
    [, $list] = mcpTool($token, 'calendar_list_entries', ['from' => '2026-07-01', 'to' => '2026-07-31', 'person' => "user:{$users[1]->id}"]);
    expect(collect($list['items'])->pluck('title')->all())->toBe(['A']);
    [$err] = mcpTool($token, 'calendar_list_entries', ['from' => '2026-01-01', 'to' => '2027-06-01']);
    expect($err)->toBeTrue();
});

it('only includes other modules the token may read', function () {
    [$family, $users] = mcpFamily(['calendar', 'tasks', 'contacts', 'projects'], 1);
    CurrentFamily::set($family->id);
    Project::createFor($users[0], ['name' => 'Kitchen', 'target_start' => '2026-07-15']);
    $calOnly = mcpConnect($users[0], $family->id, ['calendar' => 'read'])['access_token'];
    $both = mcpConnect($users[0], $family->id, ['calendar' => 'read', 'projects' => 'read'])['access_token'];
    [, $a] = mcpTool($calOnly, 'calendar_list_entries', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    [, $b] = mcpTool($both, 'calendar_list_entries', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    expect($a['items'])->toBe([])->and(collect($b['items'])->pluck('title')->all())->toBe(['Kitchen']);
});

it('narrows the list to the requested sources, never past what the token may read', function () {
    [$family, $users] = mcpFamily(['calendar', 'tasks', 'contacts', 'projects'], 1);
    CurrentFamily::set($family->id);
    Project::createFor($users[0], ['name' => 'Kitchen', 'target_start' => '2026-07-15']);
    $both = mcpConnect($users[0], $family->id, ['calendar' => 'write', 'projects' => 'read'])['access_token'];
    $calOnly = mcpConnect($users[0], $family->id, ['calendar' => 'read'])['access_token'];
    mcpTool($both, 'calendar_create_event', ['title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17']);
    $range = ['from' => '2026-07-01', 'to' => '2026-07-31'];

    $titles = fn ($token, $sources) => collect(mcpTool($token, 'calendar_list_entries', $range + ['sources' => $sources])[1]['items'])->pluck('title')->all();
    expect($titles($both, ['projects', 'nope']))->toBe(['Kitchen'])
        ->and($titles($both, ['calendar']))->toBe(['Grecia'])
        ->and($titles($calOnly, ['projects']))->toBe([]);
});

it('updates, adds a to-do, and deletes', function () {
    [$family, $users, $token] = calendarToken(modules: ['calendar', 'tasks', 'contacts', 'projects']);
    CurrentFamily::set($family->id);
    $project = Project::createFor($users[0], ['name' => 'Kitchen', 'target_start' => '2026-07-15']);
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25', 'project_id' => $project->id]);
    [$err, $u] = mcpTool($token, 'calendar_update_event', ['id' => $e['id'], 'end_date' => '2026-07-27', 'repeat' => 'yearly']);
    expect($err)->toBeFalse()->and($u)->toMatchArray(['end_date' => '2026-07-27', 'repeat' => 'yearly'])
        ->and($u['project']['url'])->toStartWith('http');

    [$err, $t] = mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Pack']);
    expect($err)->toBeFalse()->and(Task::find($t['id'])->task_list_id)->toBeNull()
        ->and($t['url'])->toStartWith('http');
    [, $g] = mcpTool($token, 'calendar_get_event', ['id' => $e['id']]);
    expect(collect($g['todos'])->pluck('title')->all())->toBe(['Pack'])
        ->and($g['todos'][0]['url'])->toStartWith('http')
        ->and($g['project']['url'])->toStartWith('http');

    [$err] = mcpTool($token, 'calendar_delete_event', ['id' => $e['id']]);
    expect($err)->toBeFalse()->and(CalendarEvent::withoutGlobalScope('family')->withTrashed()->find($e['id'])->trashed())->toBeTrue();
});

it('hides write tools from a read-only token', function () {
    [, , $token] = calendarToken(['calendar' => 'read']);
    expect(mcpToolNames($token))->toContain('calendar_list_entries')->not->toContain('calendar_create_event');
});

it('refuses a to-do assignee from another family without notifying them', function () {
    [, $users, $token] = calendarToken();
    $stranger = User::factory()->create();
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25']);
    // Faked after the setup: creating the event itself pushes the family ("Calendar changes").
    Notification::fake();
    [$err] = mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Pack', 'assigned_to' => $stranger->id]);
    expect($err)->toBeTrue()->and(Task::withoutGlobalScope('family')->where('title', 'Pack')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('returns a tool error, not an exception, for an unparseable from date', function () {
    [, , $token] = calendarToken();
    [$err, $msg] = mcpTool($token, 'calendar_list_entries', ['from' => 'abc', 'to' => '2026-07-31']);
    expect($err)->toBeTrue()->and($msg)->toContain('from'); // the validation message, not a Carbon parse error
});

it('ticks, edits and deletes an event to-do and its sub-task', function () {
    [, $users, $token] = calendarToken();
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25']);
    [, $pack] = mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Pack']);
    [, $sub] = mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Passports', 'parent_id' => $pack['id']]);

    [$err, $t] = mcpTool($token, 'calendar_update_todo', ['task_id' => $sub['id'], 'done' => true, 'due_date' => '2026-07-10', 'assigned_to' => $users[1]->id]);
    expect($err)->toBeFalse()->and($t)->toMatchArray(['done' => true, 'due_date' => '2026-07-10', 'event_id' => $e['id']])
        ->and($t['assigned_to']['id'])->toBe($users[1]->id);
    [, $g] = mcpTool($token, 'calendar_get_event', ['id' => $e['id']]);
    // Sub-tasks nest under their parent only, never as a second top-level to-do.
    expect($g['todos'])->toHaveCount(1)
        ->and($g['todos'][0]['subtasks'][0])->toMatchArray(['id' => $sub['id'], 'title' => 'Passports', 'done' => true])
        ->and($g['todos'][0]['subtasks'][0]['url'])->toEndWith("/m/calendar/events/{$e['id']}");

    [$err] = mcpTool($token, 'calendar_delete_todo', ['task_id' => $pack['id']]);
    expect($err)->toBeFalse();
    $this->assertSoftDeleted('tasks_tasks', ['id' => $pack['id']]);
    $this->assertSoftDeleted('tasks_tasks', ['id' => $sub['id']]);
    expect(mcpTool($token, 'calendar_get_event', ['id' => $e['id']])[1]['todos'])->toBe([]);

    // It sits in the trash on its own (the event is live) and restores back onto the event with its sub-task.
    [, $trash] = mcpTool($token, 'calendar_list_trash');
    expect(collect($trash['deleted'])->map(fn ($r) => [$r['type'], $r['title'], $r['where']])->sort()->values()->all())
        ->toBe([['todo', 'Pack', 'Grecia'], ['todo', 'Passports', 'Grecia']]);
    [$err] = mcpTool($token, 'calendar_restore', ['type' => 'todo', 'id' => $pack['id']]);
    expect($err)->toBeFalse();
    [, $g] = mcpTool($token, 'calendar_get_event', ['id' => $e['id']]);
    expect($g['todos'][0]['id'])->toBe($pack['id'])->and($g['todos'][0]['subtasks'][0]['id'])->toBe($sub['id']);

    // Purged for good after the window: the task and its event link both go.
    mcpTool($token, 'calendar_delete_todo', ['task_id' => $pack['id']]);
    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertExitCode(0);
    $this->assertDatabaseMissing('tasks_tasks', ['id' => $pack['id']]);
    expect(CalendarEvent::withoutGlobalScope('family')->find($e['id'])->taskIds())->toBe([]);
});

it('treats an event to-do as not found for someone who cannot edit the event', function () {
    [$family, $users, $token] = calendarToken();
    CurrentFamily::set($family->id);
    $e = CalendarEvent::createFor($users[1], CalendarEvent::validated(['title' => 'Secret', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17']));
    $e->setVisibility('private');
    $t = $e->addTask($users[1], ['title' => 'Gift']);

    [$err] = mcpTool($token, 'calendar_update_todo', ['task_id' => $t->id, 'done' => true]);
    [$err2] = mcpTool($token, 'calendar_delete_todo', ['task_id' => $t->id]);
    expect($err)->toBeTrue()->and($err2)->toBeTrue()
        ->and(Task::withoutGlobalScope('family')->find($t->id)->done)->toBeFalse();
});

it('lists deleted events and restores one with its to-dos, but not an event this person cannot see', function () {
    [$family, $users, $token] = calendarToken();
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17']);
    [, $todo] = mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Pack']);
    mcpTool($token, 'calendar_delete_event', ['id' => $e['id']]);

    CurrentFamily::set($family->id);
    $secret = CalendarEvent::createFor($users[1], CalendarEvent::validated(['title' => 'Secret', 'start_date' => '2026-07-18', 'end_date' => '2026-07-18']));
    $secret->setVisibility('private');
    $secret->delete();

    [$err, $trash] = mcpTool($token, 'calendar_list_trash');
    expect($err)->toBeFalse()->and(collect($trash['deleted'])->pluck('title')->all())->toBe(['Grecia']);

    [$err, $r] = mcpTool($token, 'calendar_restore', ['id' => $e['id']]);
    expect($err)->toBeFalse()->and($r)->toMatchArray(['restored' => true, 'id' => $e['id']])
        ->and(mcpTool($token, 'calendar_get_event', ['id' => $e['id']])[1]['todos'][0]['id'])->toBe($todo['id']);

    [$err] = mcpTool($token, 'calendar_restore', ['id' => $secret->id]);
    expect($err)->toBeTrue()->and(CalendarEvent::withTrashed()->withoutGlobalScope('family')->find($secret->id)->trashed())->toBeTrue();
});

it('writes server strings in the person\'s language', function () {
    [, $users, $token] = calendarToken(['calendar' => 'read', 'birthdays' => 'write'], ['calendar', 'contacts', 'birthdays']);
    $users[0]->update(['locale' => 'ro']);
    mcpTool($token, 'birthdays_add', ['name' => 'Ana', 'birth_month' => 7, 'birth_day' => 17]);

    [, $list] = mcpTool($token, 'calendar_list_entries', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    expect(collect($list['items'])->pluck('title')->all())->toBe(['Ziua lui Ana']);
});

it('marks days, writes day notes and shows them with the day\'s to-dos on the event', function () {
    [, , $token] = calendarToken();
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Lake trip', 'start_date' => '2026-07-17', 'end_date' => '2026-07-21', 'repeat' => 'yearly']);

    [$err, $m] = mcpTool($token, 'calendar_add_mark', ['event_id' => $e['id'], 'name' => 'Boat day', 'icon' => 'sailboat', 'dates' => ['2026-07-19', '2026-07-18']]);
    expect($err)->toBeFalse()->and($m)->toMatchArray(['name' => 'Boat day', 'days' => [1, 2], 'dates' => ['2026-07-18', '2026-07-19']]);
    [$err] = mcpTool($token, 'calendar_add_mark', ['event_id' => $e['id'], 'name' => 'Nope', 'dates' => ['2026-07-25']]);
    expect($err)->toBeTrue();

    [$err] = mcpTool($token, 'calendar_set_day_note', ['event_id' => $e['id'], 'date' => '2026-07-18', 'body' => 'Ferry at 9']);
    expect($err)->toBeFalse();
    mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Buy fish', 'due_date' => '2026-07-19']);

    [, $g] = mcpTool($token, 'calendar_get_event', ['id' => $e['id']]);
    expect($g['days'])->toMatchArray(['start' => '2026-07-17', 'end' => '2026-07-21', 'notes' => [['date' => '2026-07-18', 'body' => 'Ferry at 9']]])
        ->and($g['days']['marks'][0]['dates'])->toBe(['2026-07-18', '2026-07-19'])
        ->and($g['todos'][0]['due_date'])->toBe('2026-07-19');

    // Next year's occurrence: the mark carries over by day index, the note (a real date) does not.
    [, $next] = mcpTool($token, 'calendar_get_event', ['id' => $e['id'], 'day' => '2027-07-18']);
    expect($next['days']['start'])->toBe('2027-07-17')->and($next['days']['marks'][0]['dates'])->toBe(['2027-07-18', '2027-07-19'])
        ->and($next['days']['notes'])->toBe([]);

    [$err, $u] = mcpTool($token, 'calendar_update_mark', ['mark_id' => $m['id'], 'name' => 'Boat', 'dates' => ['2026-07-21']]);
    expect($err)->toBeFalse()->and($u)->toMatchArray(['name' => 'Boat', 'icon' => 'sailboat', 'days' => [4]]);

    // One-day events have no days to mark.
    [, $one] = mcpTool($token, 'calendar_create_event', ['title' => 'Dentist', 'start_date' => '2026-07-01', 'end_date' => '2026-07-01']);
    expect(mcpTool($token, 'calendar_add_mark', ['event_id' => $one['id'], 'name' => 'X', 'dates' => ['2026-07-01']])[0])->toBeTrue()
        ->and(mcpTool($token, 'calendar_get_event', ['id' => $one['id']])[1]['days'])->toBeNull();
});

it('trashes a deleted mark and day note, restores them, and revives a note written on the same day again', function () {
    [, , $token] = calendarToken();
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Lent', 'start_date' => '2026-02-18', 'end_date' => '2026-04-04']);
    [, $m] = mcpTool($token, 'calendar_add_mark', ['event_id' => $e['id'], 'name' => 'Fish allowed', 'dates' => ['2026-02-21']]);
    mcpTool($token, 'calendar_set_day_note', ['event_id' => $e['id'], 'date' => '2026-02-20', 'body' => 'Soup']);

    expect(mcpTool($token, 'calendar_delete_mark', ['mark_id' => $m['id']])[0])->toBeFalse()
        ->and(mcpTool($token, 'calendar_delete_day_note', ['event_id' => $e['id'], 'date' => '2026-02-20'])[0])->toBeFalse();
    [, $g] = mcpTool($token, 'calendar_get_event', ['id' => $e['id']]);
    expect($g['days']['marks'])->toBe([])->and($g['days']['notes'])->toBe([]);

    [, $trash] = mcpTool($token, 'calendar_list_trash');
    $rows = collect($trash['deleted'])->keyBy('type');
    expect($rows['mark'])->toMatchArray(['title' => 'Fish allowed', 'where' => 'Lent'])->and($rows['day_note']['title'])->toBe('Soup');

    expect(mcpTool($token, 'calendar_restore', ['type' => 'mark', 'id' => $m['id']])[0])->toBeFalse();
    // Writing the day again revives the trashed row (the (event, date) slot is unique, trashed rows included).
    mcpTool($token, 'calendar_set_day_note', ['event_id' => $e['id'], 'date' => '2026-02-20', 'body' => 'Bread']);
    [, $g] = mcpTool($token, 'calendar_get_event', ['id' => $e['id']]);
    expect($g['days']['marks'][0]['name'])->toBe('Fish allowed')->and($g['days']['notes'])->toBe([['date' => '2026-02-20', 'body' => 'Bread']]);
});

it('does not let someone who cannot edit the event mark its days', function () {
    [$family, $users, $token] = calendarToken();
    CurrentFamily::set($family->id);
    $e = CalendarEvent::createFor($users[1], CalendarEvent::validated(['title' => 'Secret', 'start_date' => '2026-07-17', 'end_date' => '2026-07-20']));
    $e->setVisibility('private');
    $mark = $e->marks()->create(['name' => 'Gift day', 'days' => [1]]);

    expect(mcpTool($token, 'calendar_add_mark', ['event_id' => $e->id, 'name' => 'X', 'dates' => ['2026-07-18']])[0])->toBeTrue()
        ->and(mcpTool($token, 'calendar_delete_mark', ['mark_id' => $mark->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'calendar_set_day_note', ['event_id' => $e->id, 'date' => '2026-07-18', 'body' => 'x'])[0])->toBeTrue()
        ->and($mark->fresh()->trashed())->toBeFalse();
});

it('lists, adds and (admins only) renames categories, and gives an event its category colour', function () {
    [$family, $users, $token] = calendarToken();
    [$err, $c] = mcpTool($token, 'calendar_create_category', ['name' => 'Sports', 'color' => 'teal']);
    expect($err)->toBeFalse()->and($c)->toMatchArray(['slug' => 'sports', 'name' => 'Sports', 'color' => 'teal', 'builtin' => false]);
    expect(mcpTool($token, 'calendar_create_category', ['name' => 'sports', 'color' => 'teal'])[0])->toBeTrue(); // name taken

    [, $list] = mcpTool($token, 'calendar_list_categories');
    expect(collect($list['categories'])->pluck('slug')->all())->toBe(['trips', 'faith', 'family', 'school', 'work', 'sports']);

    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Match', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'category' => 'sports']);
    expect($e)->toMatchArray(['category' => 'sports', 'color' => 'teal']);

    [$err, $r] = mcpTool($token, 'calendar_update_category', ['id' => $c['id'], 'name' => 'Sport']);
    expect($err)->toBeFalse()->and($r)->toMatchArray(['slug' => 'sports', 'name' => 'Sport', 'color' => 'teal']);

    // A member who isn't an admin can add but not rename.
    $member = mcpConnect($users[1], $family->id, ['calendar' => 'write'])['access_token'];
    expect(mcpTool($member, 'calendar_update_category', ['id' => $c['id'], 'name' => 'Nope'])[0])->toBeTrue();
});
