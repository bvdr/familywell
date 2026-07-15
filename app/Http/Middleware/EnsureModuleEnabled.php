<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;

class EnsureModuleEnabled
{
    public function __construct(protected ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $moduleId)
    {
        abort_unless(
            CurrentFamily::id() && $this->registry->isEnabled($moduleId, (int) CurrentFamily::id()),
            404,
        );

        return $next($request);
    }
}
