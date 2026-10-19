import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { CloudOff } from 'lucide-react';
import { useSyncExternalStore } from 'react';

import { useT } from '@/lib/i18n';

// Offline queue for the two things you do in a shop with no signal: tick an item bought, and add an item.
// Everything else in the app still just shows the offline banner (offline-banner.tsx) and rolls back.
//
// Flow: send() fires the normal Inertia request. If the browser is offline, or the request got no response at
// all (Inertia then fires neither onSuccess nor onError, only onFinish), the change goes into a queue in
// localStorage, so it survives the app being closed. The queue replays in order when the browser comes back
// online or any app request succeeds, then reloads the page props once.
//
// Replays must be safe to run twice (a request can reach the server and lose only its response):
//   - ticks send an explicit `bought` value, never the bare toggle, so a replay sets rather than flips;
//   - adds carry a `client_key` the server remembers for a day and ignores on repeat (ShoppingController).

export type QueuedOp =
    | { kind: 'bought'; key: string; method: 'patch'; url: string; data: { bought: boolean }; itemId: number }
    | { kind: 'add'; key: string; method: 'post'; url: string; data: Record<string, unknown>; page: string; name: string };

const STORAGE_KEY = 'fw.shopping-outbox';
let ops: QueuedOp[] = read();
const listeners = new Set<() => void>();

// localStorage can throw (private mode, blocked storage): the queue then lives in memory for this tab only.
function read(): QueuedOp[] {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]') as QueuedOp[];
    } catch {
        return [];
    }
}
function write(next: QueuedOp[]) {
    ops = next;
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(ops));
    } catch {
        // in memory only, see read()
    }
    listeners.forEach((l) => l());
}

function enqueue(op: QueuedOp) {
    // A queued add replays with nobody there to answer "Milk is already on this list", and a 422 would drop it,
    // so it goes in as add_anyway: a possible duplicate row beats a silently lost item.
    if (op.kind === 'add') op = { ...op, data: { ...op.data, add_anyway: true } };
    // Ticking the same item twice offline keeps only the latest value.
    write([...ops.filter((o) => !(op.kind === 'bought' && o.kind === 'bought' && o.itemId === op.itemId)), op]);
}

// Send now, or queue on no connection. `onQueued` runs instead of onSuccess when the change was queued, so the
// caller can close its sheet just as it would on success.
export function send(op: QueuedOp, options: VisitOptions & { onQueued?: () => void } = {}) {
    const { onQueued, ...visit } = options;
    const queue = () => {
        enqueue(op);
        onQueued?.();
    };
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        queue();
        visit.onFinish?.({} as never);
        return;
    }
    let answered = false; // the server responded (success or validation error), or another visit cancelled this one
    router[op.method](op.url, op.data as never, {
        ...visit,
        onSuccess: (page) => {
            answered = true;
            return visit.onSuccess?.(page);
        },
        onError: (errors) => {
            answered = true;
            return visit.onError?.(errors);
        },
        onCancel: () => {
            answered = true;
            return visit.onCancel?.();
        },
        onFinish: (v) => {
            if (!answered) queue();
            return visit.onFinish?.(v);
        },
    });
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

let flushing = false;
// Replays the queue in order, outside Inertia (a plain fetch per change) so no page visit happens per item.
// Stops at the first network failure and keeps the rest for next time. A 4xx other than an expired session
// (the item was deleted meanwhile, validation) can never succeed, so that change is dropped.
// Sent changes leave the queue only after the page reload lands, so a ticked item doesn't flicker back to the
// stale server value in between; `flushing` stays on until then so a 'success' event can't resend them.
export async function flush() {
    if (flushing || ops.length === 0 || (typeof navigator !== 'undefined' && !navigator.onLine)) return;
    flushing = true;
    const settled = new Set<string>();
    for (const op of ops) {
        let res: Response;
        try {
            res = await fetch(op.url, {
                method: op.method.toUpperCase(),
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify(op.data),
                credentials: 'same-origin',
                redirect: 'manual', // the controllers answer with a redirect back; following it would render a page for nothing
            });
        } catch {
            break; // still no connection
        }
        const done = res.type === 'opaqueredirect' || res.ok;
        if (!done && (res.status === 401 || res.status === 419 || res.status === 429 || res.status >= 500)) break;
        settled.add(op.key);
    }
    const finish = () => {
        write(ops.filter((o) => !settled.has(o.key)));
        flushing = false;
    };
    if (settled.size > 0) router.reload({ onFinish: finish });
    else finish();
}

// Wired once from app.tsx.
export function startShoppingOutbox() {
    window.addEventListener('online', () => void flush());
    router.on('success', () => void flush());
    void flush();
}

export function useShoppingOutbox(): QueuedOp[] {
    return useSyncExternalStore(
        (l) => {
            listeners.add(l);
            return () => listeners.delete(l);
        },
        () => ops,
        () => ops,
    );
}

// A new key per add attempt; the server ignores a second request with the same one.
export const newClientKey = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

// "Waiting to sync": adds queued from this page, so an item added offline doesn't seem to vanish. Ticks need no
// block: the page shows them through the queued overlay (queuedBought).
export function PendingAdds() {
    const t = useT();
    const page = typeof window !== 'undefined' ? window.location.pathname : '';
    const names = useShoppingOutbox()
        .filter((o) => o.kind === 'add' && o.page === page)
        .map((o) => (o.kind === 'add' ? o.name : ''));
    if (names.length === 0) return null;

    return (
        <div className="border-border text-muted-foreground flex items-start gap-3 rounded-2xl border border-dashed px-4 py-3 text-sm">
            <CloudOff className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 flex-1">
                <p className="text-foreground font-medium">{t('shopping.waiting_to_sync', { n: names.length })}</p>
                <p className="break-words">{names.join(', ')}</p>
            </div>
        </div>
    );
}

// Ticks waiting in the queue, by item id, for the page's isBought().
export function useQueuedBought(): Map<number, boolean> {
    const ops = useShoppingOutbox();
    return new Map(ops.flatMap((o) => (o.kind === 'bought' ? [[o.itemId, o.data.bought] as [number, boolean]] : [])));
}
