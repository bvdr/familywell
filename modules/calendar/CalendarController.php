<?php

namespace Modules\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Calendar\CalendarSources;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contacts\Person;
use Modules\Projects\Project;
use Modules\Tasks\Task;

class CalendarController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $q = $request->validate([
            'view' => ['nullable', 'in:month,timeline'],
            'zoom' => ['nullable', 'in:week,month'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $view = $q['view'] ?? 'month';
        $zoom = $q['zoom'] ?? 'month';
        $date = $q['date'] ?? now()->toDateString();
        [$from, $to] = CalendarRange::for($view, $zoom, $date);

        $familyId = (int) CurrentFamily::id();
        $family = Family::findOrFail($familyId);
        $registry = app(ModuleRegistry::class);

        return Inertia::render('calendar/index', [
            'view' => $view,
            'zoom' => $zoom,
            'date' => $date,
            'range' => ['from' => $from, 'to' => $to],
            'items' => CalendarSources::collect($from, $to, $request->user()->id),
            // Filter chips: only sources whose module is on for this family.
            'sources' => array_values(array_filter(CalendarSources::modules(), fn ($m) => $registry->isEnabled($m, $familyId))),
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'contacts' => Person::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'projects' => $registry->isEnabled('projects', $familyId)
                ? Project::query()->visibleTo($request->user()->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()
                : null,
            'feedUrl' => CalendarFeed::urlFor($request->user()->id), // Task 10; null until then
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        CalendarEvent::createFor($request->user(), CalendarEvent::validated($request->all()));

        return back();
    }

    public function show(Request $request, CalendarEvent $event): Response
    {
        abort_unless($this->access->canView($request->user(), $event), 403);
        $family = Family::findOrFail(CurrentFamily::id());
        $tasks = Task::whereIn('id', $event->taskIds())->whereNull('parent_id')->with('subtasks')->orderBy('position')->orderBy('id')->get();
        $sub = fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'note' => $t->note, 'done' => $t->done,
            'dueDate' => optional($t->due_date)->toDateString(), 'assignedTo' => $t->assigned_to];

        return Inertia::render('calendar/event', [
            'event' => $event->toPayload(),
            'canEdit' => $this->access->canEdit($request->user(), $event),
            'canDelete' => $event->created_by === $request->user()->id,
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'tasks' => $tasks->map(fn (Task $t) => $sub($t) + ['subtasks' => $t->subtasks->map($sub)->values()])->values(),
            'share' => $this->shareState($event, $family),
        ]);
    }

    public function update(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $event->applyUpdate($request->only([...CalendarEvent::COLUMNS, 'people', 'project_id']));

        return back();
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($event->created_by === $request->user()->id, 403); // creator only, like notes/recipes
        $event->delete(); // trash; its to-dos go with it (CalendarEvent::booted)

        return to_route('calendar.index');
    }

    // The ShareState the core ShareSheet expects. Same shape as RecipesController::shareState.
    private function shareState(CalendarEvent $event, Family $family): array
    {
        $visibility = $event->visibility();
        $byUser = $event->shares->keyBy('user_id');

        return [
            'type' => 'calendar_event',
            'id' => $event->id,
            'visibility' => $visibility,
            'canManage' => $this->access->canManage(request()->user(), $event),
            'members' => $family->members->map(function ($m) use ($byUser, $visibility) {
                $explicit = $byUser->get($m->id)?->role;

                return ['id' => $m->id, 'name' => $m->name, 'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null), 'isOwner' => $explicit === 'owner'];
            })->values(),
            'link' => null,
        ];
    }
}
