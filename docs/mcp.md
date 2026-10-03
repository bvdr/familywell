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

**Deletes:** tasks, lists, shopping items, expenses and notes go to a 30-day trash.
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
| Vehicles | `vehicles_list`, `vehicles_get`, `vehicles_history` | `vehicles_log_mileage`, `vehicles_log_fuel`, `vehicles_complete_maintenance`, `vehicles_add_repair`, `vehicles_add_expense`, `vehicles_add_document`, `vehicles_attach_file`, `vehicles_delete_record`, `vehicles_delete` |
| Contacts | `contacts_list` (with search), `contacts_get` | `contacts_create`, `contacts_update`, `contacts_delete` |
| Birthdays | `birthdays_list` (soonest first, `within_days`) | `birthdays_add`, `birthdays_update`, `birthdays_delete` |
| Inventory | `inventory_list`, `inventory_get`, `inventory_needs_attention` | `inventory_add_item`, `inventory_update_item`, `inventory_adjust_quantity`, `inventory_add_to_shopping`, `inventory_delete_item` |
| Projects | `projects_list`, `projects_get` | `projects_create`, `projects_update`, `projects_add_task`, `projects_update_task`, `projects_delete_task`, `projects_delete` |

**Files:** MCP has no upload primitive, so tools that take a file (`vehicles_add_document`,
`vehicles_attach_file`, and the optional file on `vehicles_add_repair` / `vehicles_complete_maintenance`) accept it
three ways through `App\Support\Mcp\IncomingFile`: `file` = `{download_url}` (what ChatGPT passes for a file
attached in the chat, enabled by the tool's `_meta['openai/fileParams']`), `file_url` (public https), or
`file_base64` + `file_name` (clients that can read local files). PDF/JPEG/PNG/WebP/HEIC up to 12 MB, sniffed from
the bytes. URL downloads are https-only, refuse private/reserved addresses on every hop, pin the vetted address,
and stop reading past the size cap. A service invoice/deviz is filed as a `service_invoice` document (service
records have no attachments of their own). Claude.ai cannot pass the bytes of a chat attachment; there the
assistant creates the record and links the person to the app page to upload the file.

**Mileage dates:** `vehicles_log_mileage` takes an optional past `date`. A reading dated before today goes into the
history at that date, must fit between the readings around it (and not exceed today's odometer), and leaves the
current odometer alone.

**Task extensions:** when a family has Important to-dos or Recurring to-dos on, `tasks_create_task` and
`tasks_update_task` gain `important` / `recurrence` parameters and task payloads carry those fields. The Tasks
tools run the same `task.serialize` and `task.saved` hooks as the web editor, and extensions add their tool
parameters through the `task.mcp.schema` filter, so Tasks itself knows nothing about them.

Contacts, birthdays, inventory items, projects and car records have no trash: their delete tools are permanent,
marked destructive (clients ask before running them) and tell the assistant to confirm with the person first.
Each runs the same path as the app's delete button and frees stored files. `vehicles_delete_record` also removes a
single mileage reading (the app has no button for that): deleting the newest reading, when the odometer still shows
it, sets the odometer back to the previous reading.

Vehicle distances are kilometres in and out (miles are only a display setting).

Tools act **as the person who connected**: the same sharing rules as the web app apply (a private list
of another member is invisible), the same validation, and the same notifications to the family.

## Widgets (MCP Apps)

Hosts that support [MCP Apps](https://modelcontextprotocol.io/extensions/apps/overview) (Claude, Claude
Desktop, ChatGPT, VS Code Copilot, ...) show an interactive widget next to a read tool's result instead
of only the JSON. Other clients see the same JSON as before.

| Widget | Tools | What you can do in it |
|---|---|---|
| `tasks` | `tasks_get_list`, `tasks_search` | tick tasks and sub-tasks, add a task |
| `shopping` | `shopping_get_list`, `shopping_search` | items by aisle, tick as bought, add an item, full screen |
| `project` | `projects_get` | progress, tick tasks, add a task, dependencies, linked lists |
| `today` | `get_today` | tick off due tasks, shopping items and project tasks |
| `spending` | `spending_summary`, `spending_list_expenses` | totals by category/shop, switch period |
| `inventory` | `inventory_needs_attention`, `inventory_list` | -/+ stock, add to shopping |
| `vehicle` | `vehicles_get`, `vehicles_list`, `vehicles_history` | odometer, mark maintenance done, documents, history |
| `birthdays` | `birthdays_list` | countdowns (read only) |
| `note` | `notes_get` | tick the note's `- [ ]` checklists |
| `contact` | `contacts_get` | call / email / map links |

Widgets change data only by calling the normal write tools through the host, so a *Read* connection gets
a view-only widget (a change says it needs *Read & write*). After a change the widget tells the model
what the person did (`ui/update-model-context`), so the next turn knows.

**How:** `App\Mcp\Apps\Widget` is one `AppResource` per widget (`ui://familywell/<widget>`, registered in
`FamilywellServer::boot`). Its page is `resources/views/mcp-app.blade.php`: laravel/mcp's SDK shell plus
`resources/mcp-apps/core.{css,js}` (shared runtime: `FW.start`, `FW.act`, `FW.h`, ...) and
`resources/mcp-apps/<widget>.js`, all inlined, so the CSP needs no external domains. A tool opts in with
`protected ?string $app = '<widget>';`. New widget: a line in `Widget::WIDGETS` plus its JS file.

**Full screen:** a widget listed in `Widget::FULLSCREEN` and started with `FW.start(render, { fullscreen: true })`
declares `availableDisplayModes: ["inline", "fullscreen"]` and shows a *Full screen* button when the host
offers that mode (`ui/request-display-mode`). laravel/mcp's SDK hardcodes `appCapabilities: {}`, so
`Widget::sdk()` patches that literal; `AppsTest` fails if a package update changes it.

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

Not in v1: personal access tokens, MCP resources/prompts, split/balance editing and receipts over MCP, a "last used" date.
