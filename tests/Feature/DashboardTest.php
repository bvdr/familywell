<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_sign_up_page()
    {
        $this->get('/dashboard')->assertRedirect('/register');
    }

    public function test_authenticated_users_with_a_family_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $family = Family::factory()->create(['owner_id' => $user->id]);
        $family->members()->attach($user, ['role' => 'admin']);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_users_without_a_family_are_sent_to_onboarding()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect(route('onboarding.index'));
    }
}
