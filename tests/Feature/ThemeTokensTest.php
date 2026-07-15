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
        ->toContain('--radius-md:12px');
});

it('injects the theme variables into the served document', function () {
    get('/login')
        ->assertOk()
        ->assertSee('id="theme"', false)
        ->assertSee('--accent:#2D5E3A', false);
});
