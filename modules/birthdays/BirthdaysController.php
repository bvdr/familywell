<?php

namespace Modules\Birthdays;

use App\Http\Controllers\Controller;
use App\Support\Modules\Hooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contacts\Person;

class BirthdaysController extends Controller
{
    public function __construct(private Hooks $hooks) {}

    // The Birthdays page: everyone with a birthday, soonest first. Family-scoped (BelongsToFamily)
    // and gated by module:birthdays on the route.
    public function index(): Response
    {
        return Inertia::render('birthdays/index', [
            'birthdays' => $this->all()->map(fn (BirthdayDate $b) => [
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

    /**
     * Quick-add from the Birthdays page: a name + day/month (+ optional year). The person is the
     * Contacts `person` primitive, created here so they show up in Contacts too; person.saved fires
     * so every extension sees the new person. If a contact with that exact name already exists, the
     * birthday attaches to them instead of creating a duplicate person (ponytail: exact
     * case-insensitive match; fuzzy matching can wait until someone actually asks).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(array_merge(['name' => ['required', 'string', 'max:255']], self::rules()));

        $person = Person::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])->first()
            ?? Person::create(['name' => trim($data['name']), 'created_by' => $request->user()->id]);

        BirthdayDate::updateOrCreate(['person_id' => $person->id], self::attributesFromRequest($request));
        // The listener in the provider also reacts to birth_month, harmlessly re-saving the same row.
        $this->hooks->fire('person.saved', $person, $request);

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

    // JSON feed for the home-screen widget: the family's birthdays coming up within the window,
    // soonest first.
    public function upcoming(Request $request): JsonResponse
    {
        $window = 60; // days ahead to show

        $items = $this->all()
            ->filter(fn (BirthdayDate $b) => $b->daysUntil() <= $window)
            ->map(fn (BirthdayDate $b) => [
                'personId' => $b->person_id,
                'name' => $b->person->name,
                'date' => $b->nextBirthday()->toDateString(),
                'daysUntil' => $b->daysUntil(),
                'age' => $b->age(),                                 // age today (null without a year)
                'turning' => $b->turning(), // age on the next birthday
            ])
            ->values();

        return response()->json(['birthdays' => $items]);
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

    /** Every birthday in the family with its person, soonest first (people deleted → row cascaded). */
    private function all()
    {
        return BirthdayDate::with('person')->get()
            ->filter(fn (BirthdayDate $b) => $b->person && $b->nextBirthday())
            ->sortBy([fn ($a, $b) => $a->daysUntil() <=> $b->daysUntil(), fn ($a, $b) => strcmp($a->person->name, $b->person->name)])
            ->values();
    }
}
