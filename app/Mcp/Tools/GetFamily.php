<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Family;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetFamily extends Tool
{
    protected string $module = McpTools::CORE;

    protected string $name = 'get_family';

    protected string $description = 'The family this connection acts on: its name, members (with role), the Familywell modules it has turned on, and what this connection may do in each module (read or write). Call this first.';

    public function handle(Request $request): Response
    {
        $family = Family::with('members')->findOrFail(CurrentFamily::id());
        $registry = app(ModuleRegistry::class);
        $names = collect($registry->discover())->map(fn ($m) => $m->name);

        return Response::json([
            'family' => ['id' => $family->id, 'name' => $family->name],
            'you' => ['id' => $this->user()->id, 'name' => $this->user()->name],
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'role' => $m->pivot->role])->values(),
            'enabled_modules' => collect($registry->enabledIds($family->id))->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? $id])->values(),
            // Only modules this connection can touch; anything missing here is not accessible.
            'permissions' => McpTools::accessFromScopes($this->tokenScopes()),
            'url' => $this->url('/dashboard'),
        ]);
    }
}
