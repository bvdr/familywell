<?php

namespace App\Support;

use App\Models\Family;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\FamilyClock;

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
 *
 * The time zone is the one core value (families.timezone, read by FamilyClock for every "which day is it"): core adds
 * it here directly instead of through a hook, since no module owns it.
 */
final class Region
{
    // Accepted zones: every IANA id PHP knows, old aliases included, because browsers still report some of them
    // (Chrome says "Asia/Calcutta" and "Europe/Kiev"). Laravel's `timezone` rule checks DateTimeZone::listIdentifiers.
    public const TIMEZONE_RULE = 'timezone:all_with_bc';

    // The zone a country's pick fills in when it has several; a one-zone country needs no entry, and one not listed
    // here falls back to its first zone (fine for the rest: CN Shanghai, DE Berlin, ID Jakarta, NZ Auckland...).
    // Picking a country never replaces a zone that's already in that country (the sheet keeps it).
    private const PRIMARY_ZONE = [
        'US' => 'America/New_York', 'CA' => 'America/Toronto', 'MX' => 'America/Mexico_City', 'BR' => 'America/Sao_Paulo',
        'CL' => 'America/Santiago', 'AU' => 'Australia/Sydney', 'RU' => 'Europe/Moscow', 'ES' => 'Europe/Madrid',
        'PT' => 'Europe/Lisbon', 'CY' => 'Asia/Nicosia', 'GL' => 'America/Nuuk', 'MN' => 'Asia/Ulaanbaatar',
        'UZ' => 'Asia/Tashkent', 'PF' => 'Pacific/Tahiti', 'FM' => 'Pacific/Pohnpei', 'KI' => 'Pacific/Tarawa',
        'MH' => 'Pacific/Majuro', 'PG' => 'Pacific/Port_Moresby',
    ];

    /** What a country implies, plus the option lists. Null country = the defaults (EUR, metric). */
    public static function derive(?string $country): array
    {
        $zones = $country ? \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $country) : [];
        $zone = self::PRIMARY_ZONE[$country] ?? $zones[0] ?? null;

        return [...app(Hooks::class)->filter('onboarding.region', [], $country), ...($zone ? ['timezone' => $zone] : [])];
    }

    /**
     * The zone picker's list: [zone id, country code] for every current IANA zone, so the sheet can tell whether the
     * zone already picked lies in a newly picked country. ~420 rows, only sent with the sheet.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function timezones(): array
    {
        return array_map(fn (string $id) => [$id, (new \DateTimeZone($id))->getLocation()['country_code'] ?? ''], \DateTimeZone::listIdentifiers());
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
        return [...app(Hooks::class)->filter('family.region', [], $familyId, $userId), 'timezone' => FamilyClock::stored($familyId)];
    }

    /** Callers validate `timezone` (Settings answers a bad one with a 422; onboarding just skips it, see store()). */
    public static function save(Family $family, array $input): void
    {
        if (filled($input['timezone'] ?? null)) {
            $family->forceFill(['timezone' => $input['timezone']])->save();
        }
        app(Hooks::class)->fire('family.region.save', $family, $input);
    }
}
