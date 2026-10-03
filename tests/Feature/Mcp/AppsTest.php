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
            // Everything inlined: no external domains (only image widgets may load from this app itself).
            ->and(in_array($w, Widget::IMAGES, true) || ! isset($content['_meta']['ui']['csp']))->toBeTrue();
    }
});

it('declares full screen to the host only for widgets that offer it', function () {
    // Widget::sdk() patches this literal in laravel/mcp's SDK; if an update renames it, fail here, not silently.
    expect(app('mcp.sdk'))->toContain('appCapabilities:{}');

    [$family, $users] = mcpFamily();
    $token = mcpConnect($users[0], $family->id)['access_token'];
    $html = fn (string $w) => mcpCall($token, 'resources/read', ['uri' => Widget::uriFor($w)])->json('result.contents.0.text');

    expect($html('shopping'))->toContain('appCapabilities:{"availableDisplayModes":["inline","fullscreen"]}')
        ->and($html('tasks'))->toContain('appCapabilities:{}')
        ->and($html('tasks'))->not->toContain('availableDisplayModes":[');
});

it('paints widgets in the person\'s Familywell skin', function () {
    [$family, $users] = mcpFamily([], 2);
    $users[1]->update(['theme' => 'default']); // personal skin: Warm
    $html = function ($user) use ($family) {
        $token = mcpConnect($user, $family->id)['access_token'];

        return mcpCall($token, 'resources/read', ['uri' => Widget::uriFor('tasks')])->json('result.contents.0.text');
    };
    $warm = json_decode(file_get_contents(resource_path('themes/default/theme.json')), true)['tokens']['background'];
    $mono = json_decode(file_get_contents(resource_path('themes/minimal-mono/theme.json')), true)['tokens']['background'];

    expect($html($users[1]))->toContain("--background:{$warm}")
        ->and($html($users[0]))->toContain("--background:{$mono}"); // no personal or family skin -> built-in default

    $family->update(['theme' => 'default']); // family default applies to members without their own
    expect($html($users[0]))->toContain("--background:{$warm}");
});

it('gives the connector the rounded app icon', function () {
    [$family, $users] = mcpFamily();
    $token = mcpConnect($users[0], $family->id)['access_token'];
    $init = mcpCall($token, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']]);

    expect($init->json('result.serverInfo.icons.0.src'))->toEndWith('/icons/icon-rounded-512.png')
        ->and(file_exists(public_path('icons/icon-rounded-512.png')))->toBeTrue();
});

it('says what each get_today item is so the widget can tick it off', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];
    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Home']);
    [, $task] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Bins out', 'due_date' => now()->toDateString()]);

    [, $today] = mcpTool($token, 'get_today');
    expect($today['due'][0])->toMatchArray(['title' => 'Bins out', 'kind' => 'task', 'id' => $task['id']]);
});
