<?php

use App\Models\User;
use App\Support\Changelog;

// Every release needs these keys: the What's new page and the Me row read them as-is.
it('has a well-formed changelog, newest first', function () {
    $all = Changelog::all();
    expect($all)->not->toBeEmpty();
    foreach ($all as $r) {
        expect($r)->toHaveKeys(['version', 'date', 'items', 'fixes'])
            ->and(count($r['items']))->toBeLessThanOrEqual(3);
        foreach ($r['items'] as $i) {
            expect($i)->toHaveKeys(['module', 'title', 'text', 'href']);
        }
    }
    $dates = array_column($all, 'date');
    expect($dates)->toBe(collect($dates)->sortDesc()->values()->all());
});

it('marks the newest release seen when What\'s new is opened, for the account', function () {
    $user = User::factory()->create();
    expect(Changelog::unseen($user))->toBeTrue();

    $this->actingAs($user)->get('/settings/whats-new')->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/whats-new')->has('releases', count(Changelog::all())));

    expect(Changelog::unseen($user->fresh()))->toBeFalse()
        ->and($user->fresh()->seen_version)->toBe(Changelog::latest());
});
