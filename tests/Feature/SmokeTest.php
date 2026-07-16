<?php

use function Pest\Laravel\get;

it('redirects the root to the app', function () {
    get('/')->assertRedirect(route('dashboard'));
});

it('serves the login page', function () {
    get('/login')->assertOk();
});
