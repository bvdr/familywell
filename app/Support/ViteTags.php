<?php

namespace App\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * The root template's script/style/preload tags, built once per page component per worker instead of on every full
 * page load. Laravel's Vite helper walks the whole build manifest for each CSS file and runs unique() over entries
 * that each carry that manifest; ours has ~1800 entries (lucide's DynamicIcon gives every icon its own chunk), so
 * `@vite` alone cost ~260ms locally and ~480ms in prod on every app open / refresh (measured 2026-11-10). The output
 * only depends on the entry points and the manifest, which only changes on deploy, and a deploy restarts the Octane
 * workers, so a per-process memo is never stale. Hot reload (npm run dev) is never memoized.
 */
final class ViteTags
{
    /** @var array<string, array{0: HtmlString, 1: array<string, mixed>}> tags + the preloads they registered */
    private static array $memo = [];

    /** @param  list<string>  $entrypoints */
    public static function for(array $entrypoints): HtmlString
    {
        $vite = app(Vite::class);
        if ($vite->isRunningHot()) {
            return $vite($entrypoints);
        }

        [$html, $preloads] = self::$memo[implode('|', $entrypoints)] ??= [$vite($entrypoints), $vite->preloadedAssets()];
        // Building the tags also records the preloads that AddLinkHeadersForPreloadedAssets turns into the response's
        // Link header, and Octane empties that list between requests (FlushVite). Put it back on a memo hit, or every
        // page after the first would lose its Link header. Vite has no setter, hence the bound closure.
        (fn () => $this->preloadedAssets = $preloads + $this->preloadedAssets)->call($vite);

        return $html;
    }
}
