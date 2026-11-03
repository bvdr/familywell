<?php

namespace Modules\Shopping;

use Illuminate\Support\Facades\DB;

/**
 * Shop name -> known brand (resources/shop-brands.json), so a shop gets its logo the moment it's added, with no
 * network call: logos ship in public/brands/<slug>.png (scripts/fetch-brand-logos.mjs). Tested 2026-11-03 against
 * guessing the website online: that took 0.3-3 s a name, accepted parked "for sale" domains and missed every name
 * with an extra word; this matches 54/61 messy names in under a millisecond and leaves local shops alone.
 *
 * Cleaned name (no accents, case, punctuation), generic words dropped ("market", "express", "romania"...), then:
 * the longest leading run of words that is a brand or alias ("Kaufland Pipera" -> Kaufland, "mega" -> Mega Image),
 * else one typo for names of 5+ letters ("Carefour"). A brand sold in the family's country wins a name clash
 * (Jumbo is a toy shop in Romania, a supermarket in the Netherlands).
 */
class ShopBrands
{
    private const GENERIC = ['market', 'express', 'city', 'romania', 'ro', 'hypermarket', 'hiper', 'supermarket', 'super', 'magazin',
        'store', 'shop', 'online', 'srl', 'sa', 'drive', 'extra'];

    /** @return list<array{slug: string, name: string, domain: string, countries: list<string>, aliases: list<string>, logo: bool}> */
    public static function all(): array
    {
        static $all;

        return $all ??= json_decode(file_get_contents(resource_path('shop-brands.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The brand slug for a typed shop name, or null (a local shop: the letter tile). */
    public static function match(string $name, ?string $country = null): ?string
    {
        $words = array_values(array_filter(explode(' ', self::clean($name)), fn ($w) => $w !== '' && ! in_array($w, self::GENERIC, true)));
        if ($words === []) {
            return null;
        }
        $keys = self::keys($country);
        for ($n = count($words); $n >= 1; $n--) {
            $key = implode('', array_slice($words, 0, $n));
            if (isset($keys[$key])) {
                return $keys[$key];
            }
        }
        $key = implode('', $words);
        if (strlen($key) >= 5) {
            foreach ($keys as $k => $slug) {
                if (strlen($k) >= 5 && levenshtein($k, $key) <= 1) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /** The family's country, from its timezone (the country itself isn't stored, see App\Support\Region). */
    public static function countryOf(?string $timezone): ?string
    {
        try {
            return $timezone ? ((new \DateTimeZone($timezone))->getLocation()['country_code'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A name squashed for matching: "La Doi Pași" -> "ladoipasi". */
    public static function key(string $name): string
    {
        return str_replace(' ', '', self::clean($name));
    }

    /** Brands the weekly sweep found (FindShopLogos): squashed name => slug. Read once per process. */
    private static function finds(): array
    {
        return self::$finds ??= rescue(fn () => DB::table('shopping_brand_finds')->where('status', 'found')->pluck('slug', 'key')->all(), [], false);
    }

    private static ?array $finds = null;

    private static array $byCountry = [];

    public static function forgetFinds(): void
    {
        self::$finds = null;
        self::$byCountry = [];
    }

    /** squashed name/alias => slug; brands sold in $country first so they win a clash, then the sweep's finds. */
    private static function keys(?string $country): array
    {
        return self::$byCountry[$country ?? ''] ??= (function () use ($country) {
            $brands = self::all();
            usort($brands, fn ($a, $b) => self::sells($b, $country) <=> self::sells($a, $country));
            $keys = [];
            foreach ($brands as $b) {
                foreach ([$b['name'], ...$b['aliases']] as $a) {
                    $keys[str_replace(' ', '', self::clean($a))] ??= $b['slug'];
                }
            }

            return $keys + self::finds();
        })();
    }

    private static function sells(array $brand, ?string $country): int
    {
        return $country && in_array($country, $brand['countries'], true) ? 2 : (in_array('*', $brand['countries'], true) ? 1 : 0);
    }

    private static function clean(string $s): string
    {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s);
        $s = mb_strtolower(str_replace(['&', '’', "'"], [' ', '', ''], $s));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /** The logo URL for a brand slug, or null when it has none (a letter tile then). */
    public static function logo(?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }
        // A sweep find: stored on R2, served by shopping.brand-logo with a year's cache.
        if (str_starts_with($slug, 'x-')) {
            return route('shopping.brand-logo', $slug);
        }
        foreach (self::all() as $b) {
            if ($b['slug'] === $slug) {
                return $b['logo'] ? "/brands/{$slug}.png" : null;
            }
        }

        return null;
    }
}
