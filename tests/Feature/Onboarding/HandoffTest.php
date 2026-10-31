<?php

use App\Mail\HandoffLinkMail;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

// "Now take it to your phone": the sign-in link the computer hands to the phone. It signs whoever opens it in,
// so these pin down that it's single use, short-lived and only for the person mid-onboarding.

function installStepOwner(): User
{
    $user = User::factory()->create();
    $family = Family::create(['name' => 'The Riveras', 'owner_id' => $user->id, 'color' => '#FFDF00']);
    $family->members()->attach($user, ['role' => 'admin']);
    $user->forceFill(['onboarding_step' => 'install'])->save();

    return $user;
}

it('signs the phone in once with the QR link, then never again', function () {
    $user = installStepOwner();
    $url = actingAs($user)->postJson('/onboarding/handoff')->assertOk()->json('url');
    auth()->logout();
    test()->flushSession();

    $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    test()->flushSession();
    $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('stops working after ten minutes', function () {
    $url = actingAs(installStepOwner())->postJson('/onboarding/handoff')->json('url');
    auth()->logout();
    test()->flushSession();

    $this->travel(11)->minutes();
    $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('rejects a made-up token', function () {
    $this->get('/h/'.str_repeat('a', 40))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('only hands off for someone mid-onboarding', function () {
    $user = installStepOwner();
    $user->forceFill(['onboarding_step' => null])->save();

    actingAs($user)->postJson('/onboarding/handoff')->assertForbidden();
});

it('emails the sign-in link to the account itself, whatever address is posted', function () {
    Mail::fake();
    $user = installStepOwner();

    actingAs($user)->post('/onboarding/handoff/email', ['email' => 'attacker@example.com'])->assertRedirect();

    Mail::assertSent(HandoffLinkMail::class, fn ($mail) => $mail->hasTo($user->email) && ! $mail->hasTo('attacker@example.com'));
});

it('throttles the handoff link endpoint', function () {
    $user = installStepOwner();
    foreach (range(1, 10) as $i) {
        actingAs($user)->postJson('/onboarding/handoff')->assertOk();
    }
    actingAs($user)->postJson('/onboarding/handoff')->assertStatus(429);
});

it('moves past the install step and resumes the next one', function () {
    $user = installStepOwner();

    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.install'));
    actingAs($user)->get('/onboarding/install')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/install')->where('step', 'install'));
    actingAs($user)->post('/onboarding/install', ['via' => 'nope'])->assertSessionHasErrors('via');
    actingAs($user)->post('/onboarding/install', ['via' => 'standalone'])->assertRedirect();
});
