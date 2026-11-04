<?php

use Illuminate\Support\Facades\Http;

// New accounts join the Resend "new-users" segment (the welcome sequence's trigger) once they have a name.

it('adds an email-code sign-up to the Resend segment at About you, once, with the first name split off', function () {
    config(['services.resend.key' => 're_test', 'services.resend.new_users_segment' => 'seg_1']);
    Http::fake(['api.resend.com/*' => Http::response(['id' => 'c_1'])]);

    signInWithCode('sara@example.com');
    Http::assertNothingSent(); // no name yet

    $this->post('/onboarding/about', ['name' => 'Sara Green-Anderson']);
    $this->post('/onboarding/about', ['name' => 'Sara G']); // a rename is not a new account

    Http::assertSentCount(2); // contact + event, once
    Http::assertSent(fn ($r) => $r->url() === 'https://api.resend.com/contacts' && $r['email'] === 'sara@example.com'
        && $r['first_name'] === 'Sara' && $r['last_name'] === 'Green-Anderson' && $r['segments'] === [['id' => 'seg_1']]);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.resend.com/events/send' && $r['event'] === 'user.signed_up' && $r['email'] === 'sara@example.com');
});

it('sends nothing without a segment configured (self-hosted)', function () {
    config(['services.resend.key' => 're_test', 'services.resend.new_users_segment' => null]);
    Http::fake();

    signInWithCode('sara@example.com');
    $this->post('/onboarding/about', ['name' => 'Sara']);

    Http::assertNothingSent();
});
