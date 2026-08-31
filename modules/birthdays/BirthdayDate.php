<?php

namespace Modules\Birthdays;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Contacts\Person;

/**
 * A person's birthdate — the birthdays module's extension of the `person` primitive (one row per
 * person, cascades with the person). Age and the next birthday are DERIVED here rather than stored,
 * so "recurring every year" needs no scheduler: the next occurrence is just computed from the date.
 */
class BirthdayDate extends Model
{
    use BelongsToFamily;

    protected $table = 'birthdays_dates';

    protected $fillable = ['person_id', 'birthdate', 'family_id'];

    protected $casts = ['birthdate' => 'date'];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /** Current age in whole years, or null if no date. */
    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    /** The next birthday on/after today (this year's, or next year's if it already passed). */
    public function nextBirthday(): ?Carbon
    {
        if (! $this->birthdate) {
            return null;
        }
        $today = Carbon::today();
        // setDate overflows Feb 29 to Mar 1 in a non-leap year — fine for our purposes.
        $next = $this->birthdate->copy()->setDate($today->year, $this->birthdate->month, $this->birthdate->day)->startOfDay();
        if ($next->lt($today)) {
            $next->addYear();
        }

        return $next;
    }

    /** Whole days until the next birthday (0 = today), or null if no date. */
    public function daysUntil(): ?int
    {
        $next = $this->nextBirthday();

        return $next ? Carbon::today()->diffInDays($next, false) : null;
    }
}
