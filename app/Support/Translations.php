<?php

namespace App\Support;

use Illuminate\Http\Request;

// Loads the JSON translation files (lang/{locale}.json) and merges the current locale over English,
// so the map handed to the client always resolves every key (untranslated strings fall back to
// English). Namespaced/nested keys, e.g. nav.dashboard. English is the source + fallback.
class Translations
{
    /** Available locales: code => native label. Add a language by adding a lang/{code}.json + a row here. */
    // `pt` is Brazilian Portuguese: Intl/ICU treat plain `pt` as Brazil (dates, numbers, plurals), and a
    // bare code lets Symfony match both pt-BR and pt-PT browsers. Rename to pt-BR only if pt-PT ever ships.
    public const LOCALES = ['en' => 'English', 'ro' => 'Română', 'de' => 'Deutsch', 'fr' => 'Français', 'nl' => 'Nederlands', 'sv' => 'Svenska', 'nb' => 'Norsk', 'pt' => 'Português (Brasil)', 'es' => 'Español'];

    /** The English name of each language, shown beside its own name in About you's language list. */
    public const ENGLISH = ['en' => 'English', 'ro' => 'Romanian', 'de' => 'German', 'fr' => 'French', 'nl' => 'Dutch', 'sv' => 'Swedish', 'nb' => 'Norwegian', 'pt' => 'Portuguese (Brazil)', 'es' => 'Spanish'];

    /**
     * Which of our languages are spoken in a country (ISO code, from Cloudflare's CF-IPCountry), for "Near you" in About
     * you. Only countries where a language we ship is spoken, and not every one: a country that speaks English
     * alone is left out (the browser row already covers it), and so are the long tail of Spanish / Portuguese
     * speaking ones beyond the main ones. Belgium lists three on purpose (nl, fr, de).
     */
    private const SPOKEN_IN = [
        'RO' => ['ro'], 'MD' => ['ro'],
        'DE' => ['de'], 'AT' => ['de'], 'LI' => ['de'], 'CH' => ['de', 'fr'], 'LU' => ['fr', 'de'], 'BE' => ['nl', 'fr', 'de'],
        'FR' => ['fr'], 'MC' => ['fr'],
        'NL' => ['nl'], 'SR' => ['nl'],
        'SE' => ['sv'], 'FI' => ['sv'],
        'NO' => ['nb'],
        'PT' => ['pt'], 'BR' => ['pt'], 'AO' => ['pt'], 'MZ' => ['pt'],
        'ES' => ['es'], 'MX' => ['es'], 'AR' => ['es'], 'CO' => ['es'], 'CL' => ['es'], 'PE' => ['es'], 'VE' => ['es'], 'EC' => ['es'], 'UY' => ['es'], 'BO' => ['es'], 'PY' => ['es'], 'CU' => ['es'], 'DO' => ['es'], 'GT' => ['es'], 'HN' => ['es'], 'SV' => ['es'], 'NI' => ['es'], 'CR' => ['es'], 'PA' => ['es'],
    ];

    /** @return list<string> shipped languages spoken in $country (null / unknown = none). */
    public static function spokenIn(?string $country): array
    {
        return self::SPOKEN_IN[strtoupper((string) $country)] ?? [];
    }

    /**
     * The language the visitor's browser asks for, as one of ours (English when none matches). Symfony matches "fr-BE"
     * to "fr" and returns the first locale (English) when nothing matches. Norwegian: we ship Bokmål as `nb`, but
     * many browsers send the macro code `no` (or Nynorsk `nn`), which Symfony wouldn't match to `nb`. Offer those
     * too and fold them into nb. Shared by SetLocale (the starting language) and About you's "From your browser".
     */
    public static function fromBrowser(Request $request): string
    {
        $locale = $request->getPreferredLanguage([...array_keys(self::LOCALES), 'no', 'nn']);

        return in_array($locale, ['no', 'nn'], true) ? 'nb' : $locale;
    }

    /** The browser's own first language tag ("en-GB"), for "Your browser · en-GB"; falls back to our code when there's none. */
    public static function browserTag(Request $request, string $fallback): string
    {
        $tag = trim(explode(',', explode(';', (string) $request->header('Accept-Language'))[0])[0]);

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag) ? $tag : $fallback;
    }

    /** @return array<string, mixed> full nested map for $locale, English-filled. */
    public static function for(string $locale): array
    {
        // Memoised per process: the lang files only change on deploy, and server-side callers (Translations::get,
        // e.g. once per aisle) would otherwise re-read and re-merge every JSON file on each call.
        static $cache = [];

        return $cache[$locale] ??= self::build($locale);
    }

    /** @return array<string, mixed> */
    private static function build(string $locale): array
    {
        $en = self::all('en');

        return $locale === 'en' ? $en : array_replace_recursive($en, self::all($locale));
    }

    /**
     * The whole map for a locale: the base lang/{locale}.json (nav/common/dashboard/...) plus each
     * per-module group file lang/{locale}/{group}.json merged under its filename namespace (so a
     * `lang/en/shopping.json` of {"add_item": "..."} resolves as `shopping.add_item`). Splitting by
     * module keeps files small and lets features own their strings without touching a shared file.
     *
     * @return array<string, mixed>
     */
    public static function all(string $locale): array
    {
        $map = self::load($locale);
        $dir = lang_path($locale);
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.json') as $file) {
                $ns = pathinfo($file, PATHINFO_FILENAME);
                $map[$ns] = array_replace_recursive($map[$ns] ?? [], json_decode((string) file_get_contents($file), true) ?: []);
            }
        }

        return $map;
    }

    /**
     * One string by dotted key (e.g. 'spending.others_list') for SERVER-side use, English-filled. Laravel's
     * __() can't reach these: it only resolves dotted keys against PHP lang files, and JSON keys only as
     * flat lang/{locale}.json entries — so a namespaced module string used in PHP has to come through here.
     */
    public static function get(string $key, ?string $locale = null): string
    {
        $value = data_get(self::for($locale ?? app()->getLocale()), $key);

        return is_string($value) ? $value : $key;
    }

    /**
     * A counted string ("1 item" / "5 items") for SERVER-side use, with :count filled in. Same storage as the
     * frontend t(): `key_one` / `key_few` / `key_other`, picked with ICU's plural rules for the locale (via
     * intl's MessageFormatter, which already powers VehicleExportFormat), falling back to `key_other`.
     */
    public static function choice(string $key, int $count, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $category = \MessageFormatter::formatMessage($locale, '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}', ['n' => $count]) ?: 'other';
        $map = self::for($locale);
        $value = data_get($map, "{$key}_{$category}") ?? data_get($map, "{$key}_other") ?? $key;

        return str_replace(':count', (string) $count, $value);
    }

    /** @return array<int, array{code: string, label: string, english: string}> for the language picker. */
    public static function options(): array
    {
        return collect(self::LOCALES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'english' => self::ENGLISH[$code] ?? $label])->values()->all();
    }

    public static function supported(string $locale): bool
    {
        return array_key_exists($locale, self::LOCALES);
    }

    /** @return array<string, mixed> */
    private static function load(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }
}
