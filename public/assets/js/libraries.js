(function () {
    const root = document.querySelector('[data-libraries-root]');
    const summaryRoot = document.querySelector('[data-library-summary]');
    const gridRoot = document.querySelector('[data-library-grid]');
    const status = document.querySelector('[data-libraries-status]');

    if (!root || !summaryRoot || !gridRoot || !status) {
        return;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    function icon(library) {
        if (library.isTv) {
            return '<svg class="icon-filled" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.707 2.293l3.293 3.292l3.293 -3.292a1 1 0 0 1 1.32 -.083l.094 .083a1 1 0 0 1 0 1.414l-2.293 2.293h4.586a3 3 0 0 1 3 3v9a3 3 0 0 1 -3 3h-14a3 3 0 0 1 -3 -3v-9a3 3 0 0 1 3 -3h4.585l-2.292 -2.293a1 1 0 0 1 1.414 -1.414"></path></svg>';
        }

        if (library.isAnime) {
            return '<svg class="icon-filled" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M16 19a1 1 0 0 1 0 -2a1 1 0 0 0 1 -1c0 -1.333 2 -1.333 2 0a1 1 0 0 0 1 1c1.333 0 1.333 2 0 2a1 1 0 0 0 -1 1c0 1.333 -2 1.333 -2 0a1 1 0 0 0 -1 -1"></path><path d="M3 11a5 5 0 0 0 5 -5c0 -1.333 2 -1.333 2 0a5 5 0 0 0 5 5c1.333 0 1.333 2 0 2a5 5 0 0 0 -5 5a1 1 0 0 1 -2 0a5 5 0 0 0 -5 -5c-1.333 0 -1.333 -2 0 -2"></path><path d="M16 7a1 1 0 0 1 0 -2a1 1 0 0 0 1 -1c0 -1.333 2 -1.333 2 0a1 1 0 0 0 1 1c1.333 0 1.333 2 0 2a1 1 0 0 0 -1 1c0 1.333 -2 1.333 -2 0a1 1 0 0 0 -1 -1"></path></svg>';
        }

        if (library.isEvent) {
            return '<svg class="icon-filled" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M16 2a1 1 0 0 1 .993 .883l.007 .117v1h1a3 3 0 0 1 2.995 2.824l.005 .176v12a3 3 0 0 1 -2.824 2.995l-.176 .005h-12a3 3 0 0 1 -2.995 -2.824l-.005 -.176v-12a3 3 0 0 1 2.824 -2.995l.176 -.005h1v-1a1 1 0 0 1 1.993 -.117l.007 .117v1h6v-1a1 1 0 0 1 1 -1m3 8h-14v8.625c0 .705 .386 1.286 .883 1.366l.117 .009h12c.513 0 .936 -.53 .993 -1.215l.007 -.16zm-9 4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1z"></path></svg>';
        }

        return '<svg class="icon-filled" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.117 7.625a1 1 0 0 0 -.564 .1l-4.553 2.275v4l4.553 2.275a1 1 0 0 0 1.447 -.892v-6.766a1 1 0 0 0 -.883 -.992z"></path><path d="M5 5c-1.645 0 -3 1.355 -3 3v8c0 1.645 1.355 3 3 3h8c1.645 0 3 -1.355 3 -3v-8c0 -1.645 -1.355 -3 -3 -3z"></path></svg>';
    }

    function renderSummary(items) {
        summaryRoot.classList.remove('is-loading');
        summaryRoot.innerHTML = (Array.isArray(items) ? items : []).map((item) => `
            <article class="library-summary-card">
                <span><i style="background: ${escapeAttr(item.color)}"></i>${escapeHtml(item.label)}</span>
                <strong>${escapeHtml(item.value)}</strong>
                <small>${escapeHtml(item.sub)}</small>
            </article>
        `).join('');
    }

    function stat(label, value) {
        return `
            <div>
                <dt>${escapeHtml(label)}</dt>
                <dd>${escapeHtml(value)}</dd>
            </div>
        `;
    }

    function itemLabel(library) {
        if (library.available === false) {
            return 'Item counts unavailable';
        }

        const unit = {
            tv: 'episodes',
            anime: 'episodes',
            music: 'songs',
            videos: 'videos',
        }[library.kind] || 'items';

        return `${escapeHtml(library.totalFiles)} ${unit}`;
    }

    function renderLibrary(library) {
        const breakdown = Array.isArray(library.breakdown) ? library.breakdown : [];
        const unavailable = library.available === false;

        return `
            <article class="library-card${unavailable ? ' is-unavailable' : ''}" style="--library-accent: ${escapeAttr(library.accent)}; --library-chip-bg: ${escapeAttr(library.chipBg)}; --library-chip-border: ${escapeAttr(library.chipBorder)};">
                <div class="library-banner">
                    <span class="library-banner-art" style="background-image: ${escapeAttr(library.banner)}"></span>
                    <span class="library-banner-overlay"></span>
                    <span class="library-type-chip">${icon(library)}${escapeHtml(library.type)}</span>
                    <div class="library-title-block">
                        <span class="library-glyph">${escapeHtml(library.glyph)}</span>
                        <div>
                            <h2 class="library-title">${escapeHtml(library.name)}</h2>
                            <p>${itemLabel(library)}</p>
                        </div>
                    </div>
                </div>

                <div class="library-body">
                    ${unavailable ? `
                        <div class="library-unavailable-note">
                            <strong>Live item counts are unavailable.</strong>
                            <span>Recorded playback stats are still shown below.</span>
                        </div>
                    ` : ''}

                    <dl class="library-stat-grid">
                        ${stat('Total Items', library.totalFiles)}
                        ${stat('Total Plays', library.totalPlays)}
                        ${stat(library.playbackEstimated ? 'Estimated Playback' : 'Total Playback', library.playback)}
                        ${stat('Last Activity', library.lastActivity)}
                    </dl>

                    <div class="library-last-played">
                        <span>Last Played</span>
                        <strong>${escapeHtml(library.lastPlayed)}</strong>
                        <small>${escapeHtml(library.lastUser)}</small>
                    </div>

                    ${breakdown.length ? `<div class="library-breakdown">
                        ${breakdown.map((item) => `<span><strong style="color: ${escapeAttr(item.color)}">${escapeHtml(item.value)}</strong>${escapeHtml(item.label)}</span>`).join('')}
                    </div>` : ''}
                </div>
            </article>
        `;
    }

    function renderLibraries(libraries) {
        gridRoot.classList.remove('is-loading');

        if (!Array.isArray(libraries) || libraries.length === 0) {
            gridRoot.innerHTML = `
                <article class="library-empty-state">
                    <strong>No matching Jellyfin libraries found.</strong>
                    <span>Expected TV Shows, Movies, Stand-Up Comedy, Anime, and PPV & Events.</span>
                </article>
            `;
            return;
        }

        gridRoot.innerHTML = libraries.map(renderLibrary).join('');
    }

    function setStatus(text, state = 'ok') {
        status.classList.toggle('is-error', state === 'error');
        status.classList.toggle('is-warning', state === 'warning');
        status.innerHTML = '<i></i>' + escapeHtml(text);
    }

    async function loadLibraries() {
        const response = await fetch('/api/libraries.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Libraries request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        renderSummary(payload.summary);
        renderLibraries(payload.libraries);
        const state = payload.partial || payload.stale ? 'warning' : 'ok';
        setStatus(payload.refreshedLabel || (payload.cached ? 'Cached library stats' : 'Live from Jellyfin'), state);
    }

    loadLibraries().catch(() => {
        setStatus('Could not load library stats', 'error');
        gridRoot.classList.remove('is-loading');
        gridRoot.innerHTML = `
            <article class="library-empty-state">
                <strong>Could not load Jellyfin libraries.</strong>
                <span>Try refreshing once Jellyfin responds again.</span>
            </article>
        `;
    });
}());
