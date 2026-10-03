<?php

namespace Modules\Birthdays;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class BirthdaysController extends Controller
{
    // The Birthdays page: everyone with a birthday, soonest first. Family-scoped (BelongsToFamily)
    // and gated by module:birthdays on the route.
    public function index(): Response
    {
        return Inertia::render('birthdays/index', [
            'birthdays' => BirthdayDate::soonestFirst()->map(fn (BirthdayDate $b) => [
                'id' => $b->id,
                'personId' => $b->person_id,
                'name' => $b->person->name,
                'month' => $b->month,
                'day' => $b->day,
                'year' => $b->year(),
                'nextBirthday' => $b->nextBirthday()?->toDateString(),
                'daysUntil' => $b->daysUntil(),
                'age' => $b->age(),
                'turning' => $b->turning(),
            ])->values(),
        ]);
    }

    // Quick-add from the Birthdays page. The find-or-create-person logic lives in BirthdayDate::addFor
    // so the MCP birthdays_add tool behaves identically.
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(array_merge(['name' => ['required', 'string', 'max:255']], self::rules()));

        BirthdayDate::addFor($data['name'], $request, $request->user());

        return back();
    }

    public function update(Request $request, BirthdayDate $birthday): RedirectResponse
    {
        $request->validate(self::rules());
        $birthday->update(self::attributesFromRequest($request));

        return back();
    }

    /** Removes the birthday only. The person stays a contact; deleting them is a Contacts action. */
    public function destroy(BirthdayDate $birthday): RedirectResponse
    {
        $birthday->delete();

        return back();
    }

    /** How many upcoming birthdays the home widget shows; the Birthdays page has the rest. */
    public const WIDGET_LIMIT = 5;

    // JSON feed for the home-screen widget: the family's next few birthdays, soonest first. Capped
    // server-side (the demo family has one every day, so an uncapped list buried the dashboard);
    // `total` tells the widget how many are coming up so it can offer "See all".
    public function upcoming(Request $request): JsonResponse
    {
        $window = 60; // days ahead to show

        $upcoming = BirthdayDate::soonestFirst()->filter(fn (BirthdayDate $b) => $b->daysUntil() <= $window);

        $items = $upcoming
            ->take(self::WIDGET_LIMIT)
            ->map(fn (BirthdayDate $b) => [
                'personId' => $b->person_id,
                'name' => $b->person->name,
                'date' => $b->nextBirthday()->toDateString(),
                'daysUntil' => $b->daysUntil(),
                'age' => $b->age(),                                 // age today (null without a year)
                'turning' => $b->turning(), // age on the next birthday
            ])
            ->values();

        return response()->json(['birthdays' => $items, 'total' => $upcoming->count()]);
    }

    /** @return array{month:string[], day:string[], year:string[]} */
    public static function rules(): array
    {
        return [
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_year' => ['nullable', 'integer', 'between:1900,'.date('Y')],
        ];
    }

    /**
     * The BirthdayDate attributes a request describes, or null when it carries no birthday (clear).
     * Legacy `birthdate` (a full date) is still accepted from older clients. The full date is stored
     * only when a year is given; month/day are the recurring part.
     *
     * @return array{month:int, day:int, birthdate:?string}|null
     */
    public static function attributesFromRequest(Request $request): ?array
    {
        if ($request->filled('birth_month') && $request->filled('birth_day')) {
            $month = (int) $request->input('birth_month');
            $day = (int) $request->input('birth_day');
            $year = $request->filled('birth_year') ? (int) $request->input('birth_year') : null;
        } elseif ($request->filled('birthdate')) {
            $d = Carbon::parse($request->input('birthdate'));
            [$month, $day, $year] = [$d->month, $d->day, $d->year];
        } else {
            return null;
        }
        // Clamp the day to what the month has (Feb 30 → Feb 29/28); a date that doesn't exist would
        // otherwise throw on parse. Uses a leap year for the year-less case so Feb 29 stays valid.
        $day = min($day, Carbon::create($year ?? 2024, $month, 1)->daysInMonth);

        return [
            'month' => $month,
            'day' => $day,
            'birthdate' => $year ? Carbon::create($year, $month, $day)->toDateString() : null,
        ];
    }
}
