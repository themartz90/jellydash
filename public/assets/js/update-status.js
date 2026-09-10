(function () {
    const root = document.querySelector('[data-update-status]');
    const updateLink = document.querySelector('[data-update-link]');
    const currentLabel = document.querySelector('[data-update-current]');

    if (!root || !updateLink || !currentLabel) {
        return;
    }

    async function load() {
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = window.setTimeout(() => controller?.abort(), 8000);
        let response;
        try {
            response = await fetch('/api/update-status.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller?.signal,
            });
        } finally {
            window.clearTimeout(timer);
        }

        if (!response.ok) {
            throw new Error('Update status request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        if (!payload || !payload.checked) {
            return;
        }

        if (payload.update_available && typeof payload.release_url === 'string') {
            updateLink.href = payload.release_url;
            updateLink.hidden = false;

            return;
        }

        if (payload.fresh && payload.latest_version === payload.current_version) {
            currentLabel.hidden = false;
        }
    }

    load().catch(() => {});
}());
