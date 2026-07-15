<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareModules
{
    public function __construct(protected ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next)
    {
        $urls = [];
        if ($familyId = CurrentFamily::id()) {
            foreach ($this->registry->discover() as $manifest) {
                if ($manifest->frontend && $this->registry->isEnabled($manifest->id, (int) $familyId)) {
                    $urls[] = route('module.frontend', ['module' => $manifest->id]);
                }
            }
        }
        View::share('moduleFrontends', $urls);

        return $next($request);
    }
}
