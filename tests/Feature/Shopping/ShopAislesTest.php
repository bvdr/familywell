<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingShop;
use Modules\Shopping\StoreTemplates;

function aislesFamily(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    test()->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('shopping', $family->id);

    return [$user, $family];
}

it('suggests a starting set from the brand or the name', function () {
    expect(StoreTemplates::guess('Hornbach', 'hornbach'))->toBe('bricolage')
        ->and(StoreTemplates::guess('Farmacia din colț'))->toBe('pharmacy')
        ->and(StoreTemplates::guess('Kaufland', 'kaufland'))->toBe('grocery');
});

it('adds a shop from Your shops straight onto its aisles, and remembers the set it started from', function () {
    [$user, $family] = aislesFamily();
    $this->actingAs($user)->withSession(['active_family_id' => $family->id]);

    $r = $this->post('/m/shopping/shops', ['name' => 'Dedeman', 'pick_aisles' => true]);
    CurrentFamily::set($family->id);
    $shop = ShoppingShop::where('name', 'Dedeman')->firstOrFail();
    $r->assertRedirect("/m/shopping/shops/{$shop->id}/aisles?pick=1");

    $this->get("/m/shopping/shops/{$shop->id}/aisles?pick=1")->assertInertia(fn ($p) => $p->component('shopping/shop-aisles')
        ->where('suggested', 'bricolage')->where('pick', true)->where('shop.template', 'grocery')->has('aisles', 9));

    $this->postJson("/m/shopping/shops/{$shop->id}/aisles/template", ['template' => 'bricolage'])->assertOk();
    expect($shop->fresh()->template)->toBe('bricolage');
});

it('keeps another family\'s shop out of reach', function () {
    [$user, $family] = aislesFamily();
    [, $other] = aislesFamily();
    CurrentFamily::set($other->id);
    $theirs = ShoppingShop::create(['name' => 'Their shop']);
    CurrentFamily::forget();

    $this->actingAs($user)->withSession(['active_family_id' => $family->id]);
    $this->get("/m/shopping/shops/{$theirs->id}")->assertNotFound();
    $this->get("/m/shopping/shops/{$theirs->id}/aisles")->assertNotFound();
    $this->postJson("/m/shopping/shops/{$theirs->id}/aisles/template", ['template' => 'blank'])->assertNotFound();
});
