<?php

namespace App\Support\Theming;

// Reads a theme's theme.json and flattens it to CSS custom-property blocks. A theme owns the FULL
// token set (shadcn tokens like --primary/--background/--radius AND the brand DS tokens like
// --surface-card/--accent-subtle), so swapping a theme reskins the whole app with no asset rebuild.
//
// This block is injected server-side AFTER the compiled stylesheet (see app.blade.php), so it wins
// the cascade for every token it defines — that's what lets a theme override the base look. `tokens`
// -> :root, `tokensDark` -> .dark (dark-mode overrides), `fonts` -> --font-{sans,heading,mono}.
class ThemeTokens
{
    public static function css(string $theme = 'default'): string
    {
        $path = resource_path("themes/{$theme}/theme.json");
        // Unknown theme name -> fall back to default rather than 500 the whole app.
        if (! is_file($path)) {
            $path = resource_path('themes/default/theme.json');
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

    /** Theme names available to pick (each is a resources/themes/<name>/theme.json). */
    public static function available(): array
    {
        return collect(glob(resource_path('themes/*/theme.json')))
            ->map(fn ($p) => [
                'id' => basename(dirname($p)),
                'name' => json_decode(file_get_contents($p), true)['name'] ?? basename(dirname($p)),
            ])
            ->sortBy('id')->values()->all();
    }
}
