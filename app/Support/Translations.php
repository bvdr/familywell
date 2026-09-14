<?php

namespace App\Support;

// Loads the JSON translation files (lang/{locale}.json) and merges the current locale over English,
// so the map handed to the client always resolves every key (untranslated strings fall back to
// English). Namespaced/nested keys, e.g. nav.dashboard. English is the source + fallback.
class Translations
{
    /** Available locales: code => native label. Add a language by adding a lang/{code}.json + a row here. */
    public const LOCALES = ['en' => 'English', 'ro' => 'Română'];

    /** @return array<string, mixed> full nested map for $locale, English-filled. */
    public static function for(string $locale): array
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
    private static function all(string $locale): array
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

    /** @return array<int, array{code: string, label: string}> for the language picker. */
    public static function options(): array
    {
        return collect(self::LOCALES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all();
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
