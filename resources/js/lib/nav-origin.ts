import { type Page } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';

/**
 * How the page on screen was reached, for the phone header: 'root' = opened from the tab bar or the More sheet (or a
 * cold open of the app), which shows the family top bar; 'child' = reached from inside the app (a card, a row, a
 * link in another module), which shows a back button that retraces your steps with history.back(), as many times as
 * you went forward.
 *
 * Stored per history entry with Inertia's remember(), so going back to a page brings its header back with it (a
 * module you opened from More stays 'root' after you go into one of its items and come back). A replace visit to
 * the same URL (a form that redirects back to its page) starts with an empty remembered state, so it keeps the
 * mode the URL had a moment ago.
 */
export type NavMode = 'root' | 'child';

const KEY = 'navMode';
let pendingTap = false;
let rootVisit = true; // the app's first page has nothing to go back to
let last: { url: string; mode: NavMode } | null = null;

/** Call from a tab bar / More sheet link's onClick: the visit it starts opens a module "from the top". */
export function markRootTap() {
    pendingTap = true;
}

// Read from the page object, not router.restore(): restore() falls back to the state the app booted with, so every
// page without a mode of its own would inherit the first page's 'root'.
function modeFor(page: Page): NavMode {
    const saved = page.rememberedState?.[KEY] as NavMode | undefined;
    if (saved) return saved;
    if (rootVisit) return 'root';
    return last?.url === page.url ? last.mode : 'child';
}

if (typeof window !== 'undefined') {
    // Only the visit the person started counts: prefetches and background reloads fire 'before' too.
    router.on('before', (e) => {
        if (e.detail.visit.prefetch || e.detail.visit.async) return;
        rootVisit = pendingTap;
        pendingTap = false;
    });
    const persist = (page: Page) => {
        const mode = modeFor(page);
        last = { url: page.url, mode };
        if (page.rememberedState?.[KEY] !== mode) router.remember(mode, KEY);
    };
    // navigate covers push visits and back/forward; success also covers replace visits (navigate skips those) and
    // background reloads, whose response can arrive without the remembered mode and so gets it written back.
    router.on('navigate', (e) => persist(e.detail.page));
    router.on('success', (e) => persist(e.detail.page));
}

/** The current page's mode; computed during render so the right header shows on the first frame. */
export function useNavMode(): NavMode {
    return modeFor(usePage());
}

/**
 * onClick for a page's own "‹ Parent" pill: when the page was reached from inside the app, retrace your steps like the
 * header's back button; opened from the top (or a cold open), the pill follows its link up to the parent as before.
 */
export function backClick(e: { preventDefault(): void }) {
    if (last?.mode !== 'child') return;
    e.preventDefault();
    window.history.back();
}
