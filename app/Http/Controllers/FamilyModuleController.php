<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\Preview;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class FamilyModuleController extends Controller
{
    public function index(ModuleRegistry $registry)
    {
        $family = Family::findOrFail(CurrentFamily::id());

        // Name/description come from lang/{locale}/modules.json (modules.<id>.name|description); module.json
        // stays the English source and the fallback for a module with no lang entry yet.
        $strings = Translations::for(app()->getLocale())['modules'] ?? [];

        $modules = collect($registry->discover())
            // A preview module ("preview": true) stays hidden here like everywhere else (enabledIds, the settings hub),
            // except for someone opted in to it, who needs this switch to try it.
            ->filter(fn ($m) => $registry->isInstalled($m->id) && (! $m->preview || Preview::on($m->id)))
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $strings[$m->id]['name'] ?? $m->name,
                'description' => $strings[$m->id]['description'] ?? $m->description,
                // The one-line "what it's for" under each switch (design Y2); the long description stays for MCP etc.
                'tagline' => $strings[$m->id]['tagline'] ?? $strings[$m->id]['description'] ?? $m->description,
                // module.json `requires`: a module needing exactly one other sits under it as an add-on.
                'requires' => $m->requires,
                'version' => $m->version,
                'enabled' => $registry->isEnabled($m->id, $family->id),
            ])->values();

        // Note: keyed as `catalog`, not `modules`. `modules` is a globally shared Inertia prop
        // (HandleInertiaRequests => {enabled: [...]}) that the nav reads to show module links; a
        // page prop of the same name would shadow it and drop those links on this page.
        return Inertia::render('family/modules', [
            'catalog' => $modules,
            'canManage' => Gate::allows('manageModules', $family),
            // The back pill returns to the family hub, labelled with the family's name.
            'familyName' => $family->name,
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
