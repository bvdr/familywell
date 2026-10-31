<?php

use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Spending\SpendSetting;
use Modules\Vehicles\VehicleSetting;

use function Pest\Laravel\actingAs;

it('sends a freshly signed-up user to About you, with no family', function () {
    signInWithCode('ada@example.com')->assertRedirect(route('onboarding.about'));

    expect(User::where('email', 'ada@example.com')->first()->families)->toHaveCount(0);
});

it('keeps someone without a name on About you until they give one', function () {
    $user = User::factory()->create(['name' => null]);

    actingAs($user)->get('/onboarding')->assertRedirect(route('onboarding.about'));
    actingAs($user)->get('/onboarding/about')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/about-you'));
    actingAs($user)->post('/onboarding/about', ['name' => ''])->assertSessionHasErrors('name');

    actingAs($user)->post('/onboarding/about', ['name' => '  Ada  '])->assertRedirect(route('onboarding.index', absolute: false));
    expect($user->fresh()->name)->toBe('Ada')->and($user->fresh()->locale)->toBe('en');
    actingAs($user->fresh())->get('/onboarding')->assertOk();
});

it('lets About you switch language and colours before the name is in', function () {
    $user = User::factory()->create(['name' => null]);

    actingAs($user)->from('/onboarding/about')->patch('/settings/language', ['locale' => 'nl'])->assertRedirect('/onboarding/about');
    actingAs($user)->from('/onboarding/about')->patch('/settings/appearance/theme', ['theme' => 'minimal-mono'])->assertRedirect('/onboarding/about');
    expect($user->fresh()->locale)->toBe('nl')->and($user->fresh()->theme)->toBe('minimal-mono');
});

it('opens the language list on the browser\'s language, then what is spoken where they are', function () {
    $user = User::factory()->create(['name' => null]);
    $about = fn (array $headers) => actingAs($user)->get('/onboarding/about', $headers)->assertOk();

    $about(['Accept-Language' => 'en-GB,en;q=0.9', 'CF-IPCountry' => 'RO'])->assertInertia(fn ($page) => $page
        ->where('browser', ['code' => 'en', 'tag' => 'en-GB'])->where('near', ['ro'])->where('example.code', 'ro')
        ->where('example.greeting', 'Bună dimineața, :name.'));
    // Belgium speaks three of ours; the browser's own language isn't repeated under "near you".
    $about(['Accept-Language' => 'fr-BE', 'CF-IPCountry' => 'BE'])->assertInertia(fn ($page) => $page
        ->where('browser', ['code' => 'fr', 'tag' => 'fr-BE'])->where('near', ['nl', 'de']));
    // No country (no Cloudflare), no preference: English, nothing near, and an example from the list.
    $about([])->assertInertia(fn ($page) => $page->where('browser.code', 'en')->where('near', []));
});

it('goes on to an invitation after About you', function () {
    $user = User::factory()->create(['name' => null]);

    actingAs($user)->withSession(['invite_token' => 'tok'])->post('/onboarding/about', ['name' => 'Ada'])
        ->assertRedirect(route('invite.show', 'tok'));
});

it('holds a family-less user out of the app', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.index'));
});

it('shows onboarding to a family-less user and the create form', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/onboarding')->assertOk();
    actingAs($user)->get('/onboarding/create')->assertOk();
});

it('creates a family from onboarding with the user as admin and goes on to inviting people', function () {
    // The skin picked on About you becomes the family's (skins are per family, ThemeTokens::resolve).
    $user = User::factory()->create(['theme' => 'minimal-mono']);

    actingAs($user)->post('/onboarding', [
        'name' => 'The Rivera Family',
        'description' => 'Home base',
        'color' => '#2D5E3A',
        'emoji' => '🏡',
    ])->assertRedirect(route('onboarding.invite', absolute: false));

    $family = $user->fresh()->families()->first();
    expect($family->name)->toBe('The Rivera Family')
        ->and($family->emoji)->toBe('🏡')
        ->and($family->owner_id)->toBe($user->id)
        ->and($family->pivot->role)->toBe('admin')
        ->and($family->theme)->toBe('minimal-mono');

    // Mid-onboarding, the app sends them back to the step they're on.
    expect($user->fresh()->onboarding_step)->toBe('invite');
    actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('onboarding.invite'));
});

it('rejects a color outside the offered palette', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', [
        'name' => 'X', 'color' => '#000000', 'emoji' => '🌿',
    ])->assertSessionHasErrors('color');
});

