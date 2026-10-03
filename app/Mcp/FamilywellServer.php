<?php

namespace App\Mcp;

use App\Mcp\Apps\Widget;
use App\Support\Mcp\McpTools;
use Laravel\Mcp\Server;

// The Familywell MCP server (POST /mcp). Serves every tool any module registered with McpTools; each
// tool's shouldRegister() hides it unless the token's scopes and the family's enabled modules allow it.
class FamilywellServer extends Server
{
    protected string $name = 'Familywell';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Familywell is a family organiser: shared to-do lists, shopping lists, expenses and notes.
        This connection acts for one signed-in person inside ONE family, chosen when they connected.
        Everything you read or change belongs to that family and respects that person's sharing rules.
        Call get_family first to see the family, its members, and what this connection may read or change.
        Deletes are reversible for 30 days (see the *_list_deleted and *_restore tools).
        Every entity includes a `url` you can give the person to open it in Familywell.
        MARKDOWN;

    // tools/list pages at 15 by default; serve the whole (scope-filtered) set in one page.
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $this->tools = McpTools::all();
        // The MCP Apps widgets tools point at. Static HTML with no family data in it (the data arrives as the
        // tool result), so they are always listed; reading one needs only a valid token.
        $this->resources = array_map(fn (string $w) => new Widget($w), array_keys(Widget::WIDGETS));
    }
}
