<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Settings > Recently deleted: everything this person can see in the active family's 30-day trash, across
// the modules that registered a Trash::source (tasks, shopping, spending, notes), with Restore. Each source
// applies its module's own sharing rules, so this page shows exactly what the MCP *_list_deleted tools do.
class RecentlyDeletedController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = collect($this->sources())
            ->flatMap(fn (string $source, string $module) => $source::list($request->user())->map(fn (array $r) => $r + ['module' => $module]));

        return Inertia::render('settings/recently-deleted', [
            'items' => Trash::sorted($rows),
            'days' => Trash::days(),
        ]);
    }

    public function restore(Request $request, string $module, string $type, int $id): RedirectResponse
    {
        $source = $this->sources()[$module] ?? abort(404);
        abort_unless($source::restore($request->user(), $type, $id), 404);

        return back();
    }

    /** Sources of the modules enabled in the active family (a disabled module's trash stays hidden). */
    private function sources(): array
    {
        $enabled = app(ModuleRegistry::class)->enabledIds((int) CurrentFamily::id());

        return array_intersect_key(Trash::sources(), array_flip($enabled));
    }
}
