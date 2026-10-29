<?php

use App\Models\Family;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

it('renders the members page with props', function () {
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    actingAs($admin)->get('/family/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('family/members')
            ->where('family.name', $family->name)
            ->has('members', 1)
            ->where('canManage', true)
        );
});

// The family's name/colour/skin editor moved to Settings > Family > Name & look; this page is people only.
it('shares the join link with admins only, and no family look editor props', function () {
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);

    actingAs($admin)->get('/family/members')->assertInertia(fn (Assert $page) => $page
        ->where('link', fn ($link) => str_contains($link, '/invite/'))
        ->missing('palette')
        ->missing('themes')
        ->has('family', 2));
    actingAs($member)->get('/family/members')->assertInertia(fn (Assert $page) => $page->where('link', null)->where('invitations', []));
});

it('never shows a join link in the public demo family', function () {
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->forceFill(['is_demo' => true])->save();
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    actingAs($admin)->get('/family/members')->assertInertia(fn (Assert $page) => $page->where('link', null));
});
