<?php

namespace Modules\Birthdays;

use App\Models\User;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    use SoftDeletes; // 30-day trash (BirthdaysTrash, trash:purge); write through setFor(), see there

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

    /**
     * [today, next birthday, days until] for this row, worked out once per day. soonestFirst() sorts on daysUntil()
     * and its callers filter on it again: uncached, that was O(n log n) Carbon work per call, ~450ms for the demo's
     * 366 birthdays on every Home load (and Home is prefetched from every page). Keyed by today's date so a model
     * that outlives midnight never serves yesterday's countdown.
     *
     * @var array{0: string, 1: ?Carbon, 2: ?int}|null
     */
    private ?array $upcoming = null;

    // A new date (setFor) must not keep serving the old countdown.
    protected static function booted(): void
    {
        static::saved(fn (self $b) => $b->upcoming = null);
    }

    /** @return array{0: string, 1: ?Carbon, 2: ?int} */
    private function upcoming(): array
    {
        $today = Carbon::today();
        if ($this->upcoming === null || $this->upcoming[0] !== $today->toDateString()) {
            $next = null;
            if ($this->month && $this->day) {
                // setDate overflows Feb 29 to Mar 1 in a non-leap year — fine for our purposes.
                $next = $today->copy()->setDate($today->year, $this->month, $this->day)->startOfDay();
                if ($next->lt($today)) {
                    $next->addYear();
                }
            }
            $this->upcoming = [$today->toDateString(), $next, $next ? (int) $today->diffInDays($next, false) : null];
        }

        return $this->upcoming;
    }

    /** The next birthday on/after today (this year's, or next year's if it already passed). */
    public function nextBirthday(): ?Carbon
    {
        return $this->upcoming()[1]?->copy();
    }

    /** Whole days until the next birthday (0 = today), or null if no date. */
    public function daysUntil(): ?int
    {
        return $this->upcoming()[2];
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

        $birthday = self::setFor($person->id, BirthdaysController::attributesFromRequest($request));
        // The listener in the provider also reacts to birth_month, harmlessly re-saving the same row.
        app(Hooks::class)->fire('person.saved', $person, $request);

        return $birthday->setRelation('person', $person);
    }

    /**
     * Set a person's birthday. person_id is unique and a trashed birthday still holds it, so a new date
     * revives the trashed row (with the new date) instead of inserting a second one.
     */
    public static function setFor(int $personId, array $attrs): self
    {
        $birthday = self::withTrashed()->updateOrCreate(['person_id' => $personId], $attrs);
        if ($birthday->trashed()) {
            $birthday->restore();
        }

        return $birthday;
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
