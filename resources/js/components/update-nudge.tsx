import { Link, usePage } from '@inertiajs/react';
import { Check, LoaderCircle, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import { useT } from '@/lib/i18n';

// "New version? You pick the moment." (design ntvzN): when a deploy lands while the app is open, a dark pill above the
// tab bar offers Refresh. Never a pop-up, never a forced reload.
// - WHEN: /app-version no longer matches the version this page was built with. Checked on open, when the app comes
//   back to the front, and every hour it stays open.
// - WAITS: held back while a sheet is open or something is being typed in, so a reload never eats a draft.
// - LATER: a sideways swipe (Later on desktop) hides it for this visit; the next fresh start loads the new version.
// - AFTER: back on the same screen and scroll, a toast says you're on the latest for 4 seconds.
// What's new opens the settings page (resources/changelog.json), not the design's W1 sheet.

const HOUR = 60 * 60 * 1000;
const AFTER_KEY = 'fw-updated-scroll'; // sessionStorage: set right before the reload, read once after it

// One check loop for the whole visit (the layout remounts on every page; this module doesn't).
let ready = false;
let dismissed = false;
let started = false;
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((l) => l());
const subscribe = (l: () => void) => {
    listeners.add(l);
    return () => listeners.delete(l);
};

let pageVersion: string | undefined;

// Is a newer build running than this page's? Also called by Me's Version row ("check now"), which brings the pill
// back even if it was swiped away.
export async function checkForUpdate(): Promise<boolean> {
    if (ready) return true;
    try {
        const r = await fetch('/app-version', { cache: 'no-store', credentials: 'same-origin' });
        const v = r.ok ? ((await r.json()) as { version?: string | null }).version : null;
        if (v && pageVersion && v !== pageVersion) {
            ready = true;
            emit();
        }
    } catch {
        // offline: try again next time
    }
    return ready;
}

export const showUpdateAgain = () => {
    dismissed = false;
    emit();
};

function start(version: string | undefined) {
    if (started || !version) return;
    started = true;
    pageVersion = version;
    void checkForUpdate();
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && void checkForUpdate());
    window.setInterval(checkForUpdate, HOUR);
}

// A sheet is open, or the person is typing: not the moment.
const busy = () => {
    const a = document.activeElement as HTMLElement | null;
    return !!document.querySelector('[role="dialog"]') || !!a?.closest('input, textarea, select, [contenteditable="true"]');
};

const scroller = () => document.querySelector<HTMLElement>('[scroll-region]');

export function UpdateNudge() {
    const t = useT();
    const { version } = usePage();
    const isReady = useSyncExternalStore(subscribe, () => ready && !dismissed);
    const [free, setFree] = useState(false);
    const [updating, setUpdating] = useState(false);
    const [after, setAfter] = useState(false);
    const [dx, setDx] = useState(0);
    const startX = useRef<number | null>(null);

    useEffect(() => start(version ?? undefined), [version]);

    // AFTER: back from a Refresh. Same URL (a reload keeps it); put the scroll back, say so for 4 seconds.
    useEffect(() => {
        let saved: string | null = null;
        try {
            saved = sessionStorage.getItem(AFTER_KEY);
            sessionStorage.removeItem(AFTER_KEY);
        } catch {
            return;
        }
        if (saved === null) return;
        const y = Number(saved);
        requestAnimationFrame(() => {
            const s = scroller();
            if (s && s.scrollHeight > s.clientHeight) s.scrollTop = y;
            else window.scrollTo(0, y);
        });
        setAfter(true);
        const id = window.setTimeout(() => setAfter(false), 4000);
        return () => window.clearTimeout(id);
    }, []);

    // WAITS: once ready, look every second until nothing is open or being typed in.
    useEffect(() => {
        if (!isReady) return;
        const tick = () => setFree(!busy());
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [isReady]);

    const refresh = () => {
        if (updating) return;
        setUpdating(true);
        try {
            const s = scroller();
            sessionStorage.setItem(AFTER_KEY, String(s && s.scrollHeight > s.clientHeight ? s.scrollTop : window.scrollY));
        } catch {
            // storage blocked: reload anyway, just without the toast
        }
        // Reload only after "Updating…" and the spinner have been painted: reloading in the same tick left the
        // old pill on screen, unchanged, until the new page arrived, so people kept tapping (user, 2026-11-03).
        requestAnimationFrame(() => requestAnimationFrame(() => window.location.reload()));
    };
    const later = () => {
        dismissed = true;
        emit();
    };

    const pill =
        'bg-primary text-primary-foreground pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-full py-1.5 pr-1.5 pl-2 shadow-lg';
    const badge = 'bg-accent-strong text-accent-strong-foreground flex size-9 shrink-0 items-center justify-center rounded-full';
    // Phone: just above the tab bar, where every toast sits. Desktop: bottom-left of the main area, clear of the sidebar.
    const spot =
        'pointer-events-none fixed inset-x-0 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-50 flex justify-center px-4 md:inset-x-auto md:bottom-6 md:left-[calc(var(--sidebar-width,16rem)+1.5rem)] md:justify-start';

    if (after && !(isReady && free)) {
        return (
            <div className={spot} role="status">
                <div className={`${pill} animate-in fade-in py-2.5 pl-3`}>
                    <span className="bg-accent-strong text-accent-strong-foreground flex size-6 shrink-0 items-center justify-center rounded-full">
                        <Check className="size-4" />
                    </span>
                    <span className="flex-1 text-[15px] font-semibold">{t('update_nudge.done')}</span>
                    <Link href="/settings/whats-new" className="text-accent-strong shrink-0 px-2 text-sm font-bold">
                        {t('update_nudge.whats_new')}
                    </Link>
                </div>
            </div>
        );
    }
    if (!isReady || !(free || updating)) return null;

    return (
        <div className={spot} role="status">
            <div
                className={`${pill} touch-pan-y`}
                style={{ transform: dx ? `translateX(${dx}px)` : undefined, opacity: dx ? Math.max(0.2, 1 - Math.abs(dx) / 200) : undefined }}
                // LATER on a phone: swipe it sideways.
                onPointerDown={(e) => e.pointerType !== 'mouse' && !updating && (startX.current = e.clientX)}
                onPointerMove={(e) => startX.current !== null && setDx(e.clientX - startX.current)}
                onPointerUp={() => {
                    startX.current = null;
                    if (Math.abs(dx) > 80) later();
                    setDx(0);
                }}
                onPointerCancel={() => {
                    startX.current = null;
                    setDx(0);
                }}
            >
                <span className={badge}>
                    <Sparkles className="size-[18px]" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] leading-snug font-semibold">
                        {t(updating ? 'update_nudge.updating' : 'update_nudge.title')}
                    </span>
                    <span className="block text-[13px] leading-snug opacity-75">
                        {t(updating ? 'update_nudge.updating_sub' : 'update_nudge.sub')}
                    </span>
                </span>
                {updating ? (
                    <span className="flex size-10 shrink-0 items-center justify-center" aria-hidden>
                        <LoaderCircle className="size-5 animate-spin opacity-75" />
                    </span>
                ) : (
                    <>
                        {/* Desktop has no swipe, so Later is a word there. */}
                        <button
                            type="button"
                            onClick={later}
                            className="hidden shrink-0 px-2 text-sm font-semibold opacity-75 hover:opacity-100 md:block"
                        >
                            {t('update_nudge.later')}
                        </button>
                        <button
                            type="button"
                            onClick={refresh}
                            className="bg-accent-strong text-accent-strong-foreground h-10 shrink-0 rounded-full px-4 text-sm font-bold"
                        >
                            {t('update_nudge.refresh')}
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}
