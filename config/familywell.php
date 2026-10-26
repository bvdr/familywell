<?php

return [
    // Days a deleted task, list, shopping item, expense or note stays restorable before `trash:purge`
    // removes it for good. Deletes from the web UI and from MCP clients both land in the trash.
    'trash_days' => (int) env('TRASH_DAYS', 30),

    // Password for the demo family's owner (demo@familywell.co), so app-directory reviewers (Claude,
    // ChatGPT) can sign in and connect the MCP server to the demo data. Unset = random, as before: /demo
    // is then the only way in. Exposes nothing new (the demo is public via /demo) and DemoSeeder
    // re-applies it on every nightly reset, so a visitor changing it only lasts until midnight.
    'demo_password' => env('DEMO_PASSWORD'),

    // Language of DemoSeeder's content: 'en' (default, the public demo) or a locale with a string table in
    // database/seeders/demo/<locale>.php. Only set it on a throwaway local database to capture the localized
    // familywell.org screenshots: the hourly demo:reset would otherwise reseed the public demo in it.
    'demo_locale' => env('DEMO_LOCALE', 'en'),

    // Hosted pricing (Billing::pricing). The first `intro_spots` families to subscribe get the intro prices
    // (STRIPE_PRICE_MONTHLY / _YEARLY); after that checkout switches to the standard ones
    // (STRIPE_PRICE_*_STANDARD) and familywell.org's counter reads 0. Amounts are display copy and must
    // match the Stripe prices (whole dollars). All from .env so the launch offer can change without a deploy.
    'pricing' => [
        'intro_spots' => (int) env('PRICING_INTRO_SPOTS', 100),
        'intro' => ['monthly' => (int) env('PRICING_INTRO_MONTHLY', 5), 'yearly' => (int) env('PRICING_INTRO_YEARLY', 45)],
        'standard' => ['monthly' => (int) env('PRICING_MONTHLY', 6), 'yearly' => (int) env('PRICING_YEARLY', 54)],
    ],

    // The module-requests board ("Can't find yours?" in the onboarding's module picker). Empty hides the card,
    // e.g. on a self-hosted install that doesn't want to send people to familywell.org.
    'requests_url' => env('REQUESTS_URL', 'https://familywell.org/requests/'),

    // "Rather run it yourself?" on the onboarding's plan step. Empty hides the line.
    'self_hosting_url' => env('SELF_HOSTING_URL', 'https://github.com/bvdr/familywell#self-hosting'),
];
