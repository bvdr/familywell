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

    [$err, $list] = mcpTool($token, 'calendar_list', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    expect($err)->toBeFalse()->and(collect($list['items'])->pluck('title')->all())->toBe(['Grecia']);
});

it('filters the list by person and rejects ranges over 400 days', function () {
    [, $users, $token] = calendarToken();
    mcpTool($token, 'calendar_create_event', ['title' => 'A', 'start_date' => '2026-07-01', 'end_date' => '2026-07-01', 'people' => [['type' => 'user', 'id' => $users[1]->id]]]);
    mcpTool($token, 'calendar_create_event', ['title' => 'B', 'start_date' => '2026-07-02', 'end_date' => '2026-07-02']);
    [, $list] = mcpTool($token, 'calendar_list', ['from' => '2026-07-01', 'to' => '2026-07-31', 'person' => "user:{$users[1]->id}"]);
    expect(collect($list['items'])->pluck('title')->all())->toBe(['A']);
    [$err] = mcpTool($token, 'calendar_list', ['from' => '2026-01-01', 'to' => '2027-06-01']);
    expect($err)->toBeTrue();
});

it('only includes other modules the token may read', function () {
    [$family, $users] = mcpFamily(['calendar', 'tasks', 'contacts', 'projects'], 1);
    CurrentFamily::set($family->id);
    Project::createFor($users[0], ['name' => 'Kitchen', 'target_start' => '2026-07-15']);
    $calOnly = mcpConnect($users[0], $family->id, ['calendar' => 'read'])['access_token'];
    $both = mcpConnect($users[0], $family->id, ['calendar' => 'read', 'projects' => 'read'])['access_token'];
    [, $a] = mcpTool($calOnly, 'calendar_list', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    [, $b] = mcpTool($both, 'calendar_list', ['from' => '2026-07-01', 'to' => '2026-07-31']);
    expect($a['items'])->toBe([])->and(collect($b['items'])->pluck('title')->all())->toBe(['Kitchen']);
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
    expect(mcpToolNames($token))->toContain('calendar_list')->not->toContain('calendar_create_event');
});

it('refuses a to-do assignee from another family without notifying them', function () {
    Notification::fake();
    [, $users, $token] = calendarToken();
    $stranger = User::factory()->create();
    [, $e] = mcpTool($token, 'calendar_create_event', ['title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25']);
    [$err] = mcpTool($token, 'calendar_add_todo', ['event_id' => $e['id'], 'title' => 'Pack', 'assigned_to' => $stranger->id]);
    expect($err)->toBeTrue()->and(Task::withoutGlobalScope('family')->where('title', 'Pack')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('returns a tool error, not an exception, for an unparseable from date', function () {
    [, , $token] = calendarToken();
    [$err, $msg] = mcpTool($token, 'calendar_list', ['from' => 'abc', 'to' => '2026-07-31']);
    expect($err)->toBeTrue()->and($msg)->toContain('from'); // the validation message, not a Carbon parse error
});
