<?php

use App\Models\User;
use App\Support\Translations;
use Illuminate\Support\Arr;

use function Pest\Laravel\actingAs;

it('merges the locale over English so every key resolves', function () {
    $en = Translations::for('en');
    $ro = Translations::for('ro');

    expect($ro['nav']['dashboard'])->toBe('Acasă')        // translated
        ->and($en['nav']['dashboard'])->toBe('Dashboard')
        // A key present in en but (hypothetically) missing in ro still resolves via the English merge.
        ->and($ro['common']['save'])->not->toBeEmpty();
});

// English is the fallback, so a missing key never breaks a page; it just shows English.
// This keeps every shipped locale complete anyway, so a new string can't silently stay untranslated.
it('translates every English key in every locale', function () {
    $flatten = fn (array $map) => array_keys(Arr::dot($map));
    $en = $flatten(Translations::all('en'));

    foreach (array_keys(Translations::LOCALES) as $locale) {
        expect(array_values(array_diff($en, $flatten(Translations::all($locale)))))->toBe([], "missing in {$locale}");
    }
});

it('lists locale options', function () {
    $codes = collect(Translations::options())->pluck('code');
    expect($codes)->toContain('en')->toContain('ro')->toContain('de')->toContain('fr')->toContain('nl');
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

it('picks the plural form for the count and the language', function () {
    expect(Translations::choice('vehicles.repairs_count', 1, 'en'))->toBe('1 repair')
        ->and(Translations::choice('vehicles.repairs_count', 3, 'en'))->toBe('3 repairs')
        ->and(Translations::choice('vehicles.repairs_count', 1, 'de'))->toBe('1 Reparatur')
        // Romanian has three forms: one, few (0, 2-19) and other (20+, takes "de").
        ->and(Translations::choice('vehicles.repairs_count', 1, 'ro'))->toBe('1 reparație')
        ->and(Translations::choice('vehicles.repairs_count', 5, 'ro'))->toBe('5 reparații')
        ->and(Translations::choice('vehicles.repairs_count', 21, 'ro'))->toBe('21 de reparații');
});
