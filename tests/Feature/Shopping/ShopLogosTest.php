<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Shopping\ShopBrands;
use Modules\Shopping\ShoppingShop;

// Names typed the way people type them (2026-11-03 test set) land on the right brand; local shops stay letters.
it('matches messy shop names to brands, preferring the family\'s country', function (string $typed, ?string $country, ?string $brand) {
    expect(ShopBrands::match($typed, $country))->toBe($brand);
})->with([
    ['mega', 'RO', 'mega-image'], ['Mega Image', 'RO', 'mega-image'], ['megaimage', 'RO', 'mega-image'], ['MEGA-IMAGE', 'RO', 'mega-image'],
    ['Kaufland Pipera', 'RO', 'kaufland'], ['Lidl Romania', 'RO', 'lidl'], ['Penny Market', 'RO', 'penny'], ['dm drogerie', 'RO', 'dm'],
    ['Carefour', 'RO', 'carrefour'], ['La doi pași', 'RO', 'la-doi-pasi'], ['H&M', 'RO', 'hm'], ['tei', 'RO', 'farmacia-tei'],
    ['Jumbo', 'RO', 'jumbo-ro'], ['Jumbo', 'NL', 'jumbo-nl'],
    ['Brutăria lui Ion', 'RO', null], ['piata', 'RO', null], ['Mama Puiu', 'RO', null],
]);

it('reads the country from the timezone', function () {
    expect(ShopBrands::countryOf('Europe/Bucharest'))->toBe('RO')->and(ShopBrands::countryOf(null))->toBeNull();
});

function shopFamily(): Family
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    test()->actingAs($user);
    CurrentFamily::set($family->id);

    return $family;
}

it('gives a shop its brand logo when it is created or renamed', function () {
    shopFamily();
    $shop = ShoppingShop::create(['name' => 'kaufland pipera']);
    expect($shop->brand)->toBe('kaufland')->and($shop->logoUrl())->toBe('/brands/kaufland.png');

    $shop->update(['name' => 'Brutăria lui Ion']);
    expect($shop->fresh()->brand)->toBeNull()->and($shop->fresh()->logoUrl())->toBeNull();
});

it('sweeps unknown shop names weekly: stores a trusted logo once, skips what is not a shop', function () {
    $family = shopFamily();
    ShopBrands::forgetFinds();
    Storage::fake('r2');
    foreach (['Fornetti', 'Dominica Bakery'] as $name) {
        ShoppingShop::create(['name' => $name]);
    }
    Http::fake([
        'www.wikidata.org/*action=wbsearchentities*search=Fornetti*' => Http::response(['search' => [
            ['id' => 'Q1', 'label' => 'Fornetti', 'description' => 'Hungarian bakery chain', 'match' => ['text' => 'Fornetti']],
        ]]),
        'www.wikidata.org/*action=wbsearchentities*' => Http::response(['search' => [
            ['id' => 'Q2', 'label' => 'Dominica', 'description' => 'island country in the Caribbean', 'match' => ['text' => 'Dominica']],
        ]]),
        'www.wikidata.org/*action=wbgetentities*' => Http::response(['entities' => ['Q1' => [
            'labels' => ['en' => ['value' => 'Fornetti']],
            'claims' => ['P154' => [['mainsnak' => ['datavalue' => ['value' => 'Fornetti logo.svg']]]]],
        ]]]),
        'commons.wikimedia.org/*' => Http::response(str_repeat('P', 2000)),
    ]);

    $this->artisan('shops:find-logos')->assertSuccessful();

    Storage::disk('r2')->assertExists('brands/x-fornetti.png');
    expect(ShoppingShop::where('name', 'Fornetti')->value('brand'))->toBe('x-fornetti')
        ->and(ShoppingShop::where('name', 'Dominica Bakery')->value('brand'))->toBeNull()
        ->and(DB::table('shopping_brand_finds')->where('key', 'dominicabakery')->value('status'))->toBe('none');

    // A new family typing it later gets the logo straight away, no lookup.
    expect(ShopBrands::match('fornetti'))->toBe('x-fornetti');
    $this->get('/brands/x/x-fornetti.png')->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});
