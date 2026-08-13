<?php

use App\Support\Theming\ThemeTokens;
use function Pest\Laravel\get;

it('emits the default theme tokens as CSS custom properties', function () {
    $css = ThemeTokens::css('default');

    expect($css)->toContain(':root{')
        ->toContain('--accent:#2D5E3A')
        ->toContain('--text-primary:#1B3A28')
        ->toContain('--font-heading:Playfair Display')
        ->toContain('--spacing-md:16px')
        ->toContain('--radius-md:12px')
        // Dark mode flips the brand color tokens app.css never redefines (cards, wells, body text),
        // so a `.dark` block must ship with the light `:root` one.
        ->toContain('.dark{')
        ->toContain('--surface-card:#1F3B2A')
        ->toContain('--text-secondary:#A6BFAE');
});

it('injects the theme variables into the served document', function () {
    get('/login')
        ->assertOk()
        ->assertSee('id="theme"', false)
        ->assertSee('--accent:#2D5E3A', false);
});
