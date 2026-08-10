<?php

use App\Models\User;
use App\Support\Translations;

use function Pest\Laravel\actingAs;

it('merges the locale over English so every key resolves', function () {
    $en = Translations::for('en');
    $ro = Translations::for('ro');

    expect($ro['nav']['dashboard'])->toBe('Acasă')        // translated
        ->and($en['nav']['dashboard'])->toBe('Dashboard')
        // A key present in en but (hypothetically) missing in ro still resolves via the English merge.
        ->and($ro['common']['save'])->not->toBeEmpty();
});

it('lists locale options', function () {
    $codes = collect(Translations::options())->pluck('code');
    expect($codes)->toContain('en')->toContain('ro');
});

it('saves the user locale and applies it to the shared translations', function () {
    $user = User::factory()->create(['locale' => null]);

    actingAs($user)->patch('/settings/language', ['locale' => 'ro'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('ro');

    // A subsequent request runs SetLocale + shares the Romanian map.
    actingAs($user)->get('/settings/language')
        ->assertInertia(fn ($page) => $page->where('translations.nav.dashboard', 'Acasă')->where('locale', 'ro'));
});

it('rejects an unknown locale', function () {
    $user = User::factory()->create();
    actingAs($user)->patch('/settings/language', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});
