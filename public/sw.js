// Service worker: PWA installability + Web Push delivery.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {
    // Network passthrough — the browser handles the request normally.
});

// A push from the server (BotPush). Show a notification in every app state;
// the OS supplies the sound. userVisibleOnly requires that we always show one.
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'Life OS' };
    }

    const title = payload.title || 'Life OS';
    const options = {
        body: payload.body || undefined,
        icon: payload.icon || '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        // Carry the click target through to notificationclick.
        data: payload.data || { url: '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Tapping the notification focuses an open Life OS window, or opens one.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                for (const client of clients) {
                    if ('focus' in client) {
                        client.navigate(url);
                        return client.focus();
                    }
                }
                return self.clients.openWindow(url);
            }),
    );
});
