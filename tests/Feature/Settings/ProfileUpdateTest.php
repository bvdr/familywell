<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\LoginCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'Test User', 'email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertSame('Test User', $user->refresh()->name);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_delete_account_page_is_displayed()
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/delete-account')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/delete-account'));
    }

    /** The code the last LoginCode notification carried. */
    private function sentCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(LoginCode::class, function (LoginCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    public function test_user_can_delete_their_account_with_the_emailed_code()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->from('/settings/delete-account')->post('/settings/delete-account/code')
            ->assertRedirect('/settings/delete-account');
        Notification::assertSentOnDemand(LoginCode::class, fn ($n, $c, $to) => $to->routes['mail'] === $user->email);

        $this->actingAs($user)->delete('/settings/profile', ['code' => $this->sentCode()])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_a_wrong_code_does_not_delete_the_account()
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/settings/delete-account/code');
        $wrong = $this->sentCode() === '000000' ? '111111' : '000000';

        $this->actingAs($user)->from('/settings/delete-account')->delete('/settings/profile', ['code' => $wrong])
            ->assertSessionHasErrors('code')
            ->assertRedirect('/settings/delete-account');

        $this->assertNotNull($user->fresh());
    }

    public function test_typing_the_email_no_longer_deletes_the_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/profile', ['email' => $user->email])->assertSessionHasErrors('code');

        $this->assertNotNull($user->fresh());
    }
}
