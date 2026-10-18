<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Theming\ThemeTokens;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('emits the default theme tokens as CSS custom properties', function () {
    $css = ThemeTokens::css('default');

    expect($css)->toContain(':root{')
        // Core shadcn tokens the skin now owns (were hard-coded in app.css before).
        ->toContain('--primary:hsl(135.9, 35.3%, 27.3%)')
        ->toContain('--background:hsl(42.9, 25.9%, 94.7%)')
        ->toContain('--radius:0.75rem')
        // Brand DS tokens + fonts.
        ->toContain('--surface-card:#FFFFFF')
        ->toContain('--text-primary:#1B3A28')
        ->toContain("--font-heading:'Playfair Display'")
        // Dark mode flips both the shadcn and brand tokens app.css no longer redefines.
        ->toContain('.dark{')
        ->toContain('--surface-card:#1F3B2A')
        ->toContain('--text-secondary:#A6BFAE');
});

it('falls back to the built-in default (minimal-mono) for an unknown name', function () {
    expect(ThemeTokens::css('does-not-exist'))->toBe(ThemeTokens::css('minimal-mono'));
});

it('injects the theme variables into the served document', function () {
    // Unauthenticated /login has no user/family skin, so it serves the built-in default (minimal-mono).
    get('/login')
        ->assertOk()
        ->assertSee('id="theme"', false)
        ->assertSee('--primary:#1C1917', false);
});

it('resolves the skin as user override, then family default, then built-in', function () {
    $family = Family::factory()->create(['theme' => 'minimal-mono']);
    $user = User::factory()->create(['theme' => null]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    // No personal skin -> the family default is served.
    actingAs($user)->get('/dashboard')->assertSee('--accent-strong:#F59E0B', false);

    // Personal skin overrides the family default.
    $user->update(['theme' => 'default']);
    actingAs($user)->get('/dashboard')->assertSee('--primary:hsl(135.9, 35.3%, 27.3%)', false);
});

it('lets a member set and clear their personal skin', function () {
    $user = User::factory()->create(['theme' => null]);

    actingAs($user)->patch('/settings/appearance/theme', ['theme' => 'minimal-mono'])->assertRedirect();
    expect($user->fresh()->theme)->toBe('minimal-mono');

    // null clears it (back to inheriting the family default).
    actingAs($user)->patch('/settings/appearance/theme', ['theme' => null])->assertRedirect();
    expect($user->fresh()->theme)->toBeNull();

    // Unknown skins are rejected.
    actingAs($user)->patch('/settings/appearance/theme', ['theme' => 'nope'])->assertSessionHasErrors('theme');
});

it('lets an admin set the family skin but forbids a member', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id, 'theme' => null]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);

    session(['active_family_id' => $family->id]);
    actingAs($admin)->patch('/family/theme', ['theme' => 'minimal-mono'])->assertRedirect();
    expect($family->fresh()->theme)->toBe('minimal-mono');

    session(['active_family_id' => $family->id]);
    actingAs($member)->patch('/family/theme', ['theme' => 'default'])->assertForbidden();
});

it('hands the skin pickers the skin CSS in effect, so a pick applies without a reload', function () {
    $user = User::factory()->create(['theme' => 'warm']);
    $family = Family::factory()->create(['owner_id' => $user->id, 'theme' => 'minimal-mono']);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    actingAs($user)->get('/settings/appearance')->assertInertia(fn ($page) => $page->where('themeCss', ThemeTokens::css('warm')));
    actingAs($user)->get('/family/members')->assertInertia(fn ($page) => $page->where('themeCss', ThemeTokens::css('warm')));

    $user->update(['theme' => null]); // back to the family default
    actingAs($user)->get('/settings/appearance')->assertInertia(fn ($page) => $page->where('themeCss', ThemeTokens::css('minimal-mono')));
});

// The "inverted" cards (spending Total spent, the add-to-home-screen hint) put text-inverse / accent-light text on a
// surface-dark background, in BOTH light and dark mode. Minimal Mono's dark mode once flipped those texts to near-black
// on its dark card, so the total was unreadable. WCAG AA for large text (3:1) in every skin, both modes.
it('keeps inverted-card text readable on surface-dark in every skin and mode', function () {
    $luminance = function (string $hex): float {
        $rgb = array_map(fn ($c) => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $lin = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    };
    $contrast = function (string $a, string $b) use ($luminance): float {
        [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    };

    foreach (glob(resource_path('themes/*/theme.json')) as $file) {
        $theme = json_decode(file_get_contents($file), true);
        foreach (['tokens', 'tokensDark'] as $mode) {
            $t = $theme[$mode] + $theme['tokens'];
            foreach (['text-inverse', 'accent-light'] as $text) {
                expect($contrast($t[$text], $t['surface-dark']))->toBeGreaterThan(3.0, basename(dirname($file))." {$mode} {$text}");
            }
        }
    }
});
