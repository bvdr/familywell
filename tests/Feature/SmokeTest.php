<?php

use function Pest\Laravel\get;

it('serves the welcome page', function () {
    get('/')->assertOk();
});

it('serves the login page', function () {
    get('/login')->assertOk();
});
