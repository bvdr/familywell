<?php

use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Context;

it('stores the active family in Context so it propagates to queued jobs', function () {
    $family = Family::factory()->create();

    CurrentFamily::set($family->id);

    // Laravel captures the current Context into queued job payloads and restores it in the worker,
    // so CurrentFamily::id() resolves inside jobs without any per-job wiring.
    expect(Context::get('family_id'))->toBe($family->id)
        ->and(CurrentFamily::id())->toBe($family->id);

    CurrentFamily::forget();
    expect(CurrentFamily::id())->toBeNull();
});
