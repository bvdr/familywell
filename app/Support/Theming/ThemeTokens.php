<?php

namespace App\Support\Theming;

use App\Models\Family;
use App\Models\User;

// Reads a theme's theme.json and flattens it to CSS custom-property blocks. A theme owns the FULL
// token set (shadcn tokens like --primary/--background/--radius AND the brand DS tokens like
// --surface-card/--accent-subtle), so swapping a theme reskins the whole app with no asset rebuild.
//
// This block is injected server-side AFTER the compiled stylesheet (see app.blade.php), so it wins
// the cascade for every token it defines — that's what lets a theme override the base look. `tokens`
// -> :root, `tokensDark` -> .dark (dark-mode overrides), `fonts` -> --font-{sans,heading,mono}.
class ThemeTokens
{
    /**
     * The skin a person sees: their personal choice wins, else the family's default, else the built-in
     * default (minimal-mono; css() also falls back to it for an unknown name). The family is only queried
     * when the person has no personal skin. Shared by the web app (ShareTheme) and the MCP widgets.
     */
    public static function resolve(?User $user, ?int $familyId): string
    {
        $theme = $user?->theme;
        if (! $theme && $familyId) {
            $theme = Family::find($familyId)?->theme;
        }

        return $theme ?: 'minimal-mono';
    }

    public static function css(string $theme = 'minimal-mono'): string
    {
        $path = resource_path("themes/{$theme}/theme.json");
        // Unknown theme name -> fall back to the built-in default (minimal-mono) rather than 500 the app.
        if (! is_file($path)) {
            $path = resource_path('themes/minimal-mono/theme.json');
        }
        $data = json_decode(file_get_contents($path), true);

        $vars = [];
        foreach (($data['tokens'] ?? []) as $k => $v) {
            $vars[] = "--{$k}:{$v}";
        }
        foreach (($data['fonts'] ?? []) as $k => $v) {
            $vars[] = "--font-{$k}:{$v}";
        }
        $css = ':root{'.implode(';', $vars).';}';

        // Dark mode overrides only the tokens the theme lists under tokensDark.
        $dark = [];
        foreach (($data['tokensDark'] ?? []) as $k => $v) {
            $dark[] = "--{$k}:{$v}";
        }
        if ($dark) {
            $css .= '.dark{'.implode(';', $dark).';}';
        }

        return $css;
    }

    /**
     * Themes available to pick, each with a few colours for the picker's preview swatch.
     * Each is a resources/themes/<name>/theme.json.
     */
    public static function available(): array
    {
        return collect(glob(resource_path('themes/*/theme.json')))
            ->map(function ($p) {
                $data = json_decode(file_get_contents($p), true);
                $t = $data['tokens'] ?? [];

                return [
                    'id' => basename(dirname($p)),
                    'name' => $data['name'] ?? basename(dirname($p)),
                    // Swatch: page ground, a card, the action accent, and the ink — enough to read the skin.
                    'swatch' => self::swatch($t),
                    // The same four in dark mode (dark tokens over light), for previews that follow the
                    // Light / Dark choice (the onboarding's About you card).
                    'swatchDark' => self::swatch([...$t, ...($data['tokensDark'] ?? [])]),
                    // "NEW" badge on a freshly added skin ("badge": "new" in its theme.json).
                    'badge' => $data['badge'] ?? null,
                ];
            })
            ->sortBy('id')->values()->all();
    }

    /** @return array{bg: string, card: string, accent: string, ink: string} */
    private static function swatch(array $t): array
    {
        return [
            'bg' => $t['background'] ?? '#fff',
            'card' => $t['card'] ?? '#fff',
            'accent' => $t['accent-strong'] ?? ($t['primary'] ?? '#000'),
            'ink' => $t['foreground'] ?? '#000',
        ];
    }
}
