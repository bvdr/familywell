<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Modules\Spending\SpendSetting;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleSetting;

use function Pest\Laravel\actingAs;

// Settings › Region & units: the family hub with the shared sheet open. Values live in the modules (Vehicles'
// units, Spending's currency) and are read / saved through the family.region hooks (App\Support\Region).

function regionFamily(): array
{
    test()->artisan('modules:migrate')->assertExitCode(0);
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id, 'name' => 'The Riveras']);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);

    return [$family, $admin, $member];
}

it('shows the family\'s units and currency on the hub row', function () {
    [$family, $admin] = regionFamily();
    SpendSetting::current($family->id)->update(['default_currency' => 'RON']);

    actingAs($admin)->get('/settings/family')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/family')
        ->where('region.units', 'metric')
        ->where('region.currency', 'RON')
        ->missing('regionSheet'));
});

it('opens the sheet with countries that fill in the rest and real preview values', function () {
    [$family, $admin] = regionFamily();
    CurrentFamily::set($family->id);
    Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 84210]);
    CurrentFamily::forget();

    actingAs($admin)->get('/settings/family/region')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/family')
        ->where('regionSheet.units', 'metric')
        ->where('regionSheet.currency', 'EUR') // unset falls back to EUR, like everywhere money shows
        ->where('regionSheet.samples.km', 84210)
        ->where('regionSheet.countries', fn ($countries) => collect($countries)->contains(fn ($c) => $c['code'] === 'RO' && $c['currency'] === 'RON' && $c['units'] === 'metric')
            && collect($countries)->contains(fn ($c) => $c['code'] === 'US' && $c['currency'] === 'USD' && $c['units'] === 'imperial')));
});

it('lets an admin save units and currency through the modules, ignoring values they don\'t accept', function () {
    [$family, $admin] = regionFamily();

    actingAs($admin)->patch('/settings/family/region', ['units' => 'imperial', 'currency' => 'USD', 'country' => 'US'])
        ->assertRedirect(route('settings.family'));
    expect(VehicleSetting::current($family->id)->unit_system)->toBe('imperial')
        ->and(SpendSetting::current($family->id)->default_currency)->toBe('USD');

    actingAs($admin)->patch('/settings/family/region', ['units' => 'furlongs', 'currency' => 'XXX'])->assertRedirect();
    expect(VehicleSetting::current($family->id)->unit_system)->toBe('imperial')
        ->and(SpendSetting::current($family->id)->default_currency)->toBe('USD');
});

it('shows the sheet to every member but only lets admins change it', function () {
    [$family, , $member] = regionFamily();

    actingAs($member)->get('/settings/family/region')->assertOk()->assertInertia(fn ($page) => $page->where('isAdmin', false)->has('regionSheet'));
    actingAs($member)->patch('/settings/family/region', ['units' => 'imperial'])->assertForbidden();
    expect(VehicleSetting::current($family->id)->unit_system)->toBe('metric');
});

it('gives onboarding the same country list for its Change sheet', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);

    actingAs(User::factory()->create())->withHeader('CF-IPCountry', 'GB')->get('/onboarding/create')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('region.units', 'imperial')
            ->where('region.currency', 'GBP')
            ->where('region.countries', fn ($countries) => collect($countries)->contains(fn ($c) => $c['code'] === 'DE' && $c['currency'] === 'EUR')));
});
