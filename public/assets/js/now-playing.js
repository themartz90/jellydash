(function () {
    const root = document.querySelector('[data-now-playing-root]');
    const label = document.querySelector('[data-live-label]');
    const dot = document.querySelector('[data-live-dot]');

    if (!root || !label || !dot) {
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

    function methodBadge(stream) {
        const isTranscode = Boolean(stream.isTranscode);
        return `
            <span class="method-badge ${isTranscode ? 'is-transcode' : 'is-direct'}">
                <i></i>
                <span>${escapeHtml(stream.methodLabel || (isTranscode ? 'Transcoding' : 'Direct Play'))}</span>
            </span>
        `;
    }

    function progress(stream, large) {
        return `
            <div class="progress-block ${large ? 'is-large' : ''}">
                <div class="progress-track"><span style="width: ${escapeAttr(stream.progressPct || '0%')}"></span></div>
                <div class="progress-labels">
                    <span>${escapeHtml(stream.timeLabel || '0:00 / 0:00')}</span>
                    <span>${escapeHtml(stream.remaining || '0 min left')}</span>
                </div>
            </div>
        `;
    }

    function watcher(stream, large) {
        const avatarUrl = stream.avatarUrl || '';
        const avatarClass = 'watcher-avatar' + (large ? ' is-large' : '');
        const img = avatarUrl
            ? `<img class="watcher-avatar-image" data-avatar-img src="${escapeAttr(avatarUrl)}" alt="">`
            : '';

        return `
            <div class="watcher-row ${large ? 'is-large' : ''}">
                <span class="${avatarClass}" style="background: ${escapeAttr(stream.avatarBg || '')}">${escapeHtml(stream.initials || 'U')}${img}</span>
                <span>
                    <strong>${escapeHtml(stream.user || 'Unknown user')}</strong>
                    <small>${escapeHtml(stream.deviceLine || '')}</small>
                </span>
            </div>
        `;
    }

    function card(stream) {
        return `
            <article class="stream-card">
                <div class="stream-backdrop" style="background-image: ${escapeAttr(stream.backdrop || '')}"></div>
                <div class="stream-card-overlay"></div>
                <div class="stream-watermark">${escapeHtml(stream.initials || '')}</div>

                <div class="stream-card-content">
                    <div class="stream-card-top">
                        <span class="now-pill ${stream.isPaused ? 'is-paused' : ''} ${stream.isLive && !stream.isPaused ? 'is-live-tv' : ''}"><i></i>${escapeHtml(stream.statusLabel || 'Now Playing')}</span>
                        <div class="playback-stack">
                            ${methodBadge(stream)}
                            <span class="quality-chip">${escapeHtml(stream.quality || '')}</span>
                        </div>
                    </div>

                    <div class="stream-card-spacer"></div>

                    <div>
                        <div class="kind-label">${escapeHtml(stream.kindLabel || '')}</div>
                        <h3>${escapeHtml(stream.title || 'Unknown title')}</h3>
                        <p>${escapeHtml(stream.subtitle || '')}</p>
                    </div>

                    ${watcher(stream, false)}
                    ${progress(stream, false)}
                </div>
            </article>
        `;
    }

    function emptyState() {
        return `
            <div class="empty-state">
                <div class="empty-orbit" aria-hidden="true">
                    <span></span>
                    <svg viewBox="0 0 24 24" role="img" focusable="false">
                        <path d="M9 6.5v11l9-5.5-9-5.5z"></path>
                    </svg>
                </div>
                <h2>All quiet on the server</h2>
                <p>No active playback right now. Streams appear here the moment someone hits play.</p>
                <small><span class="status-dot"></span>listening for sessions...</small>
            </div>
        `;
    }

    function renderStreams(payload) {
        const streams = Array.isArray(payload.streams) ? payload.streams : [];

        if (streams.length === 0) {
            root.innerHTML = emptyState();
            return;
        }

        const cards = streams.map(card).join('');

        root.innerHTML = `<div class="stream-grid">${cards}</div>`;
    }

    function setText(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            element.textContent = String(value);
            if (selector === '[data-nav-count]') {
                element.classList.remove('is-loading');
            }
        }
    }

    function updateStats(payload) {
        const stats = payload.stats || {};
        const activeStreams = Number(stats.active_streams || 0);
        const activeUsers = Number(stats.active_users || 0);

        label.textContent = activeStreams > 0
            ? activeStreams + ' streams - ' + activeUsers + ' users'
            : 'No active sessions';
        dot.classList.toggle('is-live', activeStreams > 0);
        dot.classList.toggle('is-idle', activeStreams === 0);

        setText('[data-nav-count]', activeStreams);
        setText('[data-stat="watch_today"]', stats.watch_today || '0m');

        const activeBlock = document.querySelector('[data-stat-block="active_streams"]');
        const bandwidthBlock = document.querySelector('[data-stat-block="bandwidth"]');
        const transcodeBlock = document.querySelector('[data-stat-block="transcoding"]');

        if (activeStreams > 0) {
            if (activeBlock) {
                activeBlock.innerHTML = `<span>${activeStreams}</span> <small>${activeStreams === 1 ? 'user' : 'users'}</small>`;
            }
            if (bandwidthBlock) {
                bandwidthBlock.innerHTML = `<span>${escapeHtml(stats.bandwidth_mbps || '0.0')}</span> <small>Mbps</small>`;
            }
            if (transcodeBlock) {
                transcodeBlock.innerHTML = `<span>${Number(stats.transcodes || 0)}</span> <small>of ${activeStreams} streams</small>`;
            }
            return;
        }

        if (activeBlock) {
            activeBlock.innerHTML = '<span class="stat-placeholder">Idle</span>';
        }
        if (bandwidthBlock) {
            bandwidthBlock.innerHTML = '<span class="stat-placeholder">Idle</span>';
        }
        if (transcodeBlock) {
            transcodeBlock.innerHTML = '<span class="stat-placeholder">None</span>';
        }
    }

    async function refreshNowPlaying() {
        const response = await fetch('/api/now-playing.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Now Playing request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        updateStats(payload);
        renderStreams(payload);

        window.dispatchEvent(new CustomEvent('jellydash:now-playing', { detail: payload }));
    }

    refreshNowPlaying().catch(() => {});
    window.setInterval(() => {
        refreshNowPlaying().catch(() => {});
    }, 5000);
}());
