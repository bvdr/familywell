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
