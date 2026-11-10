<?php

use App\Support\ViteTags;
use Illuminate\Foundation\Vite;

// ViteTags memoizes the root template's @vite output (it walked the ~1800-entry manifest on every full page load).
it('returns the same tags as @vite and keeps the preload Link header on a memo hit', function () {
    $entries = ['resources/js/app.tsx', 'resources/js/pages/dashboard.tsx'];
    $expected = (string) app(Vite::class)($entries);

    // First call builds, second is served from the memo after Octane-style flushing of the preload list.
    expect((string) ViteTags::for($entries))->toBe($expected);
    app()->forgetInstance(Vite::class);
    expect((string) ViteTags::for($entries))->toBe($expected)
        ->and(app(Vite::class)->preloadedAssets())->not->toBeEmpty();
});
