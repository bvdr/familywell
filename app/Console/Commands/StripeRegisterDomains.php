<?php

namespace App\Console\Commands;

use App\Support\Billing\Billing;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

// Apple Pay / Google Pay in the inline checkout only show on domains registered with Stripe (payment method domains).
// Run once per environment, with that environment's Stripe secret: it registers APP_URL's host (dev.familywell.co on
// dev, familywell.co on prod) plus any extra hosts given. Safe to re-run: an already registered domain is reported,
// not duplicated.
class StripeRegisterDomains extends Command
{
    protected $signature = 'stripe:register-domains {domains?* : Extra hosts beyond APP_URL\'s}';

    protected $description = 'Register this site\'s domain(s) with Stripe for Apple Pay / Google Pay';

    public function handle(): int
    {
        if (! Billing::enabled() || ! config('services.stripe.secret')) {
            $this->error('Stripe is not configured (STRIPE_KEY / STRIPE_SECRET).');

            return self::FAILURE;
        }
        $domains = array_unique([parse_url((string) config('app.url'), PHP_URL_HOST), ...$this->argument('domains')]);
        foreach (array_filter($domains) as $domain) {
            try {
                $d = Billing::registerDomain($domain);
                $this->info("{$domain}: registered, Apple Pay ".($d['apple_pay']['status'] ?? '?'));
            } catch (RequestException $e) {
                // Stripe answers "already exists" for a domain registered before; anything else is a real failure.
                $message = $e->response->json('error.message') ?? $e->getMessage();
                $this->warn("{$domain}: {$message}");
            }
        }

        return self::SUCCESS;
    }
}
