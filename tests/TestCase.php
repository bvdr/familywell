<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The `array` cache backs the throttle rate limiter and persists across tests in a run. Since
        // RefreshDatabase resets user ids to 1 every test, and the throttle signature for an authenticated
        // request is just sha1(user id), throttled requests from unrelated tests pile into one shared
        // bucket and randomly trip `throttle:6,1` (seen as a flaky 429 on the email-verification test).
        // Flushing here gives every test a clean rate-limiter slate.
        Cache::flush();
    }
}
