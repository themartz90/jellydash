(function () {
    const root = document.querySelector('[data-update-status]');
    const openButton = document.querySelector('[data-release-changes]');
    const openLabel = document.querySelector('[data-release-changes-label]');
    const dialog = document.querySelector('[data-release-dialog]');

    if (!root || !openButton || !openLabel || !dialog || typeof dialog.showModal !== 'function') {
        document.dispatchEvent(new CustomEvent('jellydash:release-dialog-settled'));
        return;
    }

    const version = String(root.dataset.appVersion || '').trim();
    if (!/^\d+\.\d+\.\d+$/.test(version)) {
        document.dispatchEvent(new CustomEvent('jellydash:release-dialog-settled'));
        return;
    }

    const versionLabel = dialog.querySelector('[data-release-version]');
    const title = dialog.querySelector('[data-release-title]');
    const summary = dialog.querySelector('[data-release-summary]');
    const highlights = dialog.querySelector('[data-release-highlights]');
    const links = dialog.querySelector('[data-release-links]');
    const closeButtons = dialog.querySelectorAll('[data-release-close]');

    if (!versionLabel || !title || !summary || !highlights || !links || closeButtons.length === 0) {
        settled();
        return;
    }

    const storageKey = 'jellydash.release-highlights.seen.' + version;

    function settled() {
        document.dispatchEvent(new CustomEvent('jellydash:release-dialog-settled'));
    }

    function wasSeen() {
        try {
            return window.localStorage.getItem(storageKey) === '1';
        } catch (error) {
            return true;
        }
    }

    function rememberSeen() {
        try {
            window.localStorage.setItem(storageKey, '1');
        } catch (error) {
            // Storage can be blocked. Manual opening still works.
        }
    }

    function validLink(link) {
        if (!link || typeof link.label !== 'string' || typeof link.url !== 'string') {
            return false;
        }

        try {
            const url = new URL(link.url);

            return url.protocol === 'https:'
                && url.hostname === 'github.com'
                && url.pathname.startsWith('/themartz90/jellydash/');
        } catch (error) {
            return false;
        }
    }

    function validPayload(payload) {
        return payload
            && payload.version === version
            && typeof payload.auto_show === 'boolean'
            && typeof payload.title === 'string'
            && payload.title.trim() !== ''
            && typeof payload.summary === 'string'
            && payload.summary.trim() !== ''
            && Array.isArray(payload.highlights)
            && payload.highlights.length > 0
            && payload.highlights.every((item) => typeof item === 'string' && item.trim() !== '')
            && Array.isArray(payload.links)
            && payload.links.length > 0
            && payload.links.every(validLink);
    }

    function render(payload) {
        versionLabel.textContent = 'Jellydash v' + payload.version;
        title.textContent = payload.title;
        summary.textContent = payload.summary;
        highlights.replaceChildren();
        links.replaceChildren();

        payload.highlights.forEach((item) => {
            const row = document.createElement('li');
            row.textContent = item;
            highlights.appendChild(row);
        });

        payload.links.forEach((item) => {
            const link = document.createElement('a');
            link.href = item.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = item.label;
            links.appendChild(link);
        });

        openLabel.textContent = 'See v' + payload.version + ' changes';
        openButton.hidden = false;

        openButton.addEventListener('click', () => {
            dialog.showModal();
        });
    }

    closeButtons.forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    fetch('/assets/release-highlights/' + encodeURIComponent(version) + '.json', {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Release highlights request failed with HTTP ' + response.status);
            }

            return response.json();
        })
        .then((payload) => {
            if (!validPayload(payload)) {
                settled();
                return;
            }

            render(payload);
            if (payload.auto_show && !wasSeen()) {
                rememberSeen();
                dialog.addEventListener('close', settled, { once: true });
                dialog.showModal();
                return;
            }

            settled();
        })
        .catch(() => settled());
}());