it('does not let an onboarded user create a second family through onboarding', function () {
    $user = User::factory()->create();
    $family = Family::create(['name' => 'First', 'owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    actingAs($user)->get('/onboarding')->assertRedirect(route('dashboard'));
    actingAs($user)->post('/onboarding', [
        'name' => 'Second', 'color' => '#2D5E3A', 'emoji' => '🌿',
    ])->assertRedirect(route('dashboard'));

    expect($user->fresh()->families)->toHaveCount(1);
});

it('starts a new family with every module on and leaves existing families alone', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    $registry = app(ModuleRegistry::class);
    $existing = Family::factory()->create();
    $registry->enable('tasks', $existing->id);

    $user = User::factory()->create();
    actingAs($user)->post('/onboarding', ['name' => 'New', 'color' => '#2D5E3A', 'emoji' => '🏡'])->assertRedirect();

    $family = $user->fresh()->families()->first();
    expect($registry->enabledIds($family->id))->toEqualCanonicalizing(array_keys($registry->discover()))
        ->and(DB::table('family_modules')->where('family_id', $existing->id)->pluck('module_id')->all())->toBe(['tasks']);
});

it('creates a family with a symbol from the onboarding palette, emoji defaulting for older screens', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', ['name' => 'The Riveras', 'color' => '#FFDF00', 'symbol' => 'paw-print'])->assertRedirect();

    $family = $user->fresh()->families()->first();
    expect($family->symbol)->toBe('paw-print')->and($family->color)->toBe('#FFDF00')->and($family->emoji)->toBe('🏡');
});

it('rejects a symbol that is not an icon key', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', ['name' => 'X', 'color' => '#FFDF00', 'symbol' => '<b>hi</b>'])->assertSessionHasErrors('symbol');
});

it('shows the region guess and applies the currency and units picked in onboarding', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    $user = User::factory()->create(['name' => 'Ana Rivera']);

    actingAs($user)->withHeader('CF-IPCountry', 'RO')->get('/onboarding/create')->assertOk()
        ->assertInertia(fn ($page) => $page->component('onboarding/create-family')
            ->where('userName', 'Ana Rivera')
            ->where('region.country', 'RO')
            ->where('region.currency', 'RON')
            ->where('region.units', 'metric'));

    actingAs($user)->withHeader('CF-IPCountry', 'RO')
        ->post('/onboarding', ['name' => 'The Riveras', 'color' => '#FFDF00', 'symbol' => 'house', 'currency' => 'USD', 'units' => 'imperial'])
        ->assertRedirect();

    $family = $user->fresh()->families()->first();
    expect(SpendSetting::current($family->id)->default_currency)->toBe('USD')
        ->and(VehicleSetting::current($family->id)->unit_system)->toBe('imperial');
});

