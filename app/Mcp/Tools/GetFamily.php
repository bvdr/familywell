<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Family;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetFamily extends Tool
{
    protected string $module = McpTools::CORE;

    protected string $name = 'get_family';

    protected string $description = 'Use this when you need the family\'s members (ids, names, roles), which FamilyWell modules are turned on, or what this connection may read or change in each, for example to find a member id for an assignee or to explain why a module can\'t be reached. `not_connected` lists modules the family uses that this connection was not granted; the person can add them by reconnecting FamilyWell. Read-only.';

    protected string $title = 'Get family and access';

    public function handle(Request $request): Response|ResponseFactory
    {
        $family = Family::with('members')->findOrFail(CurrentFamily::id());
        $registry = app(ModuleRegistry::class);
        $names = collect($registry->discover())->map(fn ($m) => $m->name);

        $scopes = $this->tokenScopes();
        $modules = McpTools::modules();
        $missing = array_map(fn ($id) => ['id' => $id, 'name' => $modules[$id]], McpTools::notConnected($family->id, $scopes));

        return Response::structured([
            'family' => ['id' => $family->id, 'name' => $family->name],
            'you' => ['id' => $this->user()->id, 'name' => $this->user()->name],
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'role' => $m->pivot->role])->values(),
            'enabled_modules' => collect($registry->enabledIds($family->id))->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? $id])->values(),
            // Only modules this connection can touch; anything missing here is not accessible.
            'permissions' => McpTools::accessFromScopes($scopes),
            // Access is fixed at consent time, so a module turned on later is invisible until a reconnect;
            // without this the assistant just says "I can't see your calendar" with no way forward.
            'not_connected' => $missing,
            'url' => $this->url('/dashboard'),
        ] + ($missing ? ['hint' => 'This connection cannot see '.implode(', ', array_column($missing, 'name'))
            .'. Tell the person to reconnect Familywell in their AI app and grant access to add them (Settings > Connected apps in Familywell lists their connections).'] : []));
    }
}
