<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

// The update nudge compares this with the page's Inertia version, so it must be the very same value.
it('reports the running asset version, uncached', function () {
    $this->get('/app-version')
        ->assertOk()
        ->assertExactJson(['version' => app(HandleInertiaRequests::class)->version(Request::create('/'))])
        ->assertHeader('Cache-Control', 'no-store, private');
});