it('lets ?preview=1 show a step to someone already onboarded, but never create through it', function () {
    $user = User::factory()->create();
    $family = Family::create(['name' => 'First', 'owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    actingAs($user)->get('/onboarding/create?preview=1')->assertOk();
    actingAs($user)->post('/onboarding?preview=1', ['name' => 'Second', 'color' => '#FFDF00', 'preview' => 1])->assertRedirect(route('dashboard'));
    expect($user->fresh()->families)->toHaveCount(1);
});

function onboardingOwner(string $step = 'invite'): array
{
    $user = User::factory()->create(['name' => 'Ana Rivera']);
    $family = Family::create(['name' => 'The Riveras', 'owner_id' => $user->id, 'color' => '#FFDF00']);
    $family->members()->attach($user, ['role' => 'admin']);
    $user->forceFill(['onboarding_step' => $step])->save();

    return [$user, $family];
}

it('edits the family when someone goes back to step 1, instead of making a second one', function () {
    [$user, $family] = onboardingOwner();

    actingAs($user)->get('/onboarding/create')->assertOk()
        ->assertInertia(fn ($page) => $page->where('family.name', 'The Riveras'));
    actingAs($user)->post('/onboarding', ['name' => 'Casa Rivera', 'color' => '#5BD38A', 'symbol' => 'dog'])
        ->assertRedirect(route('onboarding.invite'));

    expect($user->fresh()->families)->toHaveCount(1)
        ->and($family->fresh()->only(['name', 'color', 'symbol']))->toBe(['name' => 'Casa Rivera', 'color' => '#5BD38A', 'symbol' => 'dog']);
});

it('sends the onboarding invites with a role each and moves on', function () {
    Mail::fake();
    [$user, $family] = onboardingOwner();

    actingAs($user)->get('/onboarding/invite')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/invite'));
    actingAs($user)->post('/onboarding/invite', ['invites' => [
        ['email' => 'marco@rivera.family', 'role' => 'adult'],
        ['email' => 'lucia@rivera.family', 'role' => 'teen'],
    ]])->assertRedirect();

    expect($family->invitations()->whereNotNull('email')->pluck('role', 'email')->all())
        ->toEqual(['lucia@rivera.family' => 'teen', 'marco@rivera.family' => 'admin']);
    Mail::assertSent(FamilyInvitationMail::class, 2);
});

it('lets the invite step be skipped', function () {
    [$user, $family] = onboardingOwner();

    actingAs($user)->post('/onboarding/invite', ['invites' => []])->assertRedirect();
    expect($family->invitations()->whereNotNull('email')->count())->toBe(0);
});

it('shares one family link that stays usable after someone joins with it', function () {
    [$user, $family] = onboardingOwner();

    $link = actingAs($user)->get('/onboarding/invite')->viewData('page')['props']['link'];
    actingAs($user)->get('/onboarding/invite');
    expect($family->invitations()->whereNull('email')->count())->toBe(1);

    $token = basename($link);
    $joiner = User::factory()->create();
    actingAs($joiner)->post("/invite/{$token}")->assertRedirect(route('dashboard'));
    expect($family->members()->where('users.id', $joiner->id)->exists())->toBeTrue()
        ->and($family->invitations()->whereNull('email')->count())->toBe(1);
});

it('keeps the onboarding steps to the person running it', function () {
    [, $family] = onboardingOwner();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);
    $member->forceFill(['onboarding_step' => 'invite'])->save();

    actingAs($member)->get('/onboarding/invite')->assertRedirect(route('dashboard'));
    expect($member->fresh()->onboarding_step)->toBeNull();
});

it('pins the picked modules and leaves every module on', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    [$user, $family] = onboardingOwner('modules');
    $registry = app(ModuleRegistry::class);
    $registry->enableAll($family->id);
    $before = DB::table('family_modules')->where(['family_id' => $family->id, 'enabled' => true])->pluck('module_id')->sort()->values()->all();

    actingAs($user)->get('/onboarding/modules')->assertOk()
        ->assertInertia(fn ($page) => $page->component('onboarding/modules')->where('picked', ['shopping', 'tasks', 'calendar', 'spending']));
    actingAs($user)->post('/onboarding/modules', ['modules' => ['shopping', 'recipes', 'vehicles']])->assertRedirect();

    // Pinning is only what sits on top of their dashboard: nothing is turned off.
    $after = DB::table('family_modules')->where(['family_id' => $family->id, 'enabled' => true])->pluck('module_id')->sort()->values()->all();
    expect($after)->toBe($before)->and($user->fresh()->pinned_modules)->toBe(['shopping', 'recipes', 'vehicles']);

    // Back from a later step: the page shows what they pinned.
    actingAs($user->fresh())->get('/onboarding/modules')
        ->assertInertia(fn ($page) => $page->where('picked', ['shopping', 'recipes', 'vehicles']));
});

it('lets someone change their pins later, to modules that are on, four at most', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    [$user, $family] = onboardingOwner('modules');
    app(ModuleRegistry::class)->enableAll($family->id);
    $user->forceFill(['onboarding_step' => null])->save();

    actingAs($user)->put('/settings/pins', ['modules' => ['notes', 'tasks']])->assertRedirect();
    expect($user->fresh()->pinned_modules)->toBe(['notes', 'tasks']);

    actingAs($user)->put('/settings/pins', ['modules' => ['shopping', 'tasks', 'calendar', 'spending', 'notes']])->assertSessionHasErrors('modules');
    actingAs($user)->put('/settings/pins', ['modules' => ['not_a_module']])->assertSessionHasErrors('modules.0');
    actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('pins', ['notes', 'tasks']));
});

it('caps the picks at four', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    [$user] = onboardingOwner('modules');

    actingAs($user)->post('/onboarding/modules', ['modules' => ['shopping', 'tasks', 'calendar', 'spending', 'notes']])->assertSessionHasErrors('modules');
    actingAs($user)->post('/onboarding/modules', ['modules' => []])->assertSessionHasErrors('modules');
});
