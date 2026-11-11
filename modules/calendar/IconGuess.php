<?php

namespace Modules\Calendar;

use Illuminate\Support\Str;

/**
 * Guesses icons for an event from its title, so nobody has to pick one ("Parastas" -> church). The words live in
 * icon-guess.json, which resources/js/lib/icon-guess.ts reads too: the picker's SUGGESTED row and the icon the
 * items/MCP/ICS carry come from the same table. PHP twin of that file; both are checked against
 * tests/fixtures/calendar-icon-guess.json. Change one, change both.
 */
final class IconGuess
{
    public const MAX = 5;

    /** @var list<array{exact: array<string, true>, prefix: list<string>, icons: list<string>}>|null */
    private static ?array $groups = null;

    /**
     * Lower-case ASCII of one word: the same fold as foldWord() in icon-guess.ts (NFD without marks, plus
     * the few letters NFD leaves alone). Str::ascii agrees with it for every letter of the 9 app locales.
     */
    public static function fold(string $word): string
    {
        return Str::ascii(mb_strtolower($word));
    }

    /**
     * Up to MAX icons for a title, best first, and the title word that triggered the first match (shown as
     * "Suggested for PARASTAS"). Empty icons = no good guess.
     *
     * @return array{word: string|null, icons: list<string>}
     */
    public static function guess(string $title): array
    {
        $icons = [];
        $word = null;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $folded = self::fold($token);
            foreach (self::groups() as $g) {
                if (isset($g['exact'][$folded]) || self::startsWithAny($folded, $g['prefix'])) {
                    $word ??= $token;
                    $icons = [...$icons, ...array_diff($g['icons'], $icons)];
                    break; // first matching group per word, like icon-guess.ts
                }
            }
            if (count($icons) >= self::MAX) {
                break;
            }
        }

        return ['word' => $word, 'icons' => array_slice($icons, 0, self::MAX)];
    }

    /** The one icon an event with no picked icon shows, or null. */
    public static function first(string $title): ?string
    {
        return self::guess($title)['icons'][0] ?? null;
    }

    private static function startsWithAny(string $word, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($word, $p)) {
                return true;
            }
        }

        return false;
    }

    /** The JSON, split once per process into exact ("word$") and prefix words. */
    private static function groups(): array
    {
        return self::$groups ??= array_map(function (array $g) {
            $exact = [];
            $prefix = [];
            foreach ($g['words'] as $w) {
                str_ends_with($w, '$') ? $exact[substr($w, 0, -1)] = true : $prefix[] = $w;
            }

            return ['exact' => $exact, 'prefix' => $prefix, 'icons' => $g['icons']];
        }, json_decode(file_get_contents(__DIR__.'/icon-guess.json'), true)['groups']);
    }
}
