<?php

namespace Modules\Days;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contacts\Person;

class DaysController extends Controller
{
    // A1: every day the viewer can see, next date first. Family-scoped (BelongsToFamily) and gated by module:days.
    public function index(Request $request): Response
    {
        return Inertia::render('days/index', [
            'days' => Day::visibleTo($request->user()->id)->with('shareSetting')->get()
                ->sortBy([fn (Day $a, Day $b) => $a->daysUntil() <=> $b->daysUntil(), fn (Day $a, Day $b) => strcmp($a->title, $b->title)])
                ->map->toPayload()->values(),
            // "+ People" picks from Contacts; id + name only (the demo has 366).
            'people' => Person::query()->orderBy('name')->get(['id', 'name'])->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $day = Day::create($this->attributes($data) + ['created_by' => $request->user()->id]);
        // The creator owns it, so "Only me" still leaves them a way in (ShareAccess reads the explicit share).
        $day->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);
        $day->setVisibility($data['visibility']);

        return back();
    }

    public function update(Request $request, Day $day): RedirectResponse
    {
        abort_unless(in_array($day->roleFor($request->user()), ['owner', 'editor'], true), 403);
        $data = $this->validated($request);
        // A new date or reminder starts the reminder ledger over, so it can go out for the new day.
        $day->update($this->attributes($data) + ['reminded_on' => null]);
        if ($day->roleFor($request->user()) === 'owner') {
            $day->setVisibility($data['visibility']);
        }

        return back();
    }

    public function destroy(Request $request, Day $day): RedirectResponse
    {
        abort_unless(in_array($day->roleFor($request->user()), ['owner', 'editor'], true), 403);
        $day->delete();

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'tone' => ['required', Rule::in(Day::TONES)],
            'icon' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            'year' => ['nullable', 'integer', 'between:1900,'.date('Y')],
            'note' => ['nullable', 'string', 'max:500'],
            'person_ids' => ['nullable', 'array', 'max:20'],
            // Family-scoped exists: another family's contact id is rejected.
            'person_ids.*' => ['integer', Rule::exists('contacts_people', 'id')->where('family_id', CurrentFamily::id())],
            // null = no reminder; 0 = on the day; up to 60 days ahead (Custom), like birthdays.
            'remind_days' => ['nullable', 'integer', 'between:0,60'],
            // "Invited" needs a member picker; the share sheet can add that later, the form offers family / only me.
            'visibility' => ['required', Rule::in(['family', 'private'])],
        ]);
    }

    private function attributes(array $data): array
    {
        // Clamp to what the month has (Feb 30 → 29); a leap year when the year is unknown keeps Feb 29.
        $day = min($data['day'], Carbon::create($data['year'] ?? 2024, $data['month'], 1)->daysInMonth);

        return [
            'title' => trim($data['title']),
            'tone' => $data['tone'],
            'icon' => $data['icon'],
            'month' => $data['month'],
            'day' => $day,
            'year' => $data['year'] ?? null,
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'person_ids' => array_values(array_unique($data['person_ids'] ?? [])) ?: null,
            'remind_days' => $data['remind_days'] ?? null,
        ];
    }
}
