<?php

use App\Mcp\Apps\Widget;

// MCP Apps: read tools point at a ui:// widget, the widgets are served as text/html;profile=mcp-app, and
// get_today carries what a widget needs to tick an item off.

it('links read tools to their widget and leaves write tools text-only', function () {
    [$family, $users] = mcpFamily(['tasks', 'shopping', 'notes']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write', 'shopping' => 'read', 'notes' => 'read'])['access_token'];

    $tools = collect(mcpCall($token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');
    expect($tools['tasks_get_list']['_meta']['ui']['resourceUri'])->toBe('ui://familywell/tasks')
        ->and($tools['tasks_search']['_meta']['ui']['resourceUri'])->toBe('ui://familywell/tasks')
        ->and($tools['shopping_get_list']['_meta']['ui']['resourceUri'])->toBe('ui://familywell/shopping')
        ->and($tools['notes_get']['_meta']['ui']['resourceUri'])->toBe('ui://familywell/note')
        ->and($tools['get_today']['_meta']['ui']['resourceUri'])->toBe('ui://familywell/today')
        ->and($tools['tasks_update_task'])->not->toHaveKey('_meta');
});

it('lists and serves every widget as an MCP App page', function () {
    [$family, $users] = mcpFamily();
    $token = mcpConnect($users[0], $family->id)['access_token'];

    // The server advertises the MCP Apps extension because it has app resources.
    $init = mcpCall($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])->assertOk();
    expect($init->json('result.capabilities.extensions'))->toHaveKey('io.modelcontextprotocol/ui');

    $listed = collect(mcpCall($token, 'resources/list')->assertOk()->json('result.resources'))->keyBy('uri');
    foreach (array_keys(Widget::WIDGETS) as $w) {
        $uri = Widget::uriFor($w);
        expect($listed[$uri]['mimeType'])->toBe('text/html;profile=mcp-app');

        $content = mcpCall($token, 'resources/read', ['uri' => $uri])->assertOk()->json('result.contents.0');
        expect($content['mimeType'])->toBe('text/html;profile=mcp-app')
            ->and($content['text'])->toContain('createMcpApp')  // laravel/mcp's host bridge
            ->and($content['text'])->toContain('const FW =')    // our shared runtime
            ->and($content['text'])->toContain('FW.start(')     // the widget itself
            ->and($content['_meta']['ui'])->not->toHaveKey('csp'); // everything inlined: no external domains
    }
});

it('says what each get_today item is so the widget can tick it off', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];
    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Home']);
    [, $task] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Bins out', 'due_date' => now()->toDateString()]);

    [, $today] = mcpTool($token, 'get_today');
    expect($today['due'][0])->toMatchArray(['title' => 'Bins out', 'kind' => 'task', 'id' => $task['id']]);
});
