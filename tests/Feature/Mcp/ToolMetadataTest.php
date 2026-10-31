<?php

// The ChatGPT app review (2026-10) rejected FamilyWell for "tool naming and description quality": names must
// be clear, unique and reflect what the tool does; descriptions must say WHEN to use the tool, with no
// comparative, biased or preferential language (developers.openai.com/apps-sdk/app-submission-guidelines).
// This walks the full tool list (every MCP module on, write access) so a new tool can't regress any of it.

const MCP_MODULES = ['birthdays', 'calendar', 'contacts', 'inventory', 'notes', 'projects', 'recipes', 'shopping', 'spending', 'tasks', 'vehicles', 'important_todos', 'recurring_todos'];

function everyTool(): array
{
    [$family, $users] = mcpFamily(MCP_MODULES);
    $access = array_fill_keys(array_diff(MCP_MODULES, ['important_todos', 'recurring_todos']), 'write');
    $token = mcpConnect($users[0], $family->id, $access)['access_token'];

    // tools/list pages (FamilywellServer::$defaultPaginationLength); follow the cursor to get them all.
    $tools = [];
    $params = [];
    do {
        $page = mcpCall($token, 'tools/list', $params)->assertOk()->json('result');
        $tools = [...$tools, ...$page['tools']];
        $params = ['cursor' => $page['nextCursor'] ?? null];
    } while ($params['cursor']);

    return $tools;
}

/** "name.sub" => description for every property, one level into objects and arrays of objects. */
function propertyDescriptions(array $properties, string $prefix = ''): array
{
    $out = [];
    foreach ($properties as $key => $prop) {
        $out[$prefix.$key] = trim((string) ($prop['description'] ?? ''));
        $nested = $prop['properties'] ?? $prop['items']['properties'] ?? null;
        // The `file` object's inner fields are OpenAI's fixed fileParams shape, filled in by the host itself.
        if (is_array($nested) && $key !== 'file') {
            $out += propertyDescriptions($nested, $prefix.$key.'.');
        }
    }

    return $out;
}

it('gives every tool a unique snake_case verb name and a unique title', function () {
    $tools = collect(everyTool());

    expect($tools->count())->toBeGreaterThan(90)
        ->and($tools->pluck('name')->duplicates()->all())->toBe([])
        ->and($tools->map(fn ($t) => mb_strtolower($t['annotations']['title']))->duplicates()->all())->toBe([]);
    foreach ($tools as $t) {
        expect($t['name'])->toMatch('/^[a-z]+(_[a-z]+)+$/');
    }
});

it('describes when to use every tool, without platform names or steering words', function () {
    foreach (everyTool() as $t) {
        $d = $t['description'];
        expect($d)->toStartWith('Use this when', $t['name'])
            // Naming an AI app (or another service) in a description reads as comparative to the reviewer.
            ->and($d)->not->toMatch('/chatgpt|claude|openai|anthropic|gemini|copilot/i', $t['name'])
            // Steering the model ("prefer X", "call this first", "best") is the "preferential language" ban.
            ->and($d)->not->toMatch('/\b(prefer|preferred|best|better|recommended|official|always call|call this first)\b/i', $t['name']);
    }
});

it('describes every input parameter, with no platform names', function () {
    foreach (everyTool() as $t) {
        foreach (propertyDescriptions($t['inputSchema']['properties'] ?? []) as $param => $desc) {
            expect($desc)->not->toBe('', "{$t['name']}.{$param}")
                ->and($desc)->not->toMatch('/chatgpt|claude|openai|anthropic|gemini/i', "{$t['name']}.{$param}");
        }
    }
});
