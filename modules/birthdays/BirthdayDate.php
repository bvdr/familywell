<?php

namespace Modules\Birthdays;

use App\Models\User;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Contacts\Person;

/**
 * A person's birthday — the birthdays module's extension of the `person` primitive (one row per
 * person, cascades with the person). The recurring part is `month` + `day`; the full `birthdate`
 * (with the year) is optional and only unlocks age / "turns N". Age and the next birthday are
 * DERIVED here rather than stored, so "recurring every year" needs no scheduler.
 */
class BirthdayDate extends Model
{
    use BelongsToFamily;

    protected $table = 'birthdays_dates';

    protected $fillable = ['person_id', 'month', 'day', 'birthdate', 'family_id', 'notified_on'];

    protected $casts = ['birthdate' => 'date', 'notified_on' => 'date'];

    /**
     * Birthdays falling on the given day (default today). A Feb 29 birthday is celebrated on Mar 1
     * in non-leap years, matching what nextBirthday() derives, so the card and the push agree with
     * the list's "Today!".
     */
    public function scopeOn(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= Carbon::today();
        $query->where(fn (Builder $q) => $q->where('month', $date->month)->where('day', $date->day));
        if ($date->month === 3 && $date->day === 1 && ! $date->isLeapYear()) {
            $query->orWhere(fn (Builder $q) => $q->where('month', 2)->where('day', 29));
        }

        return $query;
    }

    /** "Veronica turns 22 today" / "It's Veronica's birthday today", in the given locale. */
    public function todayHeadline(?string $locale = null): string
    {
        $turning = $this->turning();
        $key = $turning !== null ? 'birthdays.today_turns' : 'birthdays.today_noage';

        return strtr(Translations::get($key, $locale), [':name' => $this->person->name, ':age' => (string) $turning]);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /** Current age in whole years, or null when the year is unknown. */
    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    /**
     * The age they reach on their next birthday, or null when the year is unknown. On the day itself
     * Carbon's age has already ticked over, so it IS the age (not age + 1): "turns 22" on the 19th, not 23.
     */
    public function turning(): ?int
    {
        $age = $this->age();
        if ($age === null) {
            return null;
        }

        return $this->daysUntil() === 0 ? $age : $age + 1;
    }

    /** Birth year, or null when unknown. */
    public function year(): ?int
    {
        return $this->birthdate?->year;
    }

    /** The next birthday on/after today (this year's, or next year's if it already passed). */
    public function nextBirthday(): ?Carbon
    {
        if (! $this->month || ! $this->day) {
            return null;
        }
        $today = Carbon::today();
        // setDate overflows Feb 29 to Mar 1 in a non-leap year — fine for our purposes.
        $next = Carbon::today()->setDate($today->year, $this->month, $this->day)->startOfDay();
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

    /**
     * Quick-add (Birthdays page + MCP birthdays_add): a name + day/month (+ optional year). The person is
     * the Contacts `person` primitive, created here so they show up in Contacts too; person.saved fires
     * so every extension sees the new person. If a contact with that exact name already exists, the
     * birthday attaches to them instead of creating a duplicate person (ponytail: exact
     * case-insensitive match; fuzzy matching can wait until someone actually asks). $request must
     * already be validated against BirthdaysController::rules() + name.
     */
    public static function addFor(string $name, Request $request, User $by): self
    {
        $person = Person::whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first()
            ?? Person::create(['name' => trim($name), 'created_by' => $by->id]);

        $birthday = self::updateOrCreate(['person_id' => $person->id], BirthdaysController::attributesFromRequest($request));
        // The listener in the provider also reacts to birth_month, harmlessly re-saving the same row.
        app(Hooks::class)->fire('person.saved', $person, $request);

        return $birthday->setRelation('person', $person);
    }

    /** Every birthday in the family with its person, soonest first (people deleted → row cascaded). */
    public static function soonestFirst(): Collection
    {
        return self::with('person')->get()
            ->filter(fn (self $b) => $b->person && $b->nextBirthday())
            ->sortBy([fn ($a, $b) => $a->daysUntil() <=> $b->daysUntil(), fn ($a, $b) => strcmp($a->person->name, $b->person->name)])
            ->values();
    }
}
