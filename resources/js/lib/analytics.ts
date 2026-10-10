// Umami in the browser (hosted familywell.co only: the script tag in app.blade.php is rendered only when a
// website id is configured, so on a self-hosted install window.umami never exists and all of this no-ops).
// Server-side events (sign-up, billing, assistant use) go through App\Support\Analytics instead.

declare global {
    interface Window {
        umami?: {
            identify: (id: string, data?: Record<string, unknown>) => void;
            track: (event: string, data?: Record<string, string | number>) => void;
        };
    }
}

/** A custom event. No-op without Umami and never throws: analytics must not break the flow it measures. */
export function track(event: string, data?: Record<string, string | number>): void {
    try {
        window.umami?.track?.(event, data);
    } catch {
        // swallow
    }
}

let identified: string | null = null;

/**
 * Tie this browser's Umami session to the signed-in person's pseudonymous analytics id (the `analyticsId`
 * prop; never a name or email). Safe to call on every page: it only re-identifies when the id changes
 * (another person signed in). Umami loads with `defer`, so it may not be there yet: retry 10 x 200ms.
 */
export function identify(id: unknown, tries = 10): void {
    if (typeof id !== 'string' || id === '' || id === identified) {
        return;
    }
    if (window.umami?.identify) {
        identified = id;
        try {
            window.umami.identify(id);
        } catch {
            // swallow
        }
        return;
    }
    if (tries > 0) {
        window.setTimeout(() => identify(id, tries - 1), 200);
    }
}
