<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\LoginCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

// Security audit 2026-10-19: account pre-hijacking. Someone could set an unregistered address on their own account
// without proving it, wait for its owner to sign up (the code / Google then signed the owner into that account),
// and get back in through the Google login and MCP token they had left on it.

/** The code the last LoginCode notification carried. */
function lastCode(): string
{
    $code = null;
    Notification::assertSentOnDemand(LoginCode::class, function (LoginCode $n) use (&$code) {
        $code = $n->code;

        return true;
    });

    return $code;
}

it('only changes the email once the code sent to the new address is typed', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'new@example.com'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('old@example.com');
    Notification::assertSentOnDemand(LoginCode::class, fn ($n, $c, $to) => $to->routes['mail'] === 'new@example.com');
    actingAs($user)->get('/settings/profile')->assertInertia(fn ($p) => $p->where('pendingEmail', 'new@example.com'));

    actingAs($user)->post('/settings/profile/email', ['code' => lastCode() === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    expect($user->fresh()->email)->toBe('old@example.com');

    actingAs($user)->post('/settings/profile/email', ['code' => lastCode()])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('new@example.com')
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses the change if the address got taken meanwhile, and can be cancelled', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'new@example.com']);
    User::factory()->create(['email' => 'new@example.com']);

    actingAs($user)->post('/settings/profile/email', ['code' => lastCode()])->assertSessionHasErrors('code');
    expect($user->fresh()->email)->toBe('old@example.com');

    actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'other@example.com']);
    actingAs($user)->delete('/settings/profile/email')->assertRedirect('/settings/profile');
    actingAs($user)->get('/settings/profile')->assertInertia(fn ($p) => $p->where('pendingEmail', null));
});

it('hands a claimed unverified account over clean: no old Google link, token or push subscription survives', function () {
    mcpKeys();
    $client = boardClient();
    $user = User::factory()->unverified()->create(['email' => 'victim@example.com']);
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'attacker-google', 'email' => 'a@x.com']);
    DB::table('oauth_access_tokens')->insert(['id' => 'tok1', 'user_id' => $user->id, 'client_id' => $client->id, 'revoked' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('oauth_refresh_tokens')->insert(['id' => 'ref1', 'access_token_id' => 'tok1', 'revoked' => false]);
    $user->updatePushSubscription('https://push.example/attacker', 'k', 'a');

    signInWithCode('victim@example.com');

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::where('user_id', $user->id)->count())->toBe(0)
        ->and((bool) DB::table('oauth_access_tokens')->where('id', 'tok1')->value('revoked'))->toBeTrue()
        ->and((bool) DB::table('oauth_refresh_tokens')->where('id', 'ref1')->value('revoked'))->toBeTrue()
        ->and($user->pushSubscriptions()->count())->toBe(0);
});

it('leaves a verified account alone when its owner signs in again', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'ana-google', 'email' => 'ana@example.com']);

    signInWithCode('ana@example.com');

    expect(SocialAccount::where('user_id', $user->id)->count())->toBe(1);
});
