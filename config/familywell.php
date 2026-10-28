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
        // Pro tier, same shape (design 'Plan & pay · Pro': $12 -> $9 a month, $108 -> $81 a year). Display copy like
        // the above; it must match the STRIPE_PRICE_*_PRO prices, which are what is actually charged.
        'pro' => [
            'intro' => ['monthly' => (int) env('PRICING_PRO_INTRO_MONTHLY', 9), 'yearly' => (int) env('PRICING_PRO_INTRO_YEARLY', 81)],
            'standard' => ['monthly' => (int) env('PRICING_PRO_MONTHLY', 12), 'yearly' => (int) env('PRICING_PRO_YEARLY', 108)],
        ],
    ],

    // What each tier includes ("1 GB · 50 AI points"). AI points are metered per family per calendar month
    // (App\Support\Ai\AiPoints): one point per finished AI action (an add read by "Just say it", a receipt scan).
    // Storage is still display copy only.
    'tiers' => [
        'basic' => ['storage_gb' => 1, 'ai_points' => 50],
        'pro' => ['storage_gb' => 5, 'ai_points' => 200],
    ],

    // What one AI call costs us, in USD per million tokens, so every call in the ai_usage ledger carries its price
    // (App\Support\Ai\AiUsage). Keyed by model id. Cloudflare's Workers AI models bill input only. Unknown models
    // record 0 cost (the tokens are still logged). Check these against the providers' price pages when they change.
    'ai_rates' => [
        'clef' => ['in' => 0.24],
        'clef-flash' => ['in' => 0.038],
        'clairic' => ['in' => 0.10],
        'llama-4-scout-17b-16e-instruct' => ['in' => 0.27, 'out' => 0.85],
        'jev-latest' => ['in' => 0.042],
        'gemini-3-flash-preview' => ['in' => 0.50, 'out' => 3.00],
        'gemini-3.1-flash-lite' => ['in' => 0.25, 'out' => 1.50], // the reader worker's model since 2026-10-28
        // Whisper is billed per audio minute ($0.000513); its "tokens" in the ledger are seconds: $8.55 per million s.
        'whisper-large-v3-turbo' => ['in' => 8.55],
    ],

    // The module-requests board ("Can't find yours?" in the onboarding's module picker). Empty hides the card,
    // e.g. on a self-hosted install that doesn't want to send people to familywell.org.
    'requests_url' => env('REQUESTS_URL', 'https://familywell.org/requests/'),

    // "Rather run it yourself?" on the onboarding's plan step. Empty hides the line.
    'self_hosting_url' => env('SELF_HOSTING_URL', 'https://github.com/bvdr/familywell#self-hosting'),
];
