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
                    // ?v= the file's content hash: the asset is served with a year-long cache (ModuleAssetController), so
                    // a new build must get a new URL. Hashed once per worker; files only change on deploy (new workers).
                    static $hashes = [];
                    $file = (string) $manifest->frontendFile();
                    $hashes[$file] ??= is_file($file) ? substr(md5_file($file), 0, 12) : '0';
                    $urls[] = route('module.frontend', ['module' => $manifest->id, 'v' => $hashes[$file]]);
                }
            }
        }
        View::share('moduleFrontends', $urls);

        return $next($request);
    }
}
