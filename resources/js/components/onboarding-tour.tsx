import { router, usePage } from '@inertiajs/react';
import { ArrowRight, LoaderCircle } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useState } from 'react';

import { Homer, reactHomer } from '@/components/homer';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// The onboarding tour (design 07-08 / T07-08 / D07-08): two stops on their real home, one spotlight at a time,
// always skippable. Each stop spotlights the first VISIBLE element with its data-tour key (the phone's tab bar or
// the computer's sidebar for "your modules"; the + button for "add anything"). With nothing to point at (a
// computer has no + button) the coach mark just sits in the middle.
const STOPS = [
    { target: 'modules', title: 'onboarding.tour_1_title', body: 'onboarding.tour_1_body' },
    { target: 'add', title: 'onboarding.tour_2_title', body: 'onboarding.tour_2_body' },
] as const;
const PAD = 8;
const CARD_W = 350;

// Stop 1 points at the sidebar on a computer (D07) and at the dashboard's "Your modules" grid on tablets and
// phones (T07 / 07); either falls back to the other if it isn't on screen.
function targetsFor(key: string): string[] {
    if (key !== 'modules') return [key];
    return window.innerWidth >= 1024 ? ['modules-sidebar', 'modules'] : ['modules', 'modules-sidebar'];
}

function findTarget(stopKey: string): DOMRect | null {
    for (const key of targetsFor(stopKey)) {
        const r = findOne(key);
        if (r) return r;
    }
    return null;
}

function findOne(key: string): DOMRect | null {
    for (const el of document.querySelectorAll<HTMLElement>(`[data-tour="${key}"]`)) {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden') return r;
    }
    return null;
}

export function OnboardingTour() {
    const { tour } = usePage().props as { tour?: boolean };
    return tour ? <Tour /> : null;
}

function Tour() {
    const t = useT();
    const [i, setI] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);
    const [busy, setBusy] = useState(false);
    const stop = STOPS[i];

    const measure = useCallback(() => setRect(findTarget(stop.target)), [stop.target]);
    useLayoutEffect(measure, [measure]);
    useEffect(() => {
        window.addEventListener('resize', measure);
        window.addEventListener('scroll', measure, true);
        // Homer looks toward the first spotlight and winks at the last one.
        reactHomer(i === 0 ? 'hello' : 'wink');
        return () => {
            window.removeEventListener('resize', measure);
            window.removeEventListener('scroll', measure, true);
        };
    }, [measure, i]);

    const finish = () => router.post(route('onboarding.tour.finish'), {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const last = i === STOPS.length - 1;

    // The card sits above the spotlight when it's in the lower half of the screen (tab bar, + button), else below
    // it (the sidebar), clamped inside the viewport; centred when there's nothing to point at.
    const vw = typeof window !== 'undefined' ? window.innerWidth : 390;
    const vh = typeof window !== 'undefined' ? window.innerHeight : 844;
    const w = Math.min(CARD_W, vw - 40);
    let style: React.CSSProperties = { width: w, left: (vw - w) / 2, top: vh / 2, transform: 'translateY(-50%)' };
    if (rect) {
        const below = rect.top + rect.height / 2 < vh / 2;
        const sideways = rect.height > vh * 0.5; // a tall sidebar: open the card beside it instead
        const left = Math.min(Math.max(20, rect.left + rect.width / 2 - w / 2), vw - w - 20);
        style = sideways
            ? { width: w, left: rect.right + PAD + 16, top: Math.max(20, rect.top + 80) }
            : below
              ? { width: w, left, top: rect.bottom + PAD + 14 }
              : { width: w, left, bottom: vh - rect.top + PAD + 14 };
    }

    return (
        <div className="fixed inset-0 z-[70]" role="dialog" aria-modal aria-label={t('onboarding.tour_label')}>
            {/* The dim with a hole: a huge shadow around the spotlight box, or a plain dim with nothing to point at. */}
            {rect ? (
                <div
                    className="pointer-events-none absolute rounded-[22px] shadow-[0_0_0_9999px_rgba(14,13,7,0.62)] transition-all duration-300"
                    style={{ left: rect.left - PAD, top: rect.top - PAD, width: rect.width + PAD * 2, height: rect.height + PAD * 2 }}
                />
            ) : (
                <div className="absolute inset-0 bg-[rgba(14,13,7,0.62)]" />
            )}

            <div className="absolute rounded-[22px] bg-white p-[18px] shadow-[0_20px_50px_-12px_rgba(0,0,0,0.45)]" style={style}>
                {/* Homer perched on the coach mark, over the dark dim (light ink). */}
                <Homer face={last ? 'wink' : 'focused'} onDark className="absolute -top-[46px] right-4 size-14" />
                <div className="flex items-center justify-between pb-2">
                    <span className="text-stone font-mono text-[11px] font-semibold tracking-[0.8px] uppercase">
                        {t('onboarding.tour_stop', { n: i + 1, total: STOPS.length })}
                    </span>
                    <span className="flex gap-1" aria-hidden>
                        {STOPS.map((_, n) => (
                            <span key={n} className={cn('h-1.5 rounded-full', n === i ? 'bg-ink w-4' : 'bg-line w-1.5')} />
                        ))}
                    </span>
                </div>
                <p className="font-display text-ink text-[22px] leading-tight font-bold tracking-[-0.5px]">{t(stop.title)}</p>
                <p className="text-ink-2 pt-2 text-sm leading-normal">{t(stop.body)}</p>
                <div className="flex items-center justify-between pt-4">
                    <button type="button" disabled={busy} onClick={finish} className="text-stone text-sm font-semibold">
                        {t('onboarding.tour_skip')}
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => (last ? finish() : setI(i + 1))}
                        className="bg-ink inline-flex items-center gap-2 rounded-full px-4 py-2.5 text-sm font-bold text-white"
                    >
                        {last ? t('onboarding.tour_go') : t('onboarding.tour_next')}
                        {busy ? <LoaderCircle className="text-sun size-4 animate-spin" /> : <ArrowRight className="text-sun size-4" />}
                    </button>
                </div>
            </div>
        </div>
    );
}
