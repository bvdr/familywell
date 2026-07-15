<?php

use App\Support\Modules\Hooks;

it('fires actions to all listeners', function () {
    $hooks = new Hooks();
    $seen = [];
    $hooks->listen('hello.pinged', function ($who) use (&$seen) {
        $seen[] = $who;
    });

    $hooks->fire('hello.pinged', 'ada');

    expect($seen)->toBe(['ada']);
});

it('chains filters through each listener', function () {
    $hooks = new Hooks();
    $hooks->listen('hello.greeting', fn ($v) => $v.' there');
    $hooks->listen('hello.greeting', fn ($v) => $v.'!');

    expect($hooks->filter('hello.greeting', 'Hello'))->toBe('Hello there!');
});
