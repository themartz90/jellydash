// Registers the service worker so the app is installable as a standalone PWA
// (kept in an external file because the page CSP blocks inline scripts).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    });

    const host = document.querySelector('[data-seerr-notification-host]');
    if (host) {
        const home = host.parentNode;
        const notice = host.querySelector('[data-seerr-notification]');
        const link = notice.querySelector('a');
        const status = host.querySelector('[data-seerr-notification-status]');
        let previousFocus = null;

        const currentRequest = () => document.querySelector('[data-seerr-dialog][open]')
            ? new URLSearchParams(location.search).get('request')
            : null;
        const placeNotice = () => {
            const modal = document.activeElement.closest('dialog:modal') ?? Array.from(document.querySelectorAll('dialog:modal')).at(-1);
            const destination = !notice.hidden && modal ? modal : home;
            if (host.parentNode !== destination) destination.prepend(host);
        };
        const observeDialogs = new MutationObserver((changes) => {
            if (!notice.hidden && changes.some(({ target }) => target.matches('dialog'))) placeNotice();
        });
        observeDialogs.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'] });

        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.origin !== location.origin || event.source?.scriptURL !== new URL('/sw.js', location.origin).href) return;
            if (event.data?.type === 'jellydash:current-seerr-request' && event.ports[0]) {
                event.ports[0].postMessage(currentRequest());
                return;
            }
            if (event.data?.type !== 'jellydash:seerr-request') return;
            const url = event.data.url;
            const id = typeof url === 'string' && /^\/jellyseerr\?request=([1-9]\d{0,15})$/.exec(url)?.[1];
            if (!id || !Number.isSafeInteger(Number(id)) || id === currentRequest()) return;

            if (notice.hidden) previousFocus = document.activeElement;
            link.setAttribute('href', url);
            notice.hidden = false;
            placeNotice();
            status.textContent = 'Seerr request #' + id + ' is ready to open.';
        });
        notice.querySelector('button').addEventListener('click', () => {
            notice.hidden = true;
            status.textContent = '';
            placeNotice();
            if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
        });
    }
}
