<?php

namespace App\Support;

use App\Models\Family;
use App\Support\Modules\Hooks;

/**
 * The family's "Region & units" (units, currency, ...), shared by onboarding step 1 and Settings › Region & units.
 * Core stores none of it: each value lives in the module that owns it (Vehicles' unit_system, Spending's
 * default currency), and modules join through three hooks, the same way `family.created` works:
 *
 *  - `onboarding.region` (filter: array, ?country): what a country implies (RO -> RON, metric) plus any option
 *    lists (`currencies`). Feeds the onboarding guess and the sheet's country picker.
 *  - `family.region` (filter: array, familyId, userId|null): the family's current values; with a user id also
 *    `samples` for the sheet's "How it looks" preview (real numbers from that person's modules).
 *  - `family.region.save` (action: Family, input): store a change. Each module validates its own keys and
 *    ignores what it doesn't own or doesn't accept (same as `family.created` always did).
 *
 * The country itself isn't stored: it only fills in the rest (decided 2026-10-29, nothing reads a family country).
 */
final class Region
{
    /** What a country implies, plus the option lists. Null country = the defaults (EUR, metric). */
    public static function derive(?string $country): array
    {
        return app(Hooks::class)->filter('onboarding.region', [], $country);
    }

    /**
     * Every country ICU has a locale for (~250, so there's no list to maintain), named in the app language and
     * sorted by that language's alphabet, each with the scalar values it implies: [{code, name, currency, units}].
     *
     * @return list<array<string, mixed>>
     */
    public static function countries(): array
    {
        $codes = [];
        foreach (\ResourceBundle::getLocales('') as $locale) {
            $code = \Locale::getRegion($locale);
            if (strlen($code) === 2 && ctype_alpha($code)) {
                $codes[$code] = true;
            }
        }
        $locale = app()->getLocale();
        $rows = [];
        foreach (array_keys($codes) as $code) {
            // Option lists (Spending's `currencies`) are the same for every country: keep only the scalar values.
            $derived = array_filter(self::derive($code), fn ($v) => is_scalar($v));
            $rows[] = ['code' => $code, 'name' => \Locale::getDisplayRegion('-'.$code, $locale), ...$derived];
        }
        $collator = new \Collator($locale);
        usort($rows, fn ($a, $b) => $collator->compare($a['name'], $b['name']));

        return $rows;
    }

    /** The family's current values; pass the viewer to also get the preview `samples`. */
    public static function current(int $familyId, ?int $userId = null): array
    {
        return app(Hooks::class)->filter('family.region', [], $familyId, $userId);
    }

    public static function save(Family $family, array $input): void
    {
        app(Hooks::class)->fire('family.region.save', $family, $input);
    }
}
