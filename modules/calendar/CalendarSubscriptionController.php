<?php

namespace Modules\Calendar;

use App\Http\Controllers\Controller;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

// Other calendars (issue #222): add / change / remove an ICS subscription, and the read-only page of one of
// its events. Only the person who added a subscription can change or remove it; `shared` shows its events to
// the family, but never its URL.
class CalendarSubscriptionController extends Controller
{
    /** The rows for the "Other calendars" sheet: mine, plus ones others shared with the family. */
    public static function listFor(int $userId): array
    {
        return CalendarSubscription::query()->visibleTo($userId)->with('user:id,name')->orderBy('name')->get()
            ->map(fn (CalendarSubscription $s) => [
                'id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'shared' => $s->shared, 'enabled' => $s->enabled,
                'error' => $s->error, 'syncedAt' => $s->synced_at?->toIso8601String(),
                'mine' => $s->user_id === $userId, 'owner' => $s->user?->name,
            ])->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:20'],
            'shared' => ['boolean'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);
        try {
            $data['url'] = IcsFetcher::normalize($data['url']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['url' => Translations::get('calendar.sub_error_blocked')]);
        }

        // Fetch once now so a wrong URL is an error on the form, not a silent empty calendar an hour later.
        $sub = new CalendarSubscription($data + ['user_id' => $request->user()->id]);
        $sub->save();
        if (! $sub->sync()) {
            $error = $sub->error;
            $sub->delete();
            throw ValidationException::withMessages(['url' => Translations::get("calendar.sub_error_{$error}")]);
        }

        return back();
    }

    public function update(Request $request, CalendarSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);
        $subscription->update($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shared' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]));

        return back();
    }

    public function destroy(Request $request, CalendarSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);
        $subscription->delete(); // events cascade (FK)

        return back();
    }

    // Event ids change on every hourly sync (rows are replaced), so an old link 404s; the calendar is one tap away.
    public function show(Request $request, CalendarSubscriptionEvent $event): Response
    {
        // The family scope rides on the subscription: a missing one is another family's (or a removed) calendar.
        $sub = CalendarSubscription::query()->visibleTo($request->user()->id)->find($event->subscription_id);
        abort_unless($sub !== null, 404);

        return Inertia::render('calendar/external', [
            'event' => [
                'title' => $event->title !== '' ? $event->title : $sub->name,
                'location' => $event->location, 'notes' => $event->notes,
                'start_date' => $event->start_date->toDateString(), 'end_date' => $event->end_date->toDateString(),
                'start_time' => $event->start_time, 'end_time' => $event->end_time,
            ],
            'calendar' => ['name' => $sub->name, 'color' => $sub->color],
        ]);
    }
}
