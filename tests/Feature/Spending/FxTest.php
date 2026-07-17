<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Spending\Fx;

beforeEach(fn () => Cache::flush());

it('returns identity for the same currency without any HTTP call', function () {
    Http::fake(); // any real call would fail the test
    expect(Fx::rate('EUR', 'EUR'))->toBe(1.0)
        ->and(Fx::convert(4800, 'EUR', 'EUR'))->toBe(4800);
    Http::assertNothingSent();
});

it('converts minor units using the fetched ECB rate', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response(['rates' => ['RON' => 5.0]])]);

    expect(Fx::rate('EUR', 'RON'))->toBe(5.0)
        ->and(Fx::convert(1000, 'EUR', 'RON'))->toBe(5000); // 10.00 EUR -> 50.00 RON
});

it('falls back to the secondary provider when the primary has no rate', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(['rates' => []]),
        'open.er-api.com/*' => Http::response(['rates' => ['USD' => 1.1]]),
    ]);

    expect(Fx::rate('EUR', 'USD'))->toBe(1.1);
});
