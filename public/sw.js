// Service worker for Jellydash.
//
// Two jobs:
//   1. Its presence (with a fetch handler) lets Android install Jellydash as a
//      real standalone PWA (a WebAPK) with its own window, no browser UI,
//      and a maskable icon, instead of a plain browser shortcut.
//   2. It receives Web Push messages and shows them, even
//      when the app is closed.
// It deliberately does NOT pre-cache anything: the dashboard is live data and
// assets are already cache-busted, so there is nothing to go stale.

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Intentionally a no-op. Its presence is all Chrome needs to treat the app
    // as installable; by NOT intercepting the launch navigation we avoid a
    // service-worker cold start in the critical path, which was causing the
    // blank flash between the splash screen and the page. The browser handles
    // every request directly, exactly as before install.
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'Jellydash';
    const options = {
        body: data.body || '',
        icon: '/assets/img/icon-192.png',
        // Android masks this small status-bar image, so it needs transparency.
        badge: '/assets/img/notification-badge.png?v=1',
        tag: data.tag || 'jellydash',
        data: { url: data.url || '/now-playing' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

function seerrRequestId(url) {
    const ids = url.searchParams.getAll('request');
    if (url.pathname !== '/jellyseerr' || url.searchParams.has('fragment') || ids.length !== 1) return null;
    const [id] = ids;
    return /^[1-9]\d{0,15}$/.test(id) && Number.isSafeInteger(Number(id)) ? id : null;
}

function showsSeerrRequest(client, id) {
    return new Promise((resolve) => {
        const channel = new MessageChannel();
        const finish = (matches) => {
            clearTimeout(timeout);
            channel.port1.close();
            resolve(matches);
        };
        // Client.url can predate a request opened or closed with pushState.
        const timeout = setTimeout(() => finish(false), 200);
        channel.port1.onmessage = (event) => finish(event.data === id);
        try {
            client.postMessage({ type: 'jellydash:current-seerr-request' }, [channel.port2]);
        } catch (error) {
            channel.port2.close();
            finish(false);
        }
    });
}

// Tapping a notification keeps unrelated open pages in place.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/now-playing';

    event.waitUntil((async () => {
        let targetUrl;
        try {
            targetUrl = new URL(target, self.location.origin);
        } catch (e) {
            targetUrl = new URL('/now-playing', self.location.origin);
        }
        if (targetUrl.origin !== self.location.origin) {
            targetUrl = new URL('/now-playing', self.location.origin);
        }
        const id = seerrRequestId(targetUrl);
        const requestPath = id !== null && targetUrl.search === '?request=' + id && !targetUrl.hash
            ? '/jellyseerr?request=' + id
            : null;
        if (targetUrl.pathname === '/jellyseerr') {
            targetUrl = new URL(requestPath ?? '/jellyseerr', self.location.origin);
        }

        const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const appWindows = clientList.filter((client) => new URL(client.url).origin === self.location.origin && 'focus' in client);
        let exact;
        if (requestPath) {
            const seerrWindows = appWindows.filter((client) => new URL(client.url).pathname === '/jellyseerr');
            const matches = await Promise.all(seerrWindows.map((client) => showsSeerrRequest(client, id)));
            exact = seerrWindows[matches.indexOf(true)];
        } else {
            exact = appWindows.find((client) => client.url === targetUrl.href);
        }
        if (exact) {
            await exact.focus();
            return;
        }
        // openWindow can reuse an installed app window and discard its edits.
        // Keep an existing Jellydash page in place when the target is not open.
        const existing = appWindows[0];
        if (existing) {
            if (requestPath) {
                existing.postMessage({ type: 'jellydash:seerr-request', url: requestPath });
            }
            await existing.focus();
            return;
        }
        if (self.clients.openWindow) {
            await self.clients.openWindow(targetUrl.href);
        }
    })());
});
