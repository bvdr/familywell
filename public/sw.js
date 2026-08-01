// FamilyWell service worker — Web Push only (no offline caching; Octane serves fresh pages).
// Shows a notification when a push arrives (even with the app closed) and focuses/opens the app on tap.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { body: event.data && event.data.text() };
    }
    const title = payload.title || 'FamilyWell';
    const url = (payload.data && payload.data.url) || payload.url || '/dashboard';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/icons/icon-192.png',
        badge: payload.badge || '/icons/icon-192.png',
        tag: payload.tag,
        data: { url },
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/dashboard';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            // Focus an existing tab (navigating it to the target) if one is open, else open a new one.
            for (const w of wins) {
                if ('focus' in w) {
                    if ('navigate' in w) w.navigate(url);
                    return w.focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(url);
        }),
    );
});
