<?php

namespace Modules\Birthdays\Mcp;

use Illuminate\Http\Request;
use Modules\Birthdays\BirthdayDate;
use Modules\Birthdays\BirthdaysController;

// Shared lookups + payload shapes for the Birthdays MCP tools. Birthdays are family-wide (no sharing),
// so the BelongsToFamily scope under CurrentFamily IS the access rule.
class BirthdayData
{
    public static function birthday(mixed $id): ?BirthdayDate
    {
        $b = BirthdayDate::with('person')->find((int) $id);

        return $b?->person ? $b : null;
    }

    public static function payload(BirthdayDate $b): array
    {
        return [
            'id' => $b->id,
            'person_id' => $b->person_id,
            'name' => $b->person->name,
            'month' => $b->month,
            'day' => $b->day,
            'year' => $b->year(),                                  // null when unknown
            'next_birthday' => $b->nextBirthday()?->toDateString(),
            'days_until' => $b->daysUntil(),                       // 0 = today
            'age' => $b->age(),                                    // age today, null without a year
            'turning' => $b->turning(),                            // age on the next birthday
            // The Birthdays page has no per-birthday route; link to the list.
            'url' => rtrim((string) config('app.url'), '/').'/m/birthdays',
        ];
    }

    /** Tool args use the web form's field names, so BirthdaysController::rules() applies unchanged. */
    public static function schema($schema): array
    {
        return [
            'birth_month' => $schema->integer()->description('1-12.')->required(),
            'birth_day' => $schema->integer()->description('1-31 (clamped to the month, e.g. Feb 30 -> Feb 29).')->required(),
            'birth_year' => $schema->integer()->description('Optional; unlocks age and "turns N".'),
        ];
    }

    /**
     * BirthdayDate::addFor / attributesFromRequest and the person.saved hook read an HTTP request; an
     * MCP call has none, so build one from the validated arguments (same fields as the web form).
     */
    public static function request(array $data): Request
    {
        return Request::create('/', 'POST', $data);
    }

    public static function rules(array $extra): array
    {
        return $extra + BirthdaysController::rules();
    }
}
