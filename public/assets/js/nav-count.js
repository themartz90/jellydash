(function () {
    const navCount = document.querySelector('[data-nav-count]');
    let hasLoaded = false;

    if (!navCount) {
        return;
    }

    // Now Playing already refreshes the full session payload every five
    // seconds and updates this badge from that response. Do not start a second
    // polling loop for the same endpoint on that page.
    if (document.querySelector('[data-now-playing-root]')) {
        return;
    }

    async function refresh() {
        const response = await fetch('/api/now-playing.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Now Playing count request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        const stats = payload.stats || {};
        navCount.textContent = String(Number(stats.active_streams || 0));
        navCount.classList.remove('is-loading');
        navCount.classList.remove('is-stale');
        navCount.removeAttribute('title');
        hasLoaded = true;
    }

    function clearLoadingState() {
        navCount.textContent = '-';
        navCount.classList.remove('is-loading');
        navCount.classList.add('is-stale');
        navCount.setAttribute('title', hasLoaded ? 'Now Playing count is unavailable' : 'Could not load Now Playing count');
    }

    if (window.JellydashFrontendTestHooks) {
        window.JellydashFrontendTestHooks.refreshNavCount = refresh;
        window.JellydashFrontendTestHooks.clearNavCount = clearLoadingState;
    }

    refresh().catch(clearLoadingState);
    window.setInterval(() => {
        refresh().catch(clearLoadingState);
    }, 10000);
}());
