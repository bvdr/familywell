<?php

namespace App\Http\Controllers\Settings;

use App\Events\ProjectsChanged;
use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Support\Media\StoredImage;
use App\Support\Modules\ModuleRegistry;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Projects\Project;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendReceipt;

// The family's media library (Settings > Media): every uploaded image the current user can see, across
// modules, so it can be browsed and deleted in one place. Enumerated live from each source (project
// covers, spend receipts) rather than a central table — there are only a couple of image types today,
// and this reuses each module's existing auth-gated serve route. Only shows media the user may view,
// and deleting clears the owner's reference so nothing is left pointing at a missing file.
class MediaLibraryController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $familyId = (int) CurrentFamily::id();
        $registry = app(ModuleRegistry::class);
        $items = [];

        // Project covers, on projects the user can view.
        if ($registry->isEnabled('projects', $familyId)) {
            $projects = Project::query()->whereNotNull('cover_path')->with(['shareSetting', 'shares'])->get()
                ->filter(fn (Project $p) => $p->visibility() === 'family' || $p->shares->contains('user_id', $userId));
            foreach ($projects as $p) {
                $items[] = [
                    'type' => 'cover',
                    'id' => $p->id,
                    'name' => $p->name,
                    'kind' => 'Project cover',
                    'thumbUrl' => route('projects.cover.show', $p).'?v=thumb',
                    'fullUrl' => route('projects.cover.show', $p),
                ];
            }
        }

        // Spend receipts, on events the user can view.
        if ($registry->isEnabled('spending', $familyId)) {
            $eventIds = SpendEvent::query()->with(['shareSetting', 'shares'])->get()
                ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $userId))
                ->pluck('id');
            $names = SpendEvent::whereIn('id', $eventIds)->pluck('name', 'id');
            $receipts = SpendReceipt::query()
                ->whereHas('spend', fn ($q) => $q->whereIn('spend_event_id', $eventIds))
                ->with('spend:id,spend_event_id')
                ->latest()->get();
            foreach ($receipts as $r) {
                $items[] = [
                    'type' => 'receipt',
                    'id' => $r->id,
                    'name' => $names[$r->spend->spend_event_id] ?? 'Receipt',
                    'kind' => 'Receipt',
                    'thumbUrl' => route('spending.receipts.show', $r->id),
                    'fullUrl' => route('spending.receipts.show', $r->id),
                ];
            }
        }

        return Inertia::render('settings/media', ['items' => $items]);
    }

    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        if ($type === 'cover') {
            $project = Project::findOrFail($id); // BelongsToFamily scopes this to the current family
            abort_unless($this->access->roleFor($request->user(), $project) !== null, 403);
            StoredImage::delete($project->cover_path);
            $project->update(['cover_path' => null]);
            Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));

            return back();
        }

        if ($type === 'receipt') {
            $receipt = SpendReceipt::findOrFail($id);
            $spend = Spend::findOrFail($receipt->spend_id); // family-scoped: 404s if not our family
            $event = SpendEvent::findOrFail($spend->spend_event_id);
            abort_unless($this->access->roleFor($request->user(), $event) !== null, 403);
            Storage::disk('r2')->delete($receipt->path);
            $receipt->delete();
            Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

            return back();
        }

        abort(404);
    }
}
