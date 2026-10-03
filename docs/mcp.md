# MCP server (AI assistants)

Familywell exposes an [MCP](https://modelcontextprotocol.io) server so AI assistants (Claude, ChatGPT,
Cursor, VS Code and any other MCP client) can read and change a family's data on a person's behalf.

- **Address:** `https://mcp.familywell.co/mcp` (self-hosted: `https://<your host>/mcp`, or set `MCP_SERVER_URL`).
- **Auth:** OAuth 2.1 authorization code + PKCE. No API keys or personal tokens.
- **Scope of a connection:** one family, and per module *No access*, *Read* or *Read & write*.

## Connecting an assistant

1. Add a remote MCP server in the assistant with the address above.
   Claude Code: `claude mcp add --transport http familywell https://mcp.familywell.co/mcp`.
2. The assistant opens a browser window. Sign in to Familywell if needed.
3. The consent screen asks for **one family** and what the app may do in each module. Everything starts
   at *No access*. The app always sees the family's name and members (`get_family`).
4. **Allow** sends you back to the assistant, now connected.

Every connection shows the consent screen, even for an app you connected before, so each one carries
its own family and permissions.

**Disconnecting:** Settings → Connected apps → *Disconnect*. Access stops immediately. Leaving a family
also cuts off every connection made for it. A connection unused for 30 days expires; reconnect it.

**Deletes are reversible.** Anything an assistant (or you, in the app) deletes goes to a 30-day trash.
Settings → Recently deleted and the `*_list_deleted` / `*_restore` tools bring it back (both read the same
per-module `TrashSource`); `trash:purge` removes it for good afterwards.

## Tools

| Module | Read | Write |
|---|---|---|
| core (always) | `get_family`, `get_today` | – |
| Tasks | `tasks_list_lists`, `tasks_get_list`, `tasks_search` | `tasks_create_list`, `tasks_update_list`, `tasks_create_task`, `tasks_update_task`, `tasks_delete_list`, `tasks_delete_task`, `tasks_list_deleted`, `tasks_restore` |
| Shopping | `shopping_list_lists`, `shopping_get_list`, `shopping_search` | `shopping_create_list`, `shopping_update_list`, `shopping_add_item`, `shopping_update_item`, `shopping_delete_list`, `shopping_delete_item`, `shopping_list_deleted`, `shopping_restore` |
| Spending | `spending_list_expenses`, `spending_get_expense`, `spending_summary`, `spending_list_categories` | `spending_add_expense`, `spending_update_expense`, `spending_delete_expense`, `spending_list_deleted`, `spending_restore` |
| Notes | `notes_list`, `notes_get`, `notes_search` | `notes_create`, `notes_update`, `notes_delete`, `notes_list_deleted`, `notes_restore` |

Tools act **as the person who connected**: the same sharing rules as the web app apply (a private list
of another member is invisible), the same validation, and the same notifications to the family.

## How it works

```
assistant ──POST /mcp (Bearer)──▶ auth:api (Passport) ▶ McpFamilyContext ▶ FamilywellServer ▶ tools
discovery   /.well-known/oauth-protected-resource[/mcp]   /.well-known/oauth-authorization-server
OAuth       POST /oauth/register (RFC 7591)   GET|POST|DELETE /oauth/authorize (consent)   POST /oauth/token
```

- `routes/mcp.php` registers everything above, outside the `web` group. Passport's own routes are off
  (`Passport::ignoreRoutes()`); only what this flow needs is registered.
- **Scopes:** `mcp:use`, `family:<id>` (exactly one), `<module>:read`, `<module>:write`. The consent
  form (`App\Http\Controllers\OAuth\ApproveController`) decides them; `App\Support\Mcp\ScopeRepository`
  accepts the dynamic family scope and ignores what the client asked for. Passport copies scopes onto
  refreshed tokens, so the family binding survives refresh.
- **`McpFamilyContext`** reads `family:<id>` from the token, re-checks membership on every request, and
  sets `CurrentFamily`, so module queries are scoped exactly as in the web app.
- **One permission gate:** `App\Mcp\Tool::shouldRegister()` lists a tool only if the module is enabled
  for the family and the token has `<module>:<access>`. An unlisted tool cannot be called.
- Access tokens last 1 hour, refresh tokens 30 days. Rate limit: 120 calls per minute per token.

## Adding tools to a module

1. Create tool classes in `modules/<id>/Mcp/`, extending `App\Mcp\Tool`:

   ```php
   #[IsReadOnly]
   class ListThings extends Tool
   {
       protected string $module = 'things';   // your module id
       protected string $access = 'read';     // or 'write'
       protected string $name = 'things_list';
       protected string $description = 'What the assistant should know to pick this tool.';

       public function schema(JsonSchema $schema): array { return $this->pageSchema($schema); }

       public function handle(Request $request): Response
       {
           $page = $this->page(Thing::query()->visibleTo($this->user()->id), $request);

           return Response::json(['things' => $page['items'], 'next_cursor' => $page['next_cursor']]);
       }
   }
   ```

   Re-check per-row access (`ShareAccess`) in `handle()`; scopes are already enforced. Return
   `Response::error()` for not-found or rule violations. Annotate writes with `#[IsIdempotent]` or
   `#[IsDestructive]` where they apply. Put a rule that lives in a controller on the model instead and
   call it from both (see `TaskList::addTask`), rather than duplicating it.

2. Register them in your service provider's `boot()`:

   ```php
   McpTools::register('things', 'Things', [Mcp\ListThings::class, /* ... */]);
   ```

   That also creates the `things:read` / `things:write` scopes and the module's row on the consent screen
   (shown only in families that have the module enabled).

3. **Trash:** give deletable models `SoftDeletes` (plus `CascadesTrash` with `$trashChildren` /
   `$trashParents` for parent/child rows) and register them with `Trash::register(Model::class)` so
   `trash:purge` cleans them up after `config('familywell.trash_days')`. Implement `App\Support\Trash\TrashSource`
   (list + restore, applying your sharing rules) and register it with `Trash::source('things', ThingsTrash::class)`:
   it feeds Settings → Recently deleted, and your MCP trash tools should call it too.

## Operations

- **Keys:** Passport signs tokens with an RSA pair from `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`
  (newlines as literal `\n`). `.do/apply.sh` generates them into `.env.do` and injects them as secrets.
  Rotating them disconnects every app. Locally, `php artisan passport:keys` writes `storage/oauth-*.key`.
- **Env:** `MCP_AUTHORIZATION_SERVER` (the host that runs OAuth, `https://familywell.co` in production)
  and `MCP_SERVER_URL` (the address shown in Settings).
- **DNS:** `mcp.familywell.co` is an ALIAS domain on the app (`.do/app.yaml`) with a Cloudflare CNAME
  to the app ingress. No host-based routing: every route answers on both hosts.
- **Tests:** `tests/Feature/Mcp/` drives the real OAuth flow (`mcpConnect()` in `tests/Pest.php`) with
  keys generated at runtime.

Not in v1: personal access tokens, MCP resources/prompts, tools for the other modules, split/balance editing and receipts over MCP, a "last used" date.
