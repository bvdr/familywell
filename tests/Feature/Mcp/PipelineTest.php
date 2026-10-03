<?php

use App\Support\Modules\ModuleRegistry;

it('answers 401 with a WWW-Authenticate pointer when there is no token', function () {
    mcpKeys();
    $res = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $res->assertUnauthorized();
    expect($res->headers->get('WWW-Authenticate'))->toContain('resource_metadata');
});

it('lists only the tools the scopes and enabled modules allow', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $read = mcpConnect($users[0], $family->id, ['tasks' => 'read'])['access_token'];
    $write = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];
    $none = mcpConnect($users[0], $family->id)['access_token'];

    expect(mcpToolNames($none))->toBe(['get_family', 'get_today'])
        ->and(mcpToolNames($read))->toBe(['get_family', 'get_today', 'tasks_get_list', 'tasks_list_lists', 'tasks_search'])
        ->and(mcpToolNames($write))->toContain('tasks_create_task', 'tasks_delete_list', 'tasks_restore', 'tasks_search');

    // A read-only token cannot call a write tool: it is not listed, so it does not exist for this token.
    [$isError, $msg] = mcpTool($read, 'tasks_create_list', ['name' => 'Nope']);
    expect($isError)->toBeTrue()->and($msg)->toContain('not found');

    // Disabling the module after consent hides its tools.
    app(ModuleRegistry::class)->disable('tasks', $family->id);
    expect(mcpToolNames($write))->toBe(['get_family', 'get_today']);
});

it('refuses a token once the person has left the family', function () {
    [$family, $users] = mcpFamily(['tasks'], 2);
    $token = mcpConnect($users[1], $family->id, ['tasks' => 'read'])['access_token'];
    mcpCall($token, 'tools/list')->assertOk();

    $family->members()->detach($users[1]->id);
    mcpCall($token, 'tools/list')->assertForbidden();
});

it('tells the assistant who it acts for and what it may do', function () {
    [$family, $users] = mcpFamily(['tasks', 'notes'], 2);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];

    [, $info] = mcpTool($token, 'get_family');
    expect($info['family']['id'])->toBe($family->id)
        ->and(collect($info['members'])->pluck('id')->sort()->values()->all())->toBe($users->pluck('id')->sort()->values()->all())
        ->and($info['permissions'])->toBe(['tasks' => 'write']);
});
