<?php

namespace App\Mcp;

use App\Support\Analytics;
use Generator;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

// tools/call, plus an `assistant_tool` analytics event naming the tool (never its arguments or result).
// Hooked here, the one place every tool call passes through (FamilywellServer::boot swaps it in), instead
// of in 100+ tool classes. parent::handle() throws for an unknown/unlisted tool, so only real calls count.
class TrackedCallTool extends CallTool
{
    /**
     * Names the tools had before the 2026-10-29 rename for the ChatGPT review (fdb2335d). The approved ChatGPT app
     * (1.0.0) was reviewed with these: its skills and the tool list ChatGPT keeps from the review still call them,
     * so dropping them broke the live app ("Tool [calendar_list] not found"). Accepted on tools/call only, never
     * listed, so tools/list stays one clean name per tool.
     * ponytail: delete once every approved app version (ChatGPT, Claude directory) uses the new names.
     */
    public const OLD_NAMES = [
        'birthdays_list_deleted' => 'birthdays_list_trash',
        'calendar_list' => 'calendar_list_entries',
        'calendar_list_deleted' => 'calendar_list_trash',
        'contacts_list_deleted' => 'contacts_list_trash',
        'inventory_list_deleted' => 'inventory_list_trash',
        'inventory_needs_attention' => 'inventory_list_needs_attention',
        'notes_list_deleted' => 'notes_list_trash',
        'projects_link' => 'projects_link_item',
        'projects_list_deleted' => 'projects_list_trash',
        'projects_unlink' => 'projects_unlink_item',
        'shopping_list_deleted' => 'shopping_list_trash',
        'shopping_list_lists' => 'shopping_list_shopping_lists',
        'spending_list_deleted' => 'spending_list_trash',
        'spending_summary' => 'spending_get_summary',
        'tasks_list_deleted' => 'tasks_list_trash',
        'tasks_list_lists' => 'tasks_list_todo_lists',
        'vehicles_delete' => 'vehicles_delete_vehicle',
        'vehicles_list_deleted' => 'vehicles_list_trash',
        'vehicles_history' => 'vehicles_get_history',
    ];

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        if (is_string($request->params['name'] ?? null) && isset(self::OLD_NAMES[$request->params['name']])) {
            $request->params['name'] = self::OLD_NAMES[$request->params['name']];
        }
        $response = parent::handle($request, $context);
        Analytics::track(auth()->user(), 'assistant_tool', ['tool' => (string) $request->get('name')], '/mcp');

        return $response;
    }
}
