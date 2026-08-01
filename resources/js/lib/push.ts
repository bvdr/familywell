// Web Push client: register the service worker and manage this device's push subscription.
// iOS caveat: push only works on iOS 16.4+ when the PWA is installed to the Home Screen.

export function pushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

// True on iOS Safari when the app is NOT running as an installed PWA (push won't work there yet).
export function iosNeedsInstall(): boolean {
    if (typeof window === 'undefined') return false;
    const iOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true;
    return iOS && !standalone;
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

// Small CSRF-aware JSON call (these endpoints return 204, so they can't go through Inertia's router).
async function api(url: string, method: string, body?: unknown): Promise<boolean> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.ok;
}

// Register the service worker (idempotent). Called on app boot so pushes are received + clickable.
export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
    if (!pushSupported()) return null;
    try {
        return await navigator.serviceWorker.register('/sw.js');
    } catch {
        return null;
    }
}

export async function isSubscribed(): Promise<boolean> {
    if (!pushSupported()) return false;
    const reg = await navigator.serviceWorker.getRegistration();
    return !!(await reg?.pushManager.getSubscription());
}

// Ask for permission, subscribe the browser, and register the subscription server-side. Returns true
// only when fully subscribed. Throws-free: returns false on denial/failure.
export async function subscribeToPush(vapidPublicKey: string): Promise<boolean> {
    if (!pushSupported() || !vapidPublicKey) return false;
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const reg = (await navigator.serviceWorker.getRegistration()) ?? (await navigator.serviceWorker.register('/sw.js'));
    await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });
    const json = sub.toJSON() as { endpoint?: string; keys?: { p256dh?: string; auth?: string } };
    const ok = await api(route('push.subscribe'), 'POST', { endpoint: json.endpoint, keys: json.keys });
    if (!ok) await sub.unsubscribe();
    return ok;
}

export async function unsubscribeFromPush(): Promise<void> {
    if (!pushSupported()) return;
    const reg = await navigator.serviceWorker.getRegistration();
    const sub = await reg?.pushManager.getSubscription();
    if (!sub) return;
    await api(route('push.unsubscribe'), 'DELETE', { endpoint: sub.endpoint });
    await sub.unsubscribe();
}

export async function sendTestPush(): Promise<boolean> {
    return api(route('push.test'), 'POST');
}

// Save which categories the user wants pushed (account-level, all their devices).
export async function savePushPrefs(prefs: Record<string, boolean>): Promise<boolean> {
    return api(route('push.prefs'), 'PATCH', { prefs });
}
