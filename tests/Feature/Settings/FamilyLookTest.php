<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Theming\ThemeTokens;

use function Pest\Laravel\actingAs;

// Settings > Family > Family name & look (boards Y1/Y5): onboarding's name / colour / symbol pickers + the family skin.

function lookFamily(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id, 'name' => 'The Riveras', 'color' => '#FFDF00', 'symbol' => 'house', 'theme' => 'minimal-mono']);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);

    return [$family, $admin, $member];
}

it('opens the family\'s name and look for an admin', function () {
    [, $admin] = lookFamily();

    actingAs($admin)->get('/settings/family/look')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/family-look')
        ->where('family.name', 'The Riveras')
        ->where('family.color', '#FFDF00')
        ->where('family.symbol', 'house')
        ->where('family.theme', 'minimal-mono')
        ->where('canManage', true)
        ->where('members', 2)
        ->where('colors', Family::ONBOARDING_COLORS)
        ->where('defaultTheme', ThemeTokens::DEFAULT));
});

it('shows it read-only to a member, who cannot save', function () {
    [$family, , $member] = lookFamily();

    actingAs($member)->get('/settings/family/look')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));

    session(['active_family_id' => $family->id]);
    actingAs($member)->patch('/family', ['name' => 'Nope', 'color' => '#FFDF00', 'emoji' => '🏡', 'symbol' => 'sun'])->assertForbidden();
    expect($family->fresh()->name)->toBe('The Riveras');
});

it('saves the name, colour and symbol the page sends', function () {
    [$family, $admin] = lookFamily();

    // What the page sends: the edited fields plus the emoji/description it doesn't edit.
    actingAs($admin)->from('/settings/family/look')->patch('/family', [
        'name' => 'Casa Rivera', 'color' => '#5BD38A', 'symbol' => 'monogram', 'emoji' => $family->fresh()->emoji, 'description' => '',
    ])->assertSessionHasNoErrors()->assertRedirect('/settings/family/look');

    expect($family->fresh())->name->toBe('Casa Rivera')->color->toBe('#5BD38A')->symbol->toBe('monogram');
});
