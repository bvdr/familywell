<?php

namespace Modules\Birthdays;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BirthdaysController extends Controller
{
    // JSON feed for the home-screen widget: the family's birthdays coming up within the window,
    // soonest first. Family-scoped (BelongsToFamily) and gated by module:birthdays on the route.
    public function upcoming(Request $request): JsonResponse
    {
        $window = 60; // days ahead to show

        $items = BirthdayDate::with('person')
            ->get()
            ->filter(fn (BirthdayDate $b) => $b->birthdate && $b->person)
            ->map(fn (BirthdayDate $b) => [
                'personId' => $b->person_id,
                'name' => $b->person->name,
                'date' => $b->nextBirthday()->toDateString(),
                'daysUntil' => $b->daysUntil(),
                'age' => $b->age(),                                 // age today
                'turning' => $b->age() !== null ? $b->age() + 1 : null, // age on the next birthday
            ])
            ->filter(fn ($i) => $i['daysUntil'] !== null && $i['daysUntil'] <= $window)
            ->sortBy('daysUntil')
            ->values();

        return response()->json(['birthdays' => $items]);
    }
}
