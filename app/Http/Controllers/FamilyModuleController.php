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
                'description' => $m->description,
                'version' => $m->version,
                'enabled' => $registry->isEnabled($m->id, $family->id),
            ])->values();

        // Note: keyed as `catalog`, not `modules`. `modules` is a globally shared Inertia prop
        // (HandleInertiaRequests => {enabled: [...]}) that the nav reads to show module links; a
        // page prop of the same name would shadow it and drop those links on this page.
        return Inertia::render('family/modules', [
            'catalog' => $modules,
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
