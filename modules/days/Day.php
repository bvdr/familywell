<?php

namespace Modules\Days;

use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\FamilyClock;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Contacts\Person;

/**
 * A day to remember (design board 10c): a wedding, moving in, the day someone passed away. Repeats every year on
 * month + day; the year is optional and only adds the count ("12 years"). The tone decides how it reads: celebrate
 * = warm sun card with the years and Send a wish, quiet = calm white card, no big number, gentle reminder wording.
 * The next date and the countdown are derived (like BirthdayDate), so "every year" needs no scheduler.
 * Shareable: "Who can see it" is the core sharing system (family / only me).
 */
class Day extends Model
{
    use BelongsToFamily;
    use Shareable;
    use SoftDeletes;

    public const TONES = ['celebrate', 'quiet'];

    protected $table = 'days_days';

    protected $fillable = ['family_id', 'created_by', 'title', 'tone', 'icon', 'month', 'day', 'year', 'note', 'person_ids', 'remind_days', 'reminded_on'];

    protected $casts = ['person_ids' => 'array', 'remind_days' => 'integer', 'year' => 'integer', 'reminded_on' => 'date'];

    /** Days on the given date. Feb 29 is kept on Mar 1 off leap years, the same rule as BirthdayDate::on(). */
    public function scopeOn(Builder $query, Carbon $date): Builder
    {
        return $query->where(function (Builder $q) use ($date) {
            $q->where(fn (Builder $q) => $q->where('month', $date->month)->where('day', $date->day));
            if ($date->month === 3 && $date->day === 1 && ! $date->isLeapYear()) {
                $q->orWhere(fn (Builder $q) => $q->where('month', 2)->where('day', 29));
            }
        });
    }

    /** The next occurrence on/after the family's today (setDate overflows Feb 29 to Mar 1, like birthdays). */
    public function next(): Carbon
    {
        $today = FamilyClock::date($this->family_id);
        $next = $today->copy()->setDate($today->year, $this->month, $this->day)->startOfDay();

        return $next->lt($today) ? $next->addYear() : $next;
    }

    /** Whole days until the next occurrence, 0 = today. */
    public function daysUntil(): int
    {
        return (int) FamilyClock::date($this->family_id)->diffInDays($this->next(), false);
    }

    /** "12 years" on the next occurrence (or today's), null without a year or before the first anniversary. */
    public function years(): ?int
    {
        $years = $this->year ? $this->next()->year - $this->year : null;

        return $years > 0 ? $years : null;
    }

    /** People linked to the day, with their first phone ("Call family"). */
    public function people()
    {
        return Person::with('contactMethods')->whereIn('id', $this->person_ids ?? [])->orderBy('name')->get();
    }

    /**
     * The push for a day $days ahead (design A6). Quiet stays gentle: "Grandpa Ion's day is in 3 days." Celebrate
     * reads "Ana & Dan's wedding: 12 years in a week." On the day: "… today".
     */
    public function reminderHeadline(int $days, ?string $locale = null): string
    {
        $when = match (true) {
            $days === 0 => Translations::get('days.when_today', $locale),
            $days === 1 => Translations::get('days.when_tomorrow', $locale),
            $days === 7 => Translations::get('days.when_week', $locale),
            $days % 7 === 0 => Translations::choice('days.when_weeks', intdiv($days, 7), $locale),
            default => Translations::choice('days.when_days', $days, $locale),
        };
        $years = $this->years();
        $key = match (true) {
            $this->tone === 'quiet' => 'days.remind_quiet',
            $years !== null => 'days.remind_years',
            default => 'days.remind_celebrate',
        };
        $count = $years !== null ? Translations::choice('days.n_years', $years, $locale) : '';

        return strtr(Translations::get($key, $locale), [':title' => $this->title, ':years' => $count, ':when' => $when]);
    }

    /** The row the page, Home and the editor read. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'tone' => $this->tone,
            'icon' => $this->icon,
            'month' => $this->month,
            'day' => $this->day,
            'year' => $this->year,
            'note' => $this->note,
            'personIds' => array_map('intval', $this->person_ids ?? []),
            'remindDays' => $this->remind_days,
            'next' => $this->next()->toDateString(),
            'daysUntil' => $this->daysUntil(),
            'years' => $this->years(),
            'visibility' => $this->visibility(),
        ];
    }
}
