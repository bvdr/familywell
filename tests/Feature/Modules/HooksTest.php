<?php

use App\Support\Modules\Hooks;

it('fires actions to all listeners', function () {
    $hooks = new Hooks;
    $seen = [];
    $hooks->listen('sample.pinged', function ($who) use (&$seen) {
        $seen[] = $who;
    });

    $hooks->fire('sample.pinged', 'ada');

    expect($seen)->toBe(['ada']);
});

it('chains filters through each listener', function () {
    $hooks = new Hooks;
    $hooks->listen('sample.greeting', fn ($v) => $v.' there');
    $hooks->listen('sample.greeting', fn ($v) => $v.'!');

    expect($hooks->filter('sample.greeting', 'Hello'))->toBe('Hello there!');
});
