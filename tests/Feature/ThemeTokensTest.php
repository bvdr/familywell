<?php

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

it('falls back to the default theme for an unknown name', function () {
    expect(ThemeTokens::css('does-not-exist'))->toBe(ThemeTokens::css('default'));
});

it('injects the theme variables into the served document', function () {
    get('/login')
        ->assertOk()
        ->assertSee('id="theme"', false)
        ->assertSee('--primary:hsl(135.9, 35.3%, 27.3%)', false);
});

it('resolves the skin as user override, then family default, then built-in', function () {
    $family = App\Models\Family::factory()->create(['theme' => 'minimal-mono']);
    $user = App\Models\User::factory()->create(['theme' => null]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    // No personal skin -> the family default is served.
    actingAs($user)->get('/dashboard')->assertSee('--accent-strong:#F59E0B', false);

    // Personal skin overrides the family default.
    $user->update(['theme' => 'default']);
    actingAs($user)->get('/dashboard')->assertSee('--primary:hsl(135.9, 35.3%, 27.3%)', false);
});
