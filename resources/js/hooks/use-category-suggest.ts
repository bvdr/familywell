import { useEffect, useRef } from 'react';

// Type-ahead AI category for the add/edit spend sheets. While the note is typed, ask the server
// (which holds the TypeSafe key; nothing AI-related lives in the browser) for a category and put it
// in the picker, but only while the user hasn't chosen one themselves: we fill the field when it is
// still the default OR still showing our own previous suggestion, and back off for good once
// `onManualPick` fires. A suggestion that comes back empty (model says nothing fits) clears our own
// earlier suggestion back to the default. Debounced 600ms after the last keystroke; in-flight calls
// are aborted when the input changes. Disabled entirely without a key (server 404s as well).
type Opts = {
    enabled: boolean; // key configured AND the sheet is open (flipping to true resets the manual flag)
    note: string;
    amount: string;
    currency: string;
    eventId?: number | string | null;
    category: string;
    defaultKey: string;
    setCategory: (key: string) => void;
};

export function useCategorySuggest({ enabled, note, amount, currency, eventId, category, defaultKey, setCategory }: Opts) {
    const manual = useRef(false);
    const suggested = useRef<string | null>(null);
    const latest = useRef({ category, setCategory });
    latest.current = { category, setCategory };

    useEffect(() => {
        if (enabled) {
            manual.current = false;
            suggested.current = null;
        }
    }, [enabled]);

    useEffect(() => {
        const trimmed = note.trim();
        const ours = category === defaultKey || category === suggested.current;
        if (!enabled || manual.current || !ours || trimmed.length < 3) return;

        const ctrl = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
                // Relative URL so the same-origin session cookie is sent.
                const res = await fetch(route('spending.spends.suggest', undefined, false), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: ctrl.signal,
                    body: JSON.stringify({ note: trimmed, amount: amount || null, currency: currency || null, event_id: eventId || null }),
                });
                if (!res.ok) return;
                const { category: key } = (await res.json()) as { category: string | null };
                // Re-check against the live value: the user may have picked something meanwhile.
                const { category: now, setCategory: set } = latest.current;
                if (manual.current || !(now === defaultKey || now === suggested.current)) return;
                if (key) {
                    suggested.current = key;
                    set(key);
                } else if (now === suggested.current) {
                    suggested.current = null;
                    set(defaultKey);
                }
            } catch {
                // aborted or offline: keep whatever is in the field
            }
        }, 600);

        return () => {
            window.clearTimeout(timer);
            ctrl.abort();
        };
        // `category` is intentionally read live via `latest`, not a dependency: our own setCategory
        // would otherwise retrigger the effect.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, note, amount, currency, eventId, defaultKey]);

    return { onManualPick: () => (manual.current = true) };
}
