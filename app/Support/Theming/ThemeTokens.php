<?php

namespace App\Support\Theming;

// Reads a theme's theme.json and flattens its tokens to a :root CSS custom-property block.
// spacing/radius numbers are treated as pixels. This block is injected server-side (see ShareTheme),
// so swapping a theme is swapping this variable set, no asset rebuild.
class ThemeTokens
{
    public static function css(string $theme = 'default'): string
    {
        $data = json_decode(file_get_contents(resource_path("themes/{$theme}/theme.json")), true);

        $vars = [];
        foreach (($data['colors'] ?? []) as $k => $v) {
            $vars[] = "--{$k}:{$v}";
        }
        foreach (($data['fonts'] ?? []) as $k => $v) {
            $vars[] = "--font-{$k}:{$v}";
        }
        foreach (($data['spacing'] ?? []) as $k => $v) {
            $vars[] = "--spacing-{$k}:{$v}px";
        }
        foreach (($data['radius'] ?? []) as $k => $v) {
            $vars[] = "--radius-{$k}:{$v}px";
        }

        return ':root{'.implode(';', $vars).';}';
    }
}
