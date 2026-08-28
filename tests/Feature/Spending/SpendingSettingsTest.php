<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Spending\SpendCategory;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendSetting;

use function Pest\Laravel\actingAs;

// A family with the spending module enabled and active, admin first + a plain member. Mirrors the
// helper in SpendingTest but kept local so this file stands on its own.
function settingsFamily(): array
{
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.2, 'USD' => 1.1, 'RON' => 5.0, 'GBP' => 0.85]]),
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 0.2, 'USD' => 1.1, 'RON' => 5.0, 'GBP' => 0.85]]),
    ]);

    $admin = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);

    return [$family, $admin, $member];
}

it('seeds education and health as default categories for a family', function () {
    [, $admin] = settingsFamily();

    // Opening the settings screen lazily seeds the defaults for the family.
    actingAs($admin)->get('/m/spending/settings')->assertOk();

    $keys = SpendCategory::pluck('key')->all();
    expect($keys)->toContain('education')
        ->and($keys)->toContain('health')
        // The original six survive too, so existing spends keep resolving.
        ->and($keys)->toContain('food', 'transport', 'lodging', 'activities', 'shopping', 'other');
});

it('persists the default currency and uses it on the create form and analytics', function () {
    [, $admin] = settingsFamily();

    actingAs($admin)->patch('/m/spending/settings', ['default_currency' => 'USD'])->assertRedirect();
    expect(SpendSetting::defaultCurrency())->toBe('USD');

    // (a) pre-selected on the overview/create form
    actingAs($admin)->get('/m/spending')
        ->assertInertia(fn ($p) => $p->component('spending/events')->where('defaultCurrency', 'USD')->etc());

    // (b) the analytics default display currency, with no explicit ?currency=
    actingAs($admin)->get('/m/spending/analytics')
        ->assertInertia(fn ($p) => $p->component('spending/analytics')->where('currency', 'USD')->etc());
});

it('only lets admins change the default currency', function () {
    [, , $member] = settingsFamily();

    actingAs($member)->patch('/m/spending/settings', ['default_currency' => 'GBP'])->assertForbidden();
    expect(SpendSetting::defaultCurrency())->toBeNull();
});

it('lets an admin add a custom category and use it on a spend', function () {
    [, $admin] = settingsFamily();

    actingAs($admin)->post('/m/spending/categories', ['name' => 'Gifts'])->assertRedirect();

    $custom = SpendCategory::where('key', 'gifts')->first();
    expect($custom)->not->toBeNull()
        ->and($custom->is_default)->toBeFalse()
        ->and($custom->name)->toBe('Gifts');

    // The new key is accepted by the spend validation and stored on the spend.
    actingAs($admin)->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();
    actingAs($admin)->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'gifts', 'payer_key' => 'u'.$admin->id,
        'spent_at' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($event->spends()->where('category', 'gifts')->exists())->toBeTrue();
});

it('rejects a spend with an unknown category', function () {
    [, $admin] = settingsFamily();
    actingAs($admin)->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    actingAs($admin)->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '10.00', 'currency' => 'EUR', 'category' => 'nope_not_real', 'payer_key' => 'u'.$admin->id,
        'spent_at' => now()->toDateString(),
    ])->assertSessionHasErrors('category');
});

it('blocks deleting a category that is in use but allows an unused one', function () {
    [, $admin] = settingsFamily();
    actingAs($admin)->post('/m/spending/events', ['name' => 'Trip', 'currency' => 'EUR']);
    $event = SpendEvent::first();

    // Put a spend on 'food', then try to delete it — blocked.
    actingAs($admin)->post("/m/spending/events/{$event->id}/spends", [
        'amount' => '5.00', 'currency' => 'EUR', 'category' => 'food', 'payer_key' => 'u'.$admin->id,
        'spent_at' => now()->toDateString(),
    ])->assertRedirect();

    $food = SpendCategory::where('key', 'food')->first();
    actingAs($admin)->delete("/m/spending/categories/{$food->id}")->assertSessionHasErrors('category');
    expect(SpendCategory::where('key', 'food')->exists())->toBeTrue();

    // An unused category deletes cleanly.
    $unused = SpendCategory::where('key', 'shopping')->first();
    actingAs($admin)->delete("/m/spending/categories/{$unused->id}")->assertRedirect()->assertSessionHasNoErrors();
    expect(SpendCategory::where('key', 'shopping')->exists())->toBeFalse();
});
