<?php

use App\Support\Billing\Billing;
use Illuminate\Support\Facades\Route;

// Public price + intro-spot counter for familywell.org's pricing card. Loaded outside the `web` group
// (no session, no cookies) so the response is safely cacheable; open CORS because the marketing site is a
// static page on another origin and nothing here is private. 404 when billing is off (self-hosted).
Route::get('/pricing.json', function () {
    abort_unless(Billing::enabled(), 404);

    return response()->json(Billing::pricing())
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Cache-Control', 'public, max-age=60');
})->middleware('throttle:120,1')->name('pricing.json');
