import { router } from '@inertiajs/react';

// Background saves for taps that update the screen first (optimistic UI): a tick, a delete, a quick add.
//
// Why not router.patch/post: Inertia 2 aborts an in-flight visit when the next one starts. A sync visit interrupts
// the previous one, and any visit to another URL (a save always is one) cancels async ones. Tick A then B quickly and
// A's request is aborted: usually the server got it anyway, but the screen snaps back, and in a small window the save
// never lands at all. So each save is a plain fetch, which nothing cancels; saves to the same URL are chained so a
// tick and an untick reach the server in that order; and once every save has settled, the page props reload ONCE in
// the background. The promise resolves after that reload, so the caller drops its optimistic state only when the real
// data is on screen (no flicker back to the old value in between).
//
// Resolves 'ok', 'rejected' (the server said no: validation, permission, already gone) or 'offline' (no response).
// The controllers answer with a redirect back, which `redirect: 'manual'` leaves unfollowed: no page is rendered per
// save, the one reload does that. Validation errors aren't shown from here, so use it for saves the client already
// knows are valid (a tick, a delete, a non-empty title), and router.post where the server's message matters.

export type SaveResult = 'ok' | 'rejected' | 'offline';

export function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** One request, no reload. `status` is 0 when there was no response at all. Shared with the shopping outbox. */
export async function request(method: string, url: string, body?: unknown): Promise<{ result: SaveResult; status: number }> {
    try {
        const res = await fetch(url, {
            method: method.toUpperCase(),
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
            redirect: 'manual',
        });
        return { result: res.type === 'opaqueredirect' || res.ok ? 'ok' : 'rejected', status: res.status };
    } catch {
        return { result: 'offline', status: 0 };
    }
}

const chains = new Map<string, Promise<unknown>>(); // per URL: the last save sent there, so the next one waits for it
let inFlight = 0;
let waiting: (() => void)[] = [];

export function save(method: 'post' | 'put' | 'patch' | 'delete', url: string, body?: unknown): Promise<SaveResult> {
    inFlight++;
    const sent = (chains.get(url) ?? Promise.resolve()).then(() => request(method, url, body)).then((r) => r.result);
    chains.set(url, sent);
    void sent.then(() => chains.get(url) === sent && chains.delete(url));

    return sent.then(
        (result) =>
            new Promise<SaveResult>((resolve) => {
                waiting.push(() => resolve(result));
                if (--inFlight > 0) return; // another save is still out: the reload waits for it too
                const settle = waiting;
                waiting = [];
                // Async, so it never interrupts a page visit the person started meanwhile. On no connection it
                // fails, which also clears every useOptimistic override (its 'exception' listener).
                router.reload({ async: true, onFinish: () => settle.forEach((done) => done()) });
            }),
    );
}
