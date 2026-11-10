<?php

namespace Modules\Birthdays;

use App\Models\User;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
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

    protected $fillable = ['person_id', 'month', 'day', 'birthdate', 'family_id', 'notified_on', 'remind_days', 'reminded_on'];

    protected $casts = ['birthdate' => 'date', 'notified_on' => 'date', 'reminded_on' => 'date', 'remind_days' => 'integer'];

    /**
     * Birthdays falling on the given day (default today). A Feb 29 birthday is celebrated on Mar 1
     * in non-leap years, matching what nextBirthday() derives, so the card and the push agree with
     * the list's "Today!". Default: the current family's today (FamilyClock), not the server's.
     */
    public function scopeOn(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= FamilyClock::date();

        // One group, so the Feb 29 OR can never swallow the filters chained after on() (family, reminder ledger).
        return $query->where(function (Builder $q) use ($date) {
            $q->where(fn (Builder $q) => $q->where('month', $date->month)->where('day', $date->day));
            if ($date->month === 3 && $date->day === 1 && ! $date->isLeapYear()) {
                $q->orWhere(fn (Builder $q) => $q->where('month', 2)->where('day', 29));
            }
        });
    }

    /** "Veronica turns 22 today" / "It's Veronica's birthday today", in the given locale. */
    public function todayHeadline(?string $locale = null): string
    {
        $turning = $this->turning();
        $key = $turning !== null ? 'birthdays.today_turns' : 'birthdays.today_noage';

        return strtr(Translations::get($key, $locale), [':name' => $this->person->name, ':age' => (string) $turning]);
    }

    /**
     * The advance reminder (design 10b M12): "Lucia turns 12 in a week" / "Dana's birthday is in 3 days", for a
     * birthday $days ahead. On the reminder's day the birthday is still ahead, so turning() is the age they reach.
     */
    public function reminderHeadline(int $days, ?string $locale = null): string
    {
        $when = match (true) {
            $days === 1 => Translations::get('birthdays.when_tomorrow', $locale),
            $days === 7 => Translations::get('birthdays.when_week', $locale),
            $days % 7 === 0 => Translations::choice('birthdays.when_weeks', intdiv($days, 7), $locale),
            default => Translations::choice('birthdays.when_days', $days, $locale),
        };
        $turning = $this->turning();
        $key = $turning !== null ? 'birthdays.remind_turns' : 'birthdays.remind_noage';

        return strtr(Translations::get($key, $locale), [':name' => $this->person->name, ':age' => (string) $turning, ':when' => $when]);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Current age in whole years, or null when the year is unknown. Counted to the family's today, not Carbon's
     * ->age (UTC now): just after midnight in Bucharest it's already the birthday, and turning() must agree.
     */
    public function age(): ?int
    {
        if (! $this->birthdate) {
            return null;
        }
        // Integer math, not Carbon's diffInYears: that cost ~0.2ms a call, ~140ms for the demo's 366 birthdays on
        // every Birthdays and Contacts load (age() runs twice a row via turning()). Same answer: the year gap, minus
        // one while this year's birthday is still ahead (a Feb 29 birthday ticks over on Mar 1, as Carbon did).
        $today = FamilyClock::date($this->family_id);
        $born = $this->birthdate;
        $notYet = [$today->month, $today->day] < [$born->month, $born->day];

        return $today->year - $born->year - ($notYet ? 1 : 0);
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
     * Plain integers (gmmktime day numbers), not Carbon: copy/setDate/diffInDays was ~0.1ms a row, the bulk of what
     * was left of Home and Birthdays after the sort fix. The Carbon is only built when someone asks (nextBirthday()).
     *
     * @var array{0: string, 1: ?int, 2: ?int}|null [today, next birthday as a UTC timestamp, days until]
     */
    private ?array $upcoming = null;

    // A new date (setFor) must not keep serving the old countdown.
    protected static function booted(): void
    {
        static::saved(fn (self $b) => $b->upcoming = null);
    }

    /** @return array{0: string, 1: ?int, 2: ?int} */
    private function upcoming(): array
    {
        $today = FamilyClock::date($this->family_id); // the family's day, so "Today!" flips at their midnight
        $key = $today->toDateString();
        if ($this->upcoming === null || $this->upcoming[0] !== $key) {
            $next = $days = null;
            if ($this->month && $this->day) {
                // gmmktime overflows Feb 29 to Mar 1 in a non-leap year, as Carbon's setDate did.
                $now = gmmktime(0, 0, 0, $today->month, $today->day, $today->year);
                $next = gmmktime(0, 0, 0, $this->month, $this->day, $today->year);
                if ($next < $now) {
                    $next = gmmktime(0, 0, 0, $this->month, $this->day, $today->year + 1);
                }
                $days = intdiv($next - $now, 86400);
            }
            $this->upcoming = [$key, $next, $days];
        }

        return $this->upcoming;
    }

    /** The next birthday on/after today (this year's, or next year's if it already passed). */
    public function nextBirthday(): ?Carbon
    {
        $next = $this->upcoming()[1];

        // Midnight in the app zone, the same shape as before (and as a `date` cast column).
        return $next === null ? null : Carbon::createFromFormat('!Y-m-d', gmdate('Y-m-d', $next));
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

    /**
     * Every birthday of the current family with its person, loaded ONCE per request: Home asked three times (the card,
     * the More/Home status line, the calendar agenda) and Contacts once per person. The memo sits on the request's
     * attribute bag (like ModuleRegistry), so it dies with the request under Octane; keyed by family. Shared models
     * also share their worked-out countdown (upcoming()). Keyed by person_id.
     * ponytail: in console the bound request lives for the whole process; nothing queued or scheduled reads this
     * (NotifyBirthdays queries on() itself). A write later in the same request isn't seen; no caller reads after writing.
     */
    public static function allWithPeople(): Collection
    {
        $bag = app('request')->attributes;
        $key = 'birthdays.all.'.CurrentFamily::id();
        if (! $bag->has($key)) {
            $bag->set($key, self::with('person')->get()->keyBy('person_id')); // one row per person (setFor)
        }

        return $bag->get($key);
    }

    /** Every birthday in the family with its person, soonest first (people deleted → row cascaded). */
    public static function soonestFirst(): Collection
    {
        return self::allWithPeople()
            ->filter(fn (self $b) => $b->person && $b->daysUntil() !== null)
            // A key per row (days, then name), not a two-comparator sort: comparators re-ran daysUntil() ~3000 times for
            // 366 rows, and each call re-checks the family clock (~55ms of Home and Birthdays).
            ->sortBy(fn (self $b) => [$b->daysUntil(), $b->person->name])
            ->values();
    }
}
