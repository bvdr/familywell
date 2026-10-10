<?php

// The module-requests board (familywell.org/requests) and its admin (admin.familywell.org) sign people in
// through this app's OAuth server as first-party clients. Their Passport client ids are listed here: they
// skip the consent screen, but only when asking for exactly `board:identity` (App\Models\OAuthClient).
// Create a client with `php artisan board:client`, then add its id to BOARD_CLIENT_IDS (comma-separated).
return [
    'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('BOARD_CLIENT_IDS', ''))))),
];
