<?php

namespace App\Http\Controllers;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;

class ModuleAssetController extends Controller
{
    public function frontend(string $module, ModuleRegistry $registry)
    {
        $manifest = $registry->manifest($module);
        abort_unless(
            $manifest
            && CurrentFamily::id()
            && $registry->isEnabled($module, (int) CurrentFamily::id())
            && $manifest->frontendFile()
            && is_file($manifest->frontendFile()),
            404,
        );

        return response(file_get_contents($manifest->frontendFile()), 200, [
            'Content-Type' => 'text/javascript',
            // The URL carries the content hash (ShareModules), so the browser keeps it for a year: these 5 scripts were
            // re-fetched through the whole Laravel stack on every app open. Private: it sits behind the module check.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
