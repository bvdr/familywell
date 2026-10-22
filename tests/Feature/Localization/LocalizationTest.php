<?php

use App\Models\User;
use App\Support\Translations;
use Illuminate\Support\Arr;
use Modules\Spending\SpendCategory;

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
    expect($codes)->toContain('en')->toContain('ro')->toContain('de')->toContain('fr')->toContain('nl')->toContain('sv')->toContain('nb')->toContain('pt');
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

// Guests (login / sign-up) get the browser's language, so the welcome is in their own language.
it('shows guests the page in their browser language, English when unsupported', function () {
    $this->get('/login', ['Accept-Language' => 'de-AT,de;q=0.9,en;q=0.8'])
        ->assertInertia(fn ($page) => $page->where('locale', 'de'));
    $this->get('/login', ['Accept-Language' => 'ja-JP,ja;q=0.9'])
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('saves the sign-up page language as the new account locale', function () {
    signInWithCode('ana@example.com', ['Accept-Language' => 'ro-RO,ro;q=0.9'])->assertRedirect();

    expect(User::where('email', 'ana@example.com')->value('locale'))->toBe('ro');
});

it('keeps a chosen locale over the browser language', function () {
    $user = User::factory()->create(['locale' => 'en']);
    actingAs($user)->get('/settings/language', ['Accept-Language' => 'de'])
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('greets a Norwegian browser in Bokmål, whichever code it sends', function (string $header) {
    $this->get('/login', ['Accept-Language' => $header])->assertInertia(fn ($page) => $page->where('locale', 'nb'));
})->with(['nb-NO,nb;q=0.9', 'no', 'nn-NO,nn;q=0.9']);

it('greets a Swedish browser in Swedish', function () {
    $this->get('/login', ['Accept-Language' => 'sv-SE,sv;q=0.9,en;q=0.5'])->assertInertia(fn ($page) => $page->where('locale', 'sv'));
});

it('greets a Brazilian browser in Brazilian Portuguese', function () {
    $this->get('/login', ['Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.5'])->assertInertia(fn ($page) => $page->where('locale', 'pt'));
});

// Default spending categories are stored with English names but displayed through
// spending.category_<key> (lib/categories.ts catLabel), so each needs a key in every locale.
it('has a label for every default spending category in every locale', function () {
    foreach (array_keys(Translations::LOCALES) as $locale) {
        foreach (SpendCategory::DEFAULTS as $cat) {
            expect(Translations::get('spending.category_'.$cat['key'], $locale))->not->toStartWith('spending.');
        }
    }
    expect(Translations::get('spending.others_list', 'de'))->toBe('Sonstiges');
});

it('shows recipe units in the viewer language', function () {
    expect(Translations::get('recipes.unit_pcs', 'de'))->toBe('Stk')
        ->and(Translations::get('recipes.unit_tbsp', 'de'))->toBe('EL');
});
