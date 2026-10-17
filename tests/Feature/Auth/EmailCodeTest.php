<?php

use App\Models\Family;
use App\Models\User;
use App\Notifications\LoginCode;
use App\Support\Auth\EmailCode;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

// Sign in and sign up are one email-code flow (AuthenticatedSessionController). No passwords anywhere.

it('shows the same page for login and sign-up', function () {
    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/email-code')->where('mode', 'login'));
    $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('auth/email-code')->where('mode', 'register'));
});

it('emails a 6-digit code in the page language and then asks for it', function () {
    Notification::fake();

    $this->post('/auth/code', ['email' => ' Ana@Example.com '], ['Accept-Language' => 'fr'])->assertRedirect();

    Notification::assertSentOnDemand(LoginCode::class, function (LoginCode $n, array $channels, object $notifiable) {
        return preg_match('/^\d{6}$/', $n->code) && $n->locale === 'fr' && $notifiable->routes['mail'] === 'ana@example.com';
    });
    $this->get('/login')->assertInertia(fn ($page) => $page->where('codeSentTo', 'ana@example.com'));
});

it('signs an existing person in, remembered, on the right code', function () {
    $user = User::factory()->create(['email' => 'Ana@Example.com']);
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    signInWithCode('ana@example.com')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session('active_family_id'))->toBe($family->id)
        ->and($user->fresh()->remember_token)->not->toBeNull();
});

it('creates an account for a new address and starts onboarding with About you', function () {
    signInWithCode('new@example.com', ['Accept-Language' => 'de'])->assertRedirect(route('onboarding.about'));

    $user = User::where('email', 'new@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBeNull()
        ->and($user->locale)->toBe('de')
        ->and($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong code and burns the code after five misses', function () {
    Notification::fake();
    $this->post('/auth/code', ['email' => 'ana@example.com']);
    $code = null;
    Notification::assertSentOnDemand(LoginCode::class, function (LoginCode $n) use (&$code) {
        $code = $n->code;

        return true;
    });
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, EmailCode::MAX_ATTEMPTS) as $_) {
        $this->post('/auth/code/verify', ['code' => $wrong])->assertSessionHasErrors('code');
    }
    // Too late now: the right code was dropped with the fifth miss.
    $this->post('/auth/code/verify', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('expires a code after ten minutes', function () {
    Notification::fake();
    $this->post('/auth/code', ['email' => 'ana@example.com']);
    $code = null;
    Notification::assertSentOnDemand(LoginCode::class, function (LoginCode $n) use (&$code) {
        $code = $n->code;

        return true;
    });

    $this->travel(EmailCode::TTL_MINUTES + 1)->minutes();
    $this->post('/auth/code/verify', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('needs a code request first: verify alone signs nobody in', function () {
    $this->post('/auth/code/verify', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('caps how many codes one address can get', function () {
    Notification::fake();
    foreach (range(1, 5) as $_) {
        $this->post('/auth/code', ['email' => 'ana@example.com'])->assertSessionHasNoErrors();
    }
    $this->post('/auth/code', ['email' => 'ana@example.com'])->assertSessionHasErrors('email');
});

it('signs out every other session the first time an unverified account is proven', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    DB::table('sessions')->insert(['id' => 'squatter', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    signInWithCode('ana@example.com');

    expect(DB::table('sessions')->where('id', 'squatter')->exists())->toBeFalse()
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('logs out', function () {
    actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

// TEMPORARY password sign-in for app reviewers (AuthenticatedSessionController::passwordLogin).
it('still signs an existing account in with its password', function () {
    $user = User::factory()->create(['email' => 'reviewer@example.com']); // factory password: "password"

    $this->post('/login/password', ['email' => 'Reviewer@Example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('refuses a wrong password and throttles guessing', function () {
    User::factory()->create(['email' => 'reviewer@example.com']);

    foreach (range(1, 5) as $_) {
        $this->post('/login/password', ['email' => 'reviewer@example.com', 'password' => 'nope'])->assertSessionHasErrors('email');
    }
    // Locked now, even with the right one.
    $this->post('/login/password', ['email' => 'reviewer@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('never creates an account from the password form', function () {
    $this->post('/login/password', ['email' => 'new@example.com', 'password' => 'whatever'])->assertSessionHasErrors('email');
    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
});
