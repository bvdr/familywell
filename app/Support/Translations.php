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
        $en = self::load('en');

        return $locale === 'en' ? $en : array_replace_recursive($en, self::load($locale));
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
