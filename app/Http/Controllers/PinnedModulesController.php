<?php

namespace App\Http\Controllers;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;

// "Pin your top 4": which modules sit on top of a person's dashboard and sidebar. Every module stays on for the
// family; pinning only decides what is one tap away. Per person (users.pinned_modules), since each member
// reaches for different things, and kept in the order they were pinned.
class PinnedModulesController extends Controller
{
    public const MAX = 4;

    public function update(Request $request, ModuleRegistry $registry)
    {
        // Only modules this family has on: a pin to a module that is off would point at a 404.
        $enabled = $registry->enabledIds((int) CurrentFamily::id());
        $data = $request->validate([
            'modules' => ['present', 'array', 'max:'.self::MAX],
            'modules.*' => ['string', 'distinct', 'in:'.implode(',', $enabled)],
        ]);
        $request->user()->forceFill(['pinned_modules' => array_values($data['modules'])])->save();

        return back();
    }
}
