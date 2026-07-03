// Minimal service worker: enough for PWA installability, no offline logic (V1 scope).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {
    // Network passthrough — the browser handles the request normally.
});
