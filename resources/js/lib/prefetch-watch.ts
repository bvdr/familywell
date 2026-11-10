import axios from 'axios';

// Inertia's `prefetched` event only fires when a prefetch is USED (on the visit), not when it lands, and router.prefetch
// returns nothing. Inertia sends through the shared axios instance with `Purpose: prefetch`, so watch responses here:
// that's the moment a prefetch is in, which the tab bar (how old is this tab's data?) and the app (warm the page's
// code chunk) need.

/** path => when its latest prefetch landed. */
export const prefetchedAt = new Map<string, number>();

export function watchPrefetches(onPage: (component: string) => void): void {
    axios.interceptors.response.use((response) => {
        if (response.config.headers?.['Purpose'] === 'prefetch') {
            prefetchedAt.set(new URL(response.config.url ?? '', location.href).pathname, Date.now());
            // Still the raw JSON text here (Inertia parses it later); `component` is its first key, so read it from the
            // head instead of parsing the whole page (~100KB) a second time.
            const head = typeof response.data === 'string' ? response.data.slice(0, 200) : '';
            const match = head.match(/"component":("(?:[^"\\]|\\.)*")/);
            if (match) onPage(JSON.parse(match[1]) as string);
        }
        return response;
    });
}
