<?php

namespace Modules\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Calendar\CalendarSources;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contacts\Person;
use Modules\Projects\Project;
use Modules\Tasks\Mcp\TaskData;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

class CalendarController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $q = $request->validate([
            // 'week' was the week view before the agenda replaced it (old links / bookmarks): it opens the agenda.
            'view' => ['nullable', 'in:agenda,month,timeline,week'],
            'zoom' => ['nullable', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // The last view picked is remembered per device, so opening the calendar lands where you
        // left it with no client-side jump. A cookie the browser writes when you pick (pages/calendar/index.tsx), not
        // the session: the page's own background requests race the session write and could restore the old value.
        if (! isset($q['view'])) {
            $remembered = $request->cookie('fw_calendar_view');
            [$rememberedView, $rememberedZoom] = explode(':', ((string) $remembered).':');
            if (in_array($rememberedView, [...CalendarRange::VIEWS, 'week'], true)) {
                $q['view'] = $rememberedView;
                $q['zoom'] ??= $rememberedZoom ?: null;
            }
        }
        // Agenda first (design 04: "Agenda first with a week strip"); an old 'week' (view or zoom) maps to the new ones.
        $view = ($q['view'] ?? 'agenda') === 'week' ? 'agenda' : ($q['view'] ?? 'agenda');
        $zoom = in_array($q['zoom'] ?? null, CalendarRange::ZOOMS, true) ? $q['zoom'] : 'twoweeks';
        $date = $q['date'] ?? FamilyClock::today(); // opens on the family's today
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
            // Timeline (04b): the NEXT big thing after the window, and desktop's "Further out" (the next few months).
            'ahead' => $view === 'timeline' ? $this->bigThingsAfter($to, $request->user()->id) : [],
            // Filter chips: only sources whose module is on for this family.
            'sources' => array_values(array_filter(CalendarSources::modules(), fn ($m) => $registry->isEnabled($m, $familyId))),
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'contacts' => $this->contactOptions(),
            'projects' => $this->projectOptions($request),
            'categories' => CalendarCategory::forFamily(), // the chips, the filter and the event sheet; one query a request
            'feedUrl' => CalendarFeed::urlFor($request->user()->id), // Task 10; null until then
            // Other calendars for the Calendars sheet. A closure, so date navigation (partial reloads of items) skips it.
            'subscriptions' => fn () => CalendarSubscriptionController::listFor($request->user()->id),
            // Right-click › To-do due <day> (design 04b D3): the lists it can go in, loaded only when that dialog opens.
            'todoLists' => Inertia::optional(fn () => $registry->isEnabled('tasks', $familyId) ? TaskList::pickerChoices($request->user()) : []),
        ]);
    }

    /**
     * The things that take a day (design 04b: trips, fasts, holidays, school breaks: all-day events; not a 3pm dentist,
     * not to-dos, not every contact's birthday) in the 9 months after $to, soonest first: the timeline's NEXT row and
     * desktop's "Further out". A repeating event counts once (its next time). Same rule as takesADay in the browser.
     */
    private function bigThingsAfter(string $to, int $userId): array
    {
        $from = CarbonImmutable::parse($to)->addDay();
        $seen = [];

        return collect(CalendarSources::collect($from->toDateString(), $from->addMonths(9)->toDateString(), $userId, ['calendar']))
            ->filter(fn ($i) => $i['startTime'] === null)
            ->filter(function ($i) use (&$seen) {
                $key = "{$i['source']}-{$i['kind']}-{$i['id']}";

                return isset($seen[$key]) ? false : ($seen[$key] = true);
            })
            ->take(6)->values()->all();
    }

    /**
     * Items for [from, to], for the endless timeline to load the days it scrolls into. Same
     * sources, visibility and shape as the page's `items` prop; the range cap is Occurrences::MAX_RANGE_DAYS.
     */
    public function items(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > Occurrences::MAX_RANGE_DAYS, 422);

        return response()->json(['items' => CalendarSources::collect($q['from'], $q['to'], $request->user()->id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        CalendarEvent::createFor($request->user(), CalendarEvent::validated($request->all()));

        return back();
    }

    public function show(Request $request, CalendarEvent $event): Response
    {
        abort_unless($this->access->canView($request->user(), $event), 403);
        // ?day= opens the occurrence covering that date (a repeating fast has one page for every year) with that
        // day's sheet showing: where a tap on the event in the day list lands.
        $day = $request->validate(['day' => ['nullable', 'date_format:Y-m-d']])['day'] ?? null;
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
            // The edit sheet's people + project pickers, same lists as the index.
            'contacts' => $this->contactOptions(),
            'projects' => $this->projectOptions($request),
            'days' => $event->isMultiDay() ? $this->daysPayload($event, $day, $request->user()->id) : null,
            'openDay' => $day,
            'categories' => CalendarCategory::forFamily(),
        ]);
    }

    /**
     * What the event page's days grid (C15) needs for one occurrence: its dates, the marks (by day index, same in every
     * occurrence), the notes dated inside it, and "coming up inside": the next few OTHER events that fall within
     * the span, one range query through the calendar source so visibility and repeats follow the same rules as the
     * calendar itself. To-dos need nothing extra: the page already has the event's dated to-dos.
     */
    private function daysPayload(CalendarEvent $event, ?string $day, int $userId): array
    {
        [$start, $end] = $event->occurrenceAt($day);
        $today = FamilyClock::today();
        $from = $end < $today ? $start : max($start, $today); // a finished occurrence lists all it had
        $to = CarbonImmutable::parse($from)->addDays(Occurrences::MAX_RANGE_DAYS)->min(CarbonImmutable::parse($end))->toDateString();
        $inside = collect(CalendarSources::collect($from, $to, $userId, ['calendar']))
            ->filter(fn ($i) => $i['kind'] === 'event' && ! ($i['id'] === $event->id && $i['source'] === 'calendar'))
            ->take(5)->map(fn ($i) => ['id' => $i['id'], 'title' => $i['title'], 'date' => max($i['start'], $from), 'startTime' => $i['startTime'], 'href' => $i['href']])
            ->values()->all();

        return [
            'start' => $start,
            'end' => $end,
            'marks' => $event->marks->map->toPayload()->values()->all(),
            'notes' => (object) $event->dayNotes()->whereBetween('date', [$start, $end])->pluck('body', 'date')->all(),
            'inside' => $inside,
        ];
    }

    // ---- Days inside an event (C15-C17): marks by day index, and a note per date ----

    // Who may mark which events; the field rules live in CalendarEventMark::validated (shared with the MCP tools).
    private function markData(Request $request, CalendarEvent $event, bool $partial): array
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        abort_unless($event->isMultiDay(), 422, 'Only events longer than one day have days to mark.');

        return CalendarEventMark::validated($event, $request->all(), $partial);
    }

    public function storeMark(Request $request, CalendarEvent $event): RedirectResponse
    {
        $data = $this->markData($request, $event, false);
        abort_if($event->marks()->count() >= CalendarEventMark::MAX_PER_EVENT, 422, 'Too many marks on this event.');
        $event->marks()->create($data);

        return back();
    }

    // The mark is looked up through the event (its id is a plain route value, not a bound model), so a mark of
    // another event or family can never be reached by guessing ids.
    public function updateMark(Request $request, CalendarEvent $event, string $mark): RedirectResponse
    {
        $data = $this->markData($request, $event, true); // authorizes first: a 403 must not depend on whether the id exists
        $event->marks()->findOrFail($mark)->update($data);

        return back();
    }

    public function destroyMark(Request $request, CalendarEvent $event, string $mark): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $event->marks()->findOrFail($mark)->delete();

        return back();
    }

    // One note per date; an empty body removes it. Only dates inside an occurrence can carry a note.
    public function putDayNote(Request $request, CalendarEvent $event, string $date): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        abort_unless(checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 422);
        abort_unless($event->isMultiDay() && $event->coversDate($date), 422, 'That date is not inside this event.');
        $event->setDayNote($date, $request->validate(['body' => ['present', 'nullable', 'string', 'max:500']])['body']);

        return back();
    }

    // The event sheet's values, for the calendar's right-click › Edit / Duplicate (design 04b D4): the sheet opens over
    // the calendar instead of sending you to the event's page and back (where the copy wasn't on screen yet).
    public function form(Request $request, CalendarEvent $event): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $event), 403);

        return response()->json($event->toPayload());
    }

    public function update(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $event->applyUpdate($request->only([...CalendarEvent::COLUMNS, 'people', 'project_id']), $request->user());

        return back();
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($event->created_by === $request->user()->id, 403); // creator only, like notes/recipes
        $event->delete(); // trash; its to-dos go with it (CalendarEvent::booted)

        return to_route('calendar.index');
    }

    // ---- To-dos (listless tasks-module tasks linked to the event, same model as project tasks) ----

    // Route binding runs before the family scope, so guard family_id explicitly, then the event's role.
    private function eventForTask(Request $request, Task $task): CalendarEvent
    {
        abort_unless((int) $task->family_id === (int) CurrentFamily::id(), 403);
        $event = CalendarEvent::forTask($task);
        abort_unless($event && $this->access->canEdit($request->user(), $event), 403);

        return $event;
    }

    public function storeTask(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $event->addTask($request->user(), $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            // Members only (same rule as the tasks/projects tools): no cross-family assignee or notification.
            'assigned_to' => ['nullable', 'integer', Rule::in(TaskData::memberIds())],
            'parent_id' => ['nullable', 'integer'],
        ]));

        return back();
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        $event = $this->eventForTask($request, $task);
        // An empty body toggles done (the task-tree's circle and the dashboard tick), like the project routes.
        $data = $request->hasAny(['title', 'note', 'due_date', 'assigned_to', 'done'])
            ? $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'note' => ['sometimes', 'nullable', 'string', 'max:255'],
                'due_date' => ['sometimes', 'nullable', 'date'],
                'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in(TaskData::memberIds())],
                'done' => ['sometimes', 'boolean'],
            ])
            : ['done' => ! $task->done];
        $event->updateTask($request->user(), $task, $data);

        return back();
    }

    public function destroyTask(Request $request, Task $task): RedirectResponse
    {
        $this->eventForTask($request, $task)->deleteTask($task);

        return back();
    }

    // Drag reorder + re-nest; same contract as ProjectsController::reorderTasks ({order:[{id,parent_id}]}).
    public function reorderTasks(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $data = $request->validate([
            'order' => ['present', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.parent_id' => ['nullable', 'integer'],
        ]);
        $topIds = $event->taskIds();
        $tasks = Task::where(fn ($q) => $q->whereIn('id', $topIds)->orWhereIn('parent_id', $topIds))->get()->keyBy('id');
        abort_unless(collect($data['order'])->pluck('id')->every(fn ($id) => $tasks->has($id)), 422);
        $parentIds = collect($data['order'])->pluck('parent_id')->filter()->unique();
        foreach ($data['order'] as $row) {
            if ($row['parent_id'] !== null) {
                abort_unless($tasks->has($row['parent_id']), 422);
                abort_if($parentIds->contains($row['id']), 422, 'Only one level of nesting is allowed.');
            }
        }
        $counters = [];
        foreach ($data['order'] as $row) {
            $key = $row['parent_id'] ?? 'root';
            $pos = $counters[$key] = ($counters[$key] ?? -1) + 1;
            $tasks[$row['id']]->update(['parent_id' => $row['parent_id'], 'position' => $pos]);
            $row['parent_id'] === null
                ? $event->links()->firstOrCreate(['linkable_type' => 'task', 'linkable_id' => $row['id']])
                : $event->links()->where(['linkable_type' => 'task', 'linkable_id' => $row['id']])->delete();
        }

        return back();
    }

    // People picker: the family's contacts (Person is family-scoped).
    private function contactOptions()
    {
        return Person::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values();
    }

    // Project picker: viewable, non-archived projects; null hides the picker when the projects module is off.
    private function projectOptions(Request $request)
    {
        return app(ModuleRegistry::class)->isEnabled('projects', (int) CurrentFamily::id())
            ? Project::query()->visibleTo($request->user()->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()
            : null;
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
