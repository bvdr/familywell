<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class FamilyModuleController extends Controller
{
    public function index(ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());

        $modules = collect($registry->discover())
            ->filter(fn ($m) => $registry->isInstalled($m->id))
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'version' => $m->version,
                'enabled' => $registry->isEnabled($m->id, $family->id),
            ])->values();

        return Inertia::render('family/modules', [
            'modules' => $modules,
            'canManage' => Gate::allows('manageModules', $family),
        ]);
    }

    public function enable(string $module, ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageModules', $family);
        abort_unless($registry->isInstalled($module), 404);
        $registry->enable($module, $family->id);

        return back();
    }

    public function disable(string $module, ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageModules', $family);
        $registry->disable($module, $family->id);

        return back();
    }
}
