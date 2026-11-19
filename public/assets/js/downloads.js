'use strict';

(() => {
    const OVERVIEW_URL = '/api/downloads/overview.php';
    const OVERVIEW_READ_ERROR = 'The latest download update could not be read.';
    const CLIENTS_URL = '/api/downloads/clients.php';
    const PROVIDERS = {
        qbittorrent: { name: 'qBittorrent', mark: 'qB', icon: 'qbittorrent.svg' },
        sabnzbd: { name: 'SABnzbd', mark: 'S', icon: 'sabnzbd.svg' },
        transmission: { name: 'Transmission', mark: 'T', icon: 'transmission.svg' },
        deluge: { name: 'Deluge', mark: 'D', icon: 'deluge.svg' },
        nzbget: { name: 'NZBGet', mark: 'N', icon: 'nzbget.svg' },
    };
    const ACTIVE_STATES = new Set(['downloading', 'processing', 'stalled', 'error']);
    const root = document.querySelector('[data-downloads-root]');
    const announcer = document.querySelector('[data-downloads-announcer]');
    const hooks = window.JellydashDownloadsTestHooks = window.JellydashDownloadsTestHooks || {};

    const isFiniteNumber = (value) => typeof value === 'number' && Number.isFinite(value);
    const monitoringEnabled = (data) => data?.monitoring_enabled === undefined
        ? data?.workers_enabled !== false : data.monitoring_enabled === true;
    const boundedNumber = (value, minimum, maximum) => isFiniteNumber(value)
        ? Math.min(maximum, Math.max(minimum, value))
        : null;

    function node(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = String(text);
        return element;
    }

    function providerInfo(provider) {
        return PROVIDERS[provider] || { name: 'Download client', mark: '?' };
    }

    function categoryLabel(provider, value) {
        if (value === '') return ['transmission', 'deluge'].includes(provider) ? 'Unlabelled' : 'Uncategorized';
        return value;
    }

    function categoryText(item) {
        const labels = item.provider === 'transmission' && Array.isArray(item.tags)
            ? item.tags.filter((value) => typeof value === 'string' && value !== '') : [];
        if (labels.length) return labels.join(', ');
        if (item.category === null || item.category === undefined) return null;
        return categoryLabel(item.provider, item.category);
    }

    function historyStatus(item) {
        const warning = item.status === 'warning';
        const failed = item.status === 'failed';
        return {
            className: `downloads-completed${warning ? ' downloads-warning' : failed ? ' downloads-failed' : ''}`,
            label: warning ? '⚠ Needs attention' : failed ? '× Failed' : '✓ Completed',
            help: warning || failed ? `Check ${providerInfo(item.provider).name} for details.` : null,
        };
    }

    function providerMark(provider) {
        const info = providerInfo(provider);
        const mark = node('span', `downloads-provider-mark downloads-provider-${provider}`, info.mark);
        if (info.icon) {
            const image = node('img');
            image.src = `/assets/img/download-clients/${info.icon}`;
            image.alt = '';
            image.addEventListener('error', () => mark.replaceChildren(document.createTextNode(info.mark)), { once: true });
            mark.replaceChildren(image);
        }
        mark.setAttribute('aria-label', info.name);
        mark.setAttribute('role', 'img');
        return mark;
    }

    function icon(name) {
        const paths = {
            download: 'M12 3v12m-5-5 5 5 5-5M5 16v4h14v-4',
            clock: 'M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0M12 7v5l3 2',
            check: 'm5 12 4 4L19 6M20 12v7H4V5h10',
        };
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.6');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', paths[name] || paths.download);
        svg.append(path);
        return svg;
    }

    function sectionMark(name) {
        const mark = node('span', 'downloads-section-mark');
        mark.append(icon(name));
        return mark;
    }

    function speedReading(value, className) {
        const reading = node('div', className);
        const parts = formatSpeed(value).split(' ');
        reading.append(node('strong', '', parts[0] || '–'));
        if (parts[1]) reading.append(node('small', '', parts[1]));
        return reading;
    }

    function selectedOverview(data, selectedClient) {
        const monitored = monitoringEnabled(data);
        const allConnections = Array.isArray(data?.connections) ? data.connections : [];
        const selected = selectedClient && allConnections.some((client) => client.id === selectedClient) ? selectedClient : null;
        const connections = selected ? allConnections.filter((client) => client.id === selected) : allConnections;
        const items = (Array.isArray(data?.items) ? data.items : [])
            .filter((item) => !selected || item.connection_id === selected)
            .map((item) => !monitored ? { ...item, stale: true, speed: null, eta: null } : item);
        const savedHistory = selected && Array.isArray(data?.history_by_connection?.[selected])
            ? data.history_by_connection[selected]
            : (Array.isArray(data?.history) ? data.history : []).filter((item) => !selected || item.connection_id === selected);
        const enabled = connections.filter((client) => client.enabled !== false);
        const speedKnown = monitored && enabled.length > 0 && enabled.every((client) =>
            client.status === 'connected' && isFiniteNumber(client.speed) && client.speed_complete !== false
            && (client.filter_mode !== 'selected' || client.speed_complete === true));
        const outsideKnown = monitored && enabled.length > 0 && enabled.every((client) =>
            client.status === 'connected' && (client.filter_mode !== 'selected' || isFiniteNumber(client.outside_speed)));
        return {
            ...data, connections, all_connections: allConnections, items, history: savedHistory,
            speed: speedKnown ? enabled.reduce((sum, client) => sum + client.speed, 0) : null,
            speed_complete: speedKnown,
            outside_speed: outsideKnown ? enabled.reduce((sum, client) => sum + (client.outside_speed || 0), 0) : null,
            active_count: items.filter((item) => !item.stale && item.state === 'downloading').length,
        };
    }

    function cardSpeed(item) {
        const known = !item.stale && item.state === 'downloading' && isFiniteNumber(item.speed) && item.speed >= 0;
        return { value: known ? item.speed : null, clientWide: false };
    }

    function formatBytes(value) {
        if (!isFiniteNumber(value) || value < 0) return 'Unknown size';
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        let amount = value;
        let unit = 0;
        while (amount >= 1000 && unit < units.length - 1) {
            amount /= 1000;
            unit += 1;
        }
        const digits = unit === 0 ? 0 : amount >= 100 ? 0 : amount >= 10 ? 1 : 2;
        return `${amount.toFixed(digits)} ${units[unit]}`;
    }

    function formatSpeed(value) {
        if (!isFiniteNumber(value) || value < 0) return '';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        let amount = value;
        let unit = 0;
        while (amount >= 1024 && unit < units.length - 1) {
            amount /= 1024;
            unit += 1;
        }
        const digits = unit === 0 ? 0 : amount >= 100 ? 0 : amount >= 10 ? 1 : 2;
        return `${amount.toFixed(digits)} ${units[unit]}/s`;
    }

    function formatEta(value) {
        if (!isFiniteNumber(value) || value < 0) return '';
        const seconds = Math.round(value);
        if (seconds < 60) return `${seconds}s`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m ${seconds % 60}s`;
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m`;
    }

    function formatRelativeTime(epoch, now, recorded) {
        if (!Number.isInteger(epoch) || !Number.isInteger(now)) return recorded ? 'Recorded time unknown' : 'Time unknown';
        const delta = Math.max(0, now - epoch);
        let value;
        if (delta < 60) value = 'Just now';
        else if (delta < 3600) value = `${Math.floor(delta / 60)}m ago`;
        else if (delta < 86400) value = `${Math.floor(delta / 3600)}h ago`;
        else value = `${Math.floor(delta / 86400)}d ago`;
        return recorded ? `Recorded ${value.toLowerCase()}` : value;
    }

    function stateLabel(state) {
        return ({
            downloading: 'Downloading', processing: 'Processing', queued: 'Queued', paused: 'Paused',
            stalled: 'Stalled', checking: 'Checking', metadata: 'Fetching metadata', error: 'Error', unknown: 'Checking activity',
        })[state] || 'Waiting';
    }

    function filterLabel(connection) {
        return connection.filter_mode === 'selected' ? 'Selected downloads' : 'All downloads';
    }

    function connectionStatusLabel(status) {
        return ({
            connected: 'Connected', stale: 'Update delayed', offline: 'Unavailable', waiting: 'Waiting for first sync',
            disabled: 'Disabled',
        })[status] || 'Status unknown';
    }

    function overviewSignature(data) {
        const connections = Array.isArray(data?.connections) ? data.connections : [];
        return `${data?.active_count || 0}|${!monitoringEnabled(data)}|${connections.map((item) => `${item.id}:${item.status}`).join(',')}`;
    }

    function staleOverview(data) {
        return {
            ...data,
            speed: null,
            client_speed: null,
            outside_speed: null,
            speed_complete: false,
            partial: true,
            active_count: 0,
            connections: (Array.isArray(data?.connections) ? data.connections : []).map((connection) => ({
                ...connection,
                status: connection.enabled === false ? 'disabled' : 'offline',
                speed: null,
                client_speed: null,
                outside_speed: null,
                speed_complete: false,
                partial: connection.enabled !== false,
            })),
            items: (Array.isArray(data?.items) ? data.items : []).map((item) => ({
                ...item,
                stale: true,
                speed: null,
                eta: null,
            })),
        };
    }

    function isRecord(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function validOverview(data) {
        if (!isRecord(data) || typeof data.configured !== 'boolean') return false;

        const hasId = (value) => typeof value === 'string' && value !== '';
        const hasTextFields = (row, fields) => fields.every((field) => row[field] == null || typeof row[field] === 'string');
        const validRows = (rows) => Array.isArray(rows) && rows.every((item) =>
            isRecord(item) && hasId(item.connection_id) && hasId(item.source_id)
            && hasTextFields(item, ['title', 'category', 'provider', 'connection_name', 'state', 'status'])
            && (item.tags == null || (Array.isArray(item.tags) && item.tags.every((tag) => typeof tag === 'string'))));
        const clientHistory = data.history_by_connection;
        return Array.isArray(data.connections) && data.connections.every((client) =>
            isRecord(client) && hasId(client.id) && hasTextFields(client, ['name', 'provider', 'status'])
            && (client.history == null || isRecord(client.history)))
            && [data.items, data.history].every((rows) => (rows === undefined && !data.configured) || validRows(rows))
            // PHP can encode empty maps or sequential numeric client IDs as arrays.
            && (clientHistory === undefined || ((isRecord(clientHistory) || Array.isArray(clientHistory))
                && Object.values(clientHistory).every(validRows)));
    }

    function emptyOverviewMode(connections) {
        if (connections.length > 0 && connections.every((item) => item.status === 'waiting')) return 'first-sync';
        const enabled = connections.filter((item) => item.enabled !== false && item.status !== 'disabled');
        if (enabled.length > 0 && enabled.every((item) => ['offline', 'stale'].includes(item.status))) return 'unavailable';
        return 'idle';
    }

    function rememberFocus() {
        const active = document.activeElement;
        if (!active || !root || !root.contains(active)) return null;
        return active.getAttribute('data-downloads-focus-key');
    }

    function restoreFocus(key) {
        if (!key || !root) return;
        const candidates = root.querySelectorAll('[data-downloads-focus-key]');
        const successors = {
            'queue-more': ['queue-next', 'queue-fewer'],
            'queue-fewer': ['queue-more', 'queue-next'],
            'queue-next': ['queue-previous', 'queue-fewer', 'queue-more'],
            'queue-previous': ['queue-next', 'queue-fewer', 'queue-more'],
        };
        const keys = [key, ...(successors[key] || [])];
        if (key.startsWith('queue-')) keys.push('queue-heading');
        keys.push('filter-all');
        for (const nextKey of keys) {
            for (const candidate of candidates) {
                if (candidate.getAttribute('data-downloads-focus-key') === nextKey) {
                    candidate.focus({ preventScroll: true });
                    return;
                }
            }
        }
        const heading = root.querySelector('h2');
        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
        }
    }

    function renderNotice(title, copy, error = false) {
        const notice = node('div', `downloads-notice${error ? ' downloads-notice-error' : ''}`);
        notice.setAttribute('role', error ? 'alert' : 'status');
        const body = node('div');
        body.append(node('strong', '', title));
        if (copy) body.append(node('p', '', copy));
        notice.append(body);
        return notice;
    }

    function renderEmpty() {
        const panel = node('section', 'downloads-empty');
        panel.append(node('div', 'downloads-empty-mark', '↓'));
        panel.lastChild.setAttribute('aria-hidden', 'true');
        panel.append(node('h2', '', 'Connect your download client'));
        panel.append(node('p', '', 'Follow active downloads, see what is queued, and keep recent completions in one place.'));
        const canManage = root?.dataset.canManage === 'true';
        if (canManage) {
            const button = node('button', 'downloads-button downloads-button-primary', '+ Add client');
            button.type = 'button';
            button.setAttribute('data-downloads-manage', '');
            button.setAttribute('data-downloads-add-on-open', 'true');
            panel.append(button);
        } else {
            const signIn = node('a', 'downloads-button downloads-button-primary', 'Owner or admin sign-in');
            signIn.href = '/login';
            panel.append(signIn);
            panel.append(node('p', 'downloads-queue-note', 'A verified owner or administrator account is required to add download clients.'));
        }
        const supported = node('div', 'downloads-supported');
        ['qbittorrent', 'sabnzbd', 'transmission', 'deluge', 'nzbget'].forEach((provider) => {
            const client = node('span');
            client.append(providerMark(provider), document.createTextNode(` ${providerInfo(provider).name}`));
            supported.append(client);
        });
        panel.append(supported);
        return panel;
    }

    function renderConnections(data) {
        const strip = node('div', 'downloads-client-filters');
        strip.setAttribute('role', 'group');
        strip.setAttribute('aria-label', 'Filter downloads by client');
        function filterButton(id, label) {
            const button = node('button', 'downloads-client-filter');
            button.type = 'button';
            button.setAttribute('data-downloads-filter', id);
            button.setAttribute('data-downloads-focus-key', `filter-${id || 'all'}`);
            button.setAttribute('aria-pressed', String(pageState.selectedClient === id));
            button.setAttribute('aria-label', label);
            return button;
        }
        const all = filterButton('', 'All clients');
        all.classList.add('downloads-all-filter');
        all.append(node('span', '', 'All clients'));
        strip.append(all);
        data.all_connections.forEach((connection) => {
            const name = connection.name || providerInfo(connection.provider).name;
            const chip = filterButton(connection.id, `Show ${name} downloads`);
            chip.append(providerMark(connection.provider), node('span', 'downloads-filter-name', name));
            const status = !monitoringEnabled(data) ? 'Monitoring off' : connection.status === 'connected' && isFiniteNumber(connection.speed)
                ? formatSpeed(connection.speed) : connectionStatusLabel(connection.status);
            chip.append(node('span', 'downloads-filter-speed', status));
            chip.title = `${connectionStatusLabel(connection.status)} · ${filterLabel(connection)}`;
            strip.append(chip);
        });
        return strip;
    }

    function transferSpeedState(data) {
        const enabled = data.connections.filter((client) => client.enabled !== false);
        if (!monitoringEnabled(data) || enabled.length === 0) {
            return { label: 'Monitoring off', caption: 'Saved download activity is still shown.' };
        }
        if (!isFiniteNumber(data.speed)) {
            if (enabled.some((client) => client.status === 'offline')) {
                return { label: 'Speed unavailable', caption: 'Waiting for your clients to reconnect.' };
            }
            if (enabled.some((client) => client.status === 'stale')) {
                return { label: 'Updates delayed', caption: 'Showing the last saved download state.' };
            }
            if (enabled.some((client) => client.status === 'waiting')) {
                return { label: 'Waiting for update', caption: 'Download speed will appear after the first update.' };
            }
            return { label: 'Speed unavailable', caption: 'Waiting for speed data.' };
        }
        const current = data.items.filter((item) => !item.stale);
        const complete = enabled.every((client) => client.status === 'connected' && client.partial !== true)
            && data.partial !== true;
        if (data.speed === 0 && complete && !current.some((item) => item.state === 'downloading')) {
            if (current.some((item) => item.state === 'error')) {
                return { label: 'Needs attention', caption: 'Check the downloads below for details.' };
            }
            return current.some((item) => ['processing', 'checking'].includes(item.state))
                ? { label: 'Processing', caption: 'Your clients are processing downloads.' }
                : { label: 'Idle', caption: 'No downloads are running right now.' };
        }
        return null;
    }

    function renderTransferHeader(data) {
        const monitored = monitoringEnabled(data);
        const fragment = document.createDocumentFragment();
        const heading = node('div', 'downloads-transfer-heading');
        const title = node('div', 'downloads-panel-title');
        const copy = node('div');
        copy.append(node('h2', '', 'Transfers'), node('p', '', pageState.selectedClient
            ? data.connections[0]?.name || 'Download client' : 'Your download clients, together'));
        title.append(sectionMark('download'), copy);
        const enabled = data.connections.filter((client) => client.enabled !== false);
        const fresh = enabled.filter((client) => client.status === 'connected');
        const newest = data.connections.reduce((latest, client) => Math.max(latest, Number.isInteger(client.last_success_at) ? client.last_success_at : 0), 0);
        const freshness = node('span', 'downloads-freshness');
        const interrupted = enabled.some((client) => ['offline', 'stale'].includes(client.status));
        const status = !monitored || !enabled.length ? 'disabled' : interrupted ? 'stale' : fresh.length ? 'connected' : 'waiting';
        const light = node('i', `downloads-status-light is-${status}`);
        light.setAttribute('aria-hidden', 'true');
        const freshnessText = status === 'disabled' ? 'Monitoring disabled' : interrupted ? 'Connection interrupted'
            : newest ? `Updated ${formatRelativeTime(newest, data.now, false).toLowerCase()}` : 'Waiting for update';
        freshness.append(light, document.createTextNode(freshnessText));
        heading.append(title, freshness);
        fragment.append(heading);

        const overview = node('div', 'downloads-transfer-overview');
        const speed = node('div', 'downloads-speed-readout');
        const filtered = data.connections.some((client) => client.enabled !== false && client.filter_mode === 'selected');
        const speedState = transferSpeedState(data);
        const speedLabel = speedState ? (filtered ? 'Selected downloads' : 'Download activity')
            : filtered ? 'Selected download speed' : pageState.selectedClient ? 'Client download speed' : 'Combined download speed';
        speed.append(node('span', 'downloads-speed-label', speedLabel));
        const reading = speedState ? node('div', 'downloads-speed-state') : speedReading(data.speed, 'downloads-speed-value');
        if (speedState) {
            reading.append(node('strong', '', speedState.label));
        } else {
            const arrow = node('span', 'downloads-speed-arrow', '↓');
            arrow.setAttribute('aria-hidden', 'true');
            reading.prepend(arrow);
        }
        speed.append(reading);
        const unknownSplit = enabled.filter((client) => client.status === 'connected' && client.filter_mode === 'selected'
            && ['sabnzbd', 'nzbget'].includes(client.provider) && !isFiniteNumber(client.speed));
        const caption = monitored && unknownSplit.length ? 'Client-wide speed cannot be split by category'
            : speedState ? speedState.caption
                : filtered ? 'Downloads matching your connection filters' : 'Client-wide download speed';
        speed.append(node('span', 'downloads-speed-caption', caption));
        if (monitored && isFiniteNumber(data.outside_speed) && data.outside_speed > 0) {
            speed.append(node('span', 'downloads-speed-secondary', `Outside filters: ${formatSpeed(data.outside_speed)}`));
        }
        if (monitored) unknownSplit.forEach((client) => {
            if (isFiniteNumber(client.client_speed)) speed.append(node('span', 'downloads-speed-secondary', `${client.name || providerInfo(client.provider).name} total: ${formatSpeed(client.client_speed)}`));
        });
        overview.append(speed);
        const counts = node('div', 'downloads-transfer-counts');
        const known = fresh.length > 0 && monitored;
        const current = data.items.filter((item) => !item.stale);
        [
            ['Downloading', current.filter((item) => item.state === 'downloading').length],
            ['Processing', current.filter((item) => item.state === 'processing').length],
            ['Waiting', current.filter((item) => !ACTIVE_STATES.has(item.state)).length],
        ].forEach(([label, value]) => {
            const count = node('div');
            count.append(node('strong', '', known ? value : '–'), node('span', '', label));
            counts.append(count);
        });
        overview.append(counts);
        fragment.append(overview, renderConnections(data));
        return fragment;
    }

    function itemIdentity(item, index) {
        return `${item.connection_id || 'client'}:${item.source_id || index}`;
    }

    function renderCard(item, index, connections) {
        const progress = boundedNumber(item.progress, 0, 100);
        const state = typeof item.state === 'string' ? item.state : 'queued';
        const card = node('article', `downloads-card is-${state}${item.stale ? ' is-stale' : ''}`);
        card.setAttribute('data-download-key', itemIdentity(item, index));
        const top = node('div', 'downloads-card-top');
        top.append(providerMark(item.provider));
        const copy = node('div');
        copy.append(node('h3', '', item.title || 'Untitled download'));
        const meta = node('div', 'downloads-file-meta');
        meta.append(node('span', '', item.connection_name || providerInfo(item.provider).name));
        const category = categoryText(item);
        if (category !== null) {
            meta.append(node('span', '', '·'), node('span', 'downloads-category', category));
        }
        if (item.stale) meta.append(node('span', '', '· Last known update'));
        copy.append(meta);
        top.append(copy);
        card.append(top);

        if (state !== 'downloading') top.append(node('span', `downloads-state-badge is-${state}`, stateLabel(state)));
        const transfer = node('div', 'downloads-transfer');
        if (state === 'processing') {
            transfer.append(node('span', 'downloads-transfer-note', item.stale ? 'Last known processing state' : 'Processing files.'));
        } else {
            const speed = cardSpeed(item);
            if (speed.value === null) {
                transfer.append(node('span', 'downloads-transfer-note', item.stale ? 'Current speed unavailable' : stateLabel(state)));
            } else {
                const reading = node('div');
                reading.append(speedReading(speed.value, 'downloads-item-speed'));
                reading.append(node('span', 'downloads-transfer-speed-label', 'Download speed'));
                transfer.append(reading);
            }
            if (!item.stale && isFiniteNumber(item.eta)) {
                const eta = node('span', 'downloads-eta', formatEta(item.eta));
                eta.append(node('small', '', item.provider === 'sabnzbd' ? 'including queue' : 'remaining'));
                transfer.append(eta);
            }
        }
        card.append(transfer);

        const bar = node('div', 'downloads-progress');
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', `${item.title || 'Download'} progress`);
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', '100');
        if (progress !== null) bar.setAttribute('aria-valuenow', String(progress));
        else bar.setAttribute('aria-valuetext', 'Progress unknown');
        const fill = node('span');
        fill.style.width = `${progress === null ? 0 : progress}%`;
        bar.append(fill);
        card.append(bar);

        const detail = node('div', 'downloads-progress-detail');
        const downloaded = isFiniteNumber(item.downloaded) ? formatBytes(item.downloaded) : null;
        const size = isFiniteNumber(item.size) ? formatBytes(item.size) : null;
        detail.append(node('span', '', downloaded && size ? `${downloaded} / ${size}` : size || downloaded || 'Size unknown'));
        const progressCopy = [];
        if (progress !== null) progressCopy.push(`${Math.round(progress * 10) / 10}%`);
        detail.append(node('strong', '', progressCopy.join(' · ') || 'Progress unknown'));
        card.append(detail);
        return card;
    }

    function renderQueueItem(item, index) {
        const row = node('article', 'downloads-queue-item');
        row.setAttribute('data-download-key', itemIdentity(item, index));
        row.append(providerMark(item.provider));
        const copy = node('div');
        copy.append(node('strong', '', item.title || 'Untitled download'));
        const details = [];
        if (isFiniteNumber(item.size)) details.push(formatBytes(item.size));
        const category = categoryText(item);
        if (category !== null) details.push(category);
        if (details.length) copy.append(node('small', '', details.join(' · ')));
        const state = `${stateLabel(item.state)}${item.stale ? ' · Last known update' : ''}`;
        copy.append(node('div', 'downloads-queue-state', state));
        row.append(copy);
        return row;
    }

    function renderHistory(data) {
        const history = Array.isArray(data.history) ? data.history.slice(0, 20) : [];
        const failedClients = (Array.isArray(data.connections) ? data.connections : [])
            .filter((client) => client.enabled !== false && client.history?.error);
        if (!history.length && !failedClients.length) return null;
        const section = node('section', 'downloads-history-section');
        const heading = node('div', 'downloads-section-heading');
        const title = node('h2', '', 'Recent activity');
        title.append(node('span', '', String(history.length)));
        heading.append(sectionMark('clock'), title, node('p', '', history.length
            ? `Last ${history.length} recorded result${history.length === 1 ? '' : 's'}` : 'No recorded results yet'));
        section.append(heading);
        if (failedClients.length) {
            const names = failedClients.map((client) => client.name || providerInfo(client.provider).name).join(', ');
            section.append(node('p', 'downloads-queue-note downloads-history-update-note',
                `Recent activity couldn't update for ${names}.${history.length ? ' Saved results are still shown.' : ''}`));
        }
        if (!history.length) return section;
        const head = node('div', 'downloads-history-head');
        ['Download', 'Category / label', 'Size', 'Finished'].forEach((label) => head.append(node('span', '', label)));
        section.append(head);
        history.forEach((item) => {
            const row = node('div', 'downloads-history-row');
            const file = node('div', 'downloads-history-file');
            file.append(providerMark(item.provider));
            const copy = node('div');
            copy.append(node('strong', '', item.title || 'Untitled download'));
            const source = node('small', '', item.connection_name || providerInfo(item.provider).name);
            const mobile = node('span', 'downloads-history-mobile-meta');
            const mobileParts = [];
            if (isFiniteNumber(item.size)) mobileParts.push(formatBytes(item.size));
            const label = categoryText(item);
            if (label !== null) mobileParts.push(label);
            if (mobileParts.length) mobile.textContent = ` · ${mobileParts.join(' · ')}`;
            source.append(mobile);
            copy.append(source);
            file.append(copy);
            row.append(file);
            const category = node('span', 'downloads-history-category');
            category.append(node('span', 'downloads-category', categoryText(item) || 'Unknown'));
            row.append(category, node('span', 'downloads-history-size', formatBytes(item.size)));
            const outcome = historyStatus(item);
            const finished = node('span', outcome.className, outcome.label);
            const epoch = Number.isInteger(item.completed_at) ? item.completed_at : item.observed_at;
            finished.append(node('span', '', formatRelativeTime(epoch, data.now, !Number.isInteger(item.completed_at))));
            if (outcome.help) finished.append(node('span', 'downloads-failure-help', outcome.help));
            row.append(finished);
            section.append(row);
        });
        section.append(node('p', 'downloads-queue-note', 'Recent results can remain here after the client removes them. Completed does not mean imported into Jellyfin.'));
        return section;
    }

    const pageState = { overview: null, selectedClient: '', activeLimit: 6, queueLimit: 3, queueOffset: 0, lastAnnouncement: '', warning: null };

    function renderOverview(data, warning) {
        if (!root) return;
        if (warning !== undefined) pageState.warning = warning;
        const focusKey = rememberFocus();
        const fragment = document.createDocumentFragment();
        if (pageState.warning && (data?.feature_enabled === false || data?.configured !== true)) {
            fragment.append(renderNotice('Updates are paused.', pageState.warning, true));
        }
        if (data?.feature_enabled === false) {
            const panel = node('section', 'downloads-empty');
            panel.append(node('h2', '', 'Downloads are turned off'));
            panel.append(node('p', '', 'Your saved clients and recent activity are still here.'));
            if (root.dataset.canManage === 'true') {
                const settings = node('a', 'downloads-button downloads-button-primary', 'Open Settings');
                settings.href = '/settings';
                panel.append(settings);
            }
            fragment.append(panel);
            root.replaceChildren(fragment);
            restoreFocus(focusKey);
            return;
        }
        if (!data || data.configured !== true) {
            fragment.append(renderEmpty());
            root.replaceChildren(fragment);
            bindDynamicActions();
            restoreFocus(focusKey);
            return;
        }

        if (pageState.selectedClient && !data.connections?.some((client) => client.id === pageState.selectedClient)) pageState.selectedClient = '';
        data = selectedOverview(data, pageState.selectedClient);
        const connections = data.connections;
        const panel = node('section', 'downloads-transfer-panel');
        panel.append(renderTransferHeader(data));
        fragment.append(panel);
        if (pageState.warning) panel.append(renderNotice('Updates are paused.', pageState.warning, true));
        if (!monitoringEnabled(data)) {
            panel.append(renderNotice('Download monitoring is disabled.', 'Enable the download collector to refresh speed, queue and completion data. Saved information is still shown.'));
        }
        const problemConnections = connections.filter((item) => ['offline', 'stale'].includes(item.status));
        if (problemConnections.length) {
            const allUnavailable = problemConnections.length === connections.length;
            const names = problemConnections.map((item) => {
                const name = item.name || providerInfo(item.provider).name;
                return typeof item.error === 'string' && item.error ? `${name}: ${item.error}` : name;
            }).join(' ');
            panel.append(renderNotice(
                allUnavailable ? 'Download clients are unavailable.' : 'Some download clients need attention.',
                `${names}. Live speed may be incomplete. Recorded completions remain available.`,
            ));
        }
        if (data.partial === true && connections.some((item) => item.status === 'connected' && item.partial === true)) {
            panel.append(renderNotice('Some current downloads may be missing.', 'Open the download client to see its full list.'));
        }

        const items = Array.isArray(data.items) ? data.items : [];
        const activeRank = { downloading: 0, stalled: 1, error: 1, processing: 2 };
        const active = items.filter((item) => ACTIVE_STATES.has(item.state))
            .sort((left, right) => (activeRank[left.state] ?? 3) - (activeRank[right.state] ?? 3));
        const waiting = items.filter((item) => !ACTIVE_STATES.has(item.state));
        if (active.length || waiting.length) {
            const work = node('div', 'downloads-work-grid');
            const activeSection = node('section');
            const activeHeading = node('div', 'downloads-section-heading visually-hidden');
            const activeTitle = node('h2', '', 'In progress');
            activeTitle.append(node('span', '', String(active.length)));
            activeHeading.append(activeTitle);
            activeSection.append(activeHeading);
            const activeList = node('div', 'downloads-active-list');
            active.slice(0, pageState.activeLimit).forEach((item, index) => activeList.append(renderCard(item, index, connections)));
            if (!active.length) {
                const checkingActivity = monitoringEnabled(data) && (items.some((item) => !item.stale && item.state === 'unknown')
                    || (isFiniteNumber(data.speed) && data.speed > 0));
                activeList.append(node('p', 'downloads-idle-note', checkingActivity ? 'Checking download activity.' : 'Nothing downloading right now.'));
            }
            activeSection.append(activeList);
            if (active.length > 6) {
                const more = node('button', 'downloads-text-button', pageState.activeLimit >= Math.min(active.length, 30) ? 'Show fewer' : `Show ${Math.min(6, active.length - pageState.activeLimit)} more`);
                more.type = 'button';
                more.setAttribute('data-downloads-active-more', '');
                more.setAttribute('data-downloads-focus-key', 'active-more');
                activeSection.append(more);
                if (active.length > 30) activeSection.append(node('p', 'downloads-queue-note', `Showing up to 30 of ${active.length} active items.`));
            }
            work.append(activeSection);

            const queue = node('section', 'downloads-queue-panel');
            const queueHeading = node('div', 'downloads-section-heading');
            const queueTitle = node('h2', '', 'Waiting');
            queueTitle.setAttribute('data-downloads-focus-key', 'queue-heading');
            queueTitle.setAttribute('tabindex', '-1');
            queueTitle.append(node('span', '', String(waiting.length)));
            const queueTitleGroup = node('div', 'downloads-waiting-title');
            queueTitleGroup.append(icon('clock'), queueTitle);
            queueHeading.append(queueTitleGroup);
            queue.append(queueHeading);
            const queueList = node('div', 'downloads-queue-list');
            if (pageState.queueOffset >= waiting.length) pageState.queueOffset = 0;
            waiting.slice(pageState.queueOffset, pageState.queueOffset + pageState.queueLimit)
                .forEach((item, index) => queueList.append(renderQueueItem(item, pageState.queueOffset + index)));
            if (!waiting.length) queueList.append(node('p', 'downloads-queue-note', 'Nothing is waiting.'));
            queue.append(queueList);
            if (waiting.length > 3) {
                const pager = node('div', 'downloads-list-pager');
                if (pageState.queueOffset > 0) {
                    const previous = node('button', 'downloads-text-button', 'Previous 30');
                    previous.type = 'button';
                    previous.setAttribute('data-downloads-queue-previous', '');
                    previous.setAttribute('data-downloads-focus-key', 'queue-previous');
                    pager.append(previous);
                }
                if (pageState.queueOffset + pageState.queueLimit < waiting.length) {
                    const moreCount = pageState.queueOffset === 0 && pageState.queueLimit < 30
                        ? Math.min(10, Math.min(30, waiting.length) - pageState.queueLimit)
                        : Math.min(30, waiting.length - pageState.queueOffset - pageState.queueLimit);
                    const more = node('button', 'downloads-text-button', pageState.queueOffset === 0 && pageState.queueLimit < 30 ? `Show ${moreCount} more` : `Next ${moreCount}`);
                    more.type = 'button';
                    more.setAttribute(pageState.queueOffset === 0 && pageState.queueLimit < 30 ? 'data-downloads-queue-more' : 'data-downloads-queue-next', '');
                    more.setAttribute('data-downloads-focus-key', pageState.queueOffset === 0 && pageState.queueLimit < 30 ? 'queue-more' : 'queue-next');
                    pager.append(more);
                }
                if (pageState.queueOffset === 0 && pageState.queueLimit > 3) {
                    const fewer = node('button', 'downloads-text-button', 'Show fewer');
                    fewer.type = 'button';
                    fewer.setAttribute('data-downloads-queue-fewer', '');
                    fewer.setAttribute('data-downloads-focus-key', 'queue-fewer');
                    pager.append(fewer);
                }
                queueHeading.append(pager);
                if (waiting.length > 30) queue.append(node('p', 'downloads-queue-note', `Showing ${pageState.queueOffset + 1}-${Math.min(waiting.length, pageState.queueOffset + pageState.queueLimit)} of ${waiting.length}.`));
            }
            if (waiting.length) work.append(queue);
            panel.append(work);
        } else if (emptyOverviewMode(connections) === 'first-sync') {
            const loading = node('div', 'downloads-loading');
            loading.append(node('span', 'downloads-loading-mark'));
            loading.lastChild.setAttribute('aria-hidden', 'true');
            const copy = node('div');
            copy.append(node('strong', '', 'Waiting for the first update'));
            copy.append(node('p', '', 'Your clients are saved. Checking for downloads.'));
            loading.append(copy);
            panel.append(loading);
        } else if (emptyOverviewMode(connections) === 'unavailable') {
            const unavailable = node('div', 'downloads-idle');
            unavailable.append(node('span', '', '!'));
            unavailable.lastChild.setAttribute('aria-hidden', 'true');
            const copy = node('div');
            copy.append(node('h2', '', 'No current download status'));
            copy.append(node('p', '', 'Jellydash will check again automatically. Recorded completions remain available below.'));
            unavailable.append(copy);
            panel.append(unavailable);
        } else {
            const idle = node('div', 'downloads-idle');
            idle.append(node('span', '', '✓'));
            idle.lastChild.setAttribute('aria-hidden', 'true');
            const copy = node('div');
            const disabled = connections.length && connections.every((item) => item.status === 'disabled');
            const filtered = connections.some((item) => item.filter_mode === 'selected');
            copy.append(node('h2', '', disabled ? 'Monitoring is disabled' : 'No downloads to show'));
            copy.append(node('p', '', disabled
                ? 'Enable a client in Manage clients to resume monitoring.'
                : filtered ? 'No current downloads are available to display with the selected filters.' : 'No current downloads are available to display.'));
            idle.append(copy);
            panel.append(idle);
        }
        const history = renderHistory(data);
        if (history) fragment.append(history);
        root.replaceChildren(fragment);
        bindDynamicActions();
        restoreFocus(focusKey);

        const signature = overviewSignature(data);
        if (announcer && signature !== pageState.lastAnnouncement) {
            const offline = connections.filter((item) => item.status === 'offline').length;
            announcer.textContent = `${data.active_count || 0} downloading${offline ? `, ${offline} client${offline === 1 ? '' : 's'} offline` : ''}.`;
            pageState.lastAnnouncement = signature;
        }
    }

    function bindDynamicActions() {
        document.querySelectorAll('[data-downloads-manage]').forEach((button) => {
            if (button.dataset.downloadsBound) return;
            button.dataset.downloadsBound = 'true';
            button.addEventListener('click', () => openManager(button, button.dataset.downloadsAddOnOpen === 'true'));
        });
        root?.querySelector('[data-downloads-active-more]')?.addEventListener('click', () => {
            const count = selectedOverview(pageState.overview, pageState.selectedClient).items.filter((item) => ACTIVE_STATES.has(item.state)).length;
            pageState.activeLimit = pageState.activeLimit >= Math.min(count, 30) ? 6 : Math.min(30, pageState.activeLimit + 6);
            renderOverview(pageState.overview);
        });
        root?.querySelector('[data-downloads-queue-more]')?.addEventListener('click', () => {
            pageState.queueLimit = Math.min(30, pageState.queueLimit + 10);
            renderOverview(pageState.overview);
        });
        root?.querySelector('[data-downloads-queue-fewer]')?.addEventListener('click', () => {
            pageState.queueOffset = 0;
            pageState.queueLimit = 3;
            renderOverview(pageState.overview);
        });
        root?.querySelector('[data-downloads-queue-next]')?.addEventListener('click', () => {
            pageState.queueOffset += pageState.queueLimit;
            pageState.queueLimit = 30;
            renderOverview(pageState.overview);
        });
        root?.querySelector('[data-downloads-queue-previous]')?.addEventListener('click', () => {
            pageState.queueOffset = Math.max(0, pageState.queueOffset - 30);
            pageState.queueLimit = 30;
            renderOverview(pageState.overview);
        });
        root?.querySelectorAll('[data-downloads-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                pageState.selectedClient = button.dataset.downloadsFilter || '';
                pageState.activeLimit = 6;
                pageState.queueLimit = 3;
                pageState.queueOffset = 0;
                renderOverview(pageState.overview);
            });
        });
    }

    const polling = { timer: null, controller: null, failures: 0, sequence: 0, stopped: false, authExpired: false, refreshPending: false };

    function scheduleOverview(delay) {
        if (!root || polling.authExpired || polling.stopped || document.hidden) return;
        window.clearTimeout(polling.timer);
        polling.timer = window.setTimeout(refreshOverview, delay);
    }

    async function refreshOverview() {
        if (!root || polling.authExpired || polling.stopped || document.hidden || polling.controller) return;
        polling.refreshPending = false;
        const sequence = ++polling.sequence;
        const controller = new AbortController();
        polling.controller = controller;
        let timedOut = false;
        const timeout = window.setTimeout(() => { timedOut = true; controller.abort(); }, 8000);
        try {
            const response = await fetch(OVERVIEW_URL, { cache: 'no-store', credentials: 'same-origin', signal: controller.signal });
            if (!response.ok) {
                const error = new Error('The latest download update could not be loaded.');
                error.status = response.status;
                throw error;
            }
            const data = await response.json().catch((error) => {
                if (error?.name === 'AbortError') throw error;
                throw new Error(OVERVIEW_READ_ERROR);
            });
            if (sequence !== polling.sequence) return;
            if (!validOverview(data)) throw new Error(OVERVIEW_READ_ERROR);
            try {
                renderOverview(data, null);
            } catch (error) {
                throw new Error(OVERVIEW_READ_ERROR, { cause: error });
            }
            pageState.overview = data;
            polling.failures = 0;
            scheduleOverview(5000);
        } catch (error) {
            if (sequence !== polling.sequence || (error?.name === 'AbortError' && !timedOut)) return;
            if (error.status === 401 || error.status === 403) {
                polling.authExpired = true;
                window.clearTimeout(polling.timer);
                polling.timer = null;
                const notice = renderNotice('Session expired.', null, true);
                const link = node('a', '', 'Sign in again');
                link.href = '/login';
                notice.append(link);
                root.replaceChildren(notice);
                return;
            }
            polling.failures += 1;
            const message = timedOut ? 'The latest download update timed out.' : error instanceof Error ? error.message : 'The latest download update could not be loaded.';
            scheduleOverview(Math.min(60000, 5000 * (2 ** Math.min(polling.failures, 4))));
            if (pageState.overview) {
                const stale = staleOverview(pageState.overview);
                renderOverview(stale, message);
                pageState.overview = stale;
            } else {
                root.replaceChildren(renderNotice('Downloads could not be loaded.', message, true));
            }
        } finally {
            window.clearTimeout(timeout);
            if (polling.controller === controller) polling.controller = null;
            if (polling.refreshPending) refreshOverview();
        }
    }

    function requestFreshOverview() {
        if (polling.authExpired) return;
        window.clearTimeout(polling.timer);
        polling.timer = null;
        polling.refreshPending = true;
        ++polling.sequence;
        if (polling.controller) polling.controller.abort();
        else refreshOverview();
    }

    function stopOverview() {
        window.clearTimeout(polling.timer);
        polling.timer = null;
        ++polling.sequence;
        if (polling.controller) polling.controller.abort();
    }

    if (root) {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopOverview();
            else { polling.refreshPending = true; refreshOverview(); }
        });
        window.addEventListener('pagehide', () => { polling.stopped = true; stopOverview(); });
        window.addEventListener('pageshow', () => { polling.stopped = false; polling.refreshPending = true; refreshOverview(); });
        refreshOverview();
    }

    const manager = document.querySelector('[data-downloads-manager]');
    const editor = document.querySelector('[data-downloads-editor]');
    const removeDialog = document.querySelector('[data-downloads-remove-dialog]');
    const management = { clients: [], editing: null, removing: null, provider: null, receipt: null, testGeneration: 0, uncategorized: false, lastTrigger: null, returnToManager: false, toastTimer: null };

    function setMessage(element, message, isError = false) {
        if (!element) return;
        element.textContent = message || '';
        element.hidden = !message;
        element.classList.toggle('is-error', Boolean(isError));
    }

    async function requestJson(url, options = {}) {
        const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin', ...options });
        let payload = null;
        try { payload = await response.json(); } catch (_) { /* A safe generic error is shown below. */ }
        if (!response.ok) {
            const error = new Error(typeof payload?.error === 'string' ? payload.error : `Request failed (HTTP ${response.status}).`);
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function managementPost(payload) {
        return requestJson(CLIENTS_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(payload),
        });
    }

    function showToast(message) {
        const toast = document.querySelector('[data-downloads-toast]');
        if (!toast) return;
        window.clearTimeout(management.toastTimer);
        toast.textContent = message;
        toast.hidden = false;
        management.toastTimer = window.setTimeout(() => { toast.hidden = true; }, 4000);
    }

    function managerError() {
        return manager?.querySelector('[data-downloads-manager-error]');
    }

    function editorError() {
        return editor?.querySelector('[data-downloads-editor-error]');
    }

    async function loadClients() {
        const payload = await requestJson(CLIENTS_URL);
        management.clients = Array.isArray(payload?.clients) ? payload.clients : [];
        return management.clients;
    }

    function renderManager() {
        if (!manager) return;
        const list = manager.querySelector('[data-downloads-client-list]');
        list.replaceChildren();
        if (!management.clients.length) {
            list.append(node('p', 'downloads-queue-note', 'No download clients have been added.'));
            return;
        }
        management.clients.forEach((client) => {
            const card = node('article', 'downloads-managed-client');
            const head = node('div', 'downloads-managed-client-head');
            head.append(providerMark(client.provider));
            const copy = node('div');
            const name = node('strong', '', client.name || providerInfo(client.provider).name);
            if (client.source === 'environment') name.append(node('span', 'downloads-client-source', 'From configuration'));
            copy.append(name);
            const values = client.filter_mode === 'selected'
                ? [...(Array.isArray(client.categories) ? client.categories : []), ...(Array.isArray(client.tags) ? client.tags : [])]
                : [];
            copy.append(node('small', '', `${client.enabled ? 'Enabled' : 'Disabled'} · ${client.filter_mode === 'selected' ? values.map((value) => categoryLabel(client.provider, value)).join(', ') || 'Selected downloads' : 'All downloads'}`));
            head.append(copy);
            card.append(head);
            const actions = node('div', 'downloads-managed-client-actions');
            const edit = node('button', 'downloads-text-button', 'Edit');
            edit.type = 'button';
            edit.addEventListener('click', () => openEditor(client, edit));
            actions.append(edit);
            const toggle = node('button', 'downloads-text-button', client.enabled ? 'Disable' : 'Enable');
            toggle.type = 'button';
            toggle.addEventListener('click', () => toggleClient(client, toggle));
            actions.append(toggle);
            if (client.source !== 'environment') {
                const fallback = client.id === 'legacy-sabnzbd-env';
                const remove = node('button', 'downloads-text-button is-remove', fallback ? 'Use environment settings' : 'Remove');
                remove.type = 'button';
                remove.addEventListener('click', () => confirmRemove(client, remove));
                actions.append(remove);
            }
            card.append(actions);
            list.append(card);
        });
    }

    async function openManager(trigger, addAfterOpen = false) {
        if (!manager) return;
        management.lastTrigger = trigger || document.activeElement;
        setMessage(managerError(), '');
        const list = manager.querySelector('[data-downloads-client-list]');
        list.replaceChildren(node('div', 'downloads-loading', 'Loading clients...'));
        if (!manager.open) manager.showModal();
        try {
            await loadClients();
            renderManager();
            if (addAfterOpen) openEditor(null, trigger);
        } catch (error) {
            setMessage(managerError(), error.message, true);
            list.replaceChildren();
            if (error.status === 401 || error.status === 403) {
                const link = node('a', 'downloads-button', 'Sign in');
                link.href = '/login';
                list.append(link);
            }
        }
    }

    function parseValues(value) {
        const values = [];
        String(value || '').split(',').forEach((part) => {
            const trimmed = part.trim();
            if (trimmed !== '' && !values.includes(trimmed)) values.push(trimmed);
        });
        return values;
    }

    function normalizedClientUrl(provider, value) {
        const url = String(value || '').trim().replace(/\/+$/, '');
        const suffix = ({ sabnzbd: '/api', qbittorrent: '/api/v2', transmission: '/transmission/rpc',
            deluge: '/json', nzbget: '/jsonrpc' })[provider];
        return suffix && url.endsWith(suffix) ? url.slice(0, -suffix.length) : url;
    }

    function formPayload(action) {
        const form = editor.querySelector('[data-downloads-client-form]');
        const data = new FormData(form);
        const editing = management.editing;
        const payload = {
            action,
            provider: management.provider,
            name: String(data.get('name') || '').trim(),
            url: normalizedClientUrl(management.provider, data.get('url')),
            username: ['qbittorrent', 'transmission', 'nzbget'].includes(management.provider)
                ? String(data.get('username') || '').trim() : '',
            verify_tls: data.get('verify_tls') === 'on',
            enabled: editing ? editing.enabled === true : true,
            filter_mode: data.get('filter_mode') === 'selected' ? 'selected' : 'all',
            categories: [...(management.uncategorized ? [''] : []), ...parseValues(data.get('categories'))],
            tags: management.provider === 'qbittorrent' ? parseValues(data.get('tags')).filter(Boolean) : [],
            secret: String(data.get('secret') || ''),
        };
        if (editing) {
            payload.id = editing.id;
            payload.revision = editing.revision;
        }
        if (management.receipt) payload.receipt = management.receipt;
        return payload;
    }

    function updateFilterVisibility() {
        const form = editor?.querySelector('[data-downloads-client-form]');
        if (!form) return;
        const selected = form.querySelector('input[name="filter_mode"]:checked')?.value === 'selected';
        form.querySelector('[data-downloads-filter-fields]').hidden = !selected;
    }

    function updateRelocation() {
        const form = editor?.querySelector('[data-downloads-client-form]');
        const row = form?.querySelector('[data-downloads-relocation]');
        if (!row) return;
        const changed = Boolean(management.editing
            && normalizedClientUrl(management.provider, form.elements.url.value) !== management.editing.url);
        row.hidden = !changed;
        if (!changed) form.elements.confirm_relocation.checked = false;
    }

    function invalidateReceipt() {
        management.testGeneration += 1;
        management.receipt = null;
        setMessage(editor?.querySelector('[data-downloads-test-result]'), '');
        const button = editor?.querySelector('[data-downloads-test]');
        if (button) {
            button.disabled = false;
            button.textContent = 'Test connection';
        }
    }

    function chooseProvider(provider) {
        if (!PROVIDERS[provider] || !editor) return;
        if (management.provider !== provider) invalidateReceipt();
        management.provider = provider;
        const form = editor.querySelector('[data-downloads-client-form]');
        const editing = management.editing;
        editor.querySelector('[data-downloads-provider-choice]').hidden = true;
        editor.querySelector('[data-downloads-editor-fields]').hidden = false;
        editor.querySelector('[data-downloads-editor-title]').textContent = `${editing ? 'Edit' : 'Add'} ${providerInfo(provider).name}`;
        editor.querySelector('[data-downloads-editor-description]').textContent = editing
            ? 'Update this connection without changing anything in the download client.'
            : 'Connect a client to start watching its downloads.';
        const usernameField = editor.querySelector('[data-downloads-username-field]');
        usernameField.hidden = !['qbittorrent', 'transmission', 'nzbget'].includes(provider);
        form.elements.username.required = ['qbittorrent', 'transmission'].includes(provider);
        editor.querySelector('[data-downloads-username-label]').textContent = provider === 'nzbget'
            ? 'Control username (optional)' : 'Username';
        editor.querySelector('[data-downloads-secret-label]').textContent = provider === 'sabnzbd' ? 'API key' : 'Password';
        editor.querySelector('[data-downloads-tags-field]').hidden = provider !== 'qbittorrent';
        editor.querySelector('[data-downloads-selected-label]').textContent = provider === 'qbittorrent'
            ? 'Only selected categories or tags' : provider === 'transmission' || provider === 'deluge'
                ? 'Only selected labels' : 'Only selected categories';
        editor.querySelector('[data-downloads-category-label]').textContent = provider === 'transmission' || provider === 'deluge'
            ? 'Labels' : 'Categories';
        editor.querySelector('[data-downloads-category-help]').textContent = provider === 'deluge'
            ? 'Enter one label exactly as it appears in Deluge.'
            : 'Separate names with commas. Names must match the client exactly.';
        editor.querySelector('[data-downloads-deluge-label-note]').hidden = provider !== 'deluge';
        form.elements.categories.placeholder = provider === 'deluge' ? 'sonarr' : 'sonarr, radarr';
        editor.querySelector('[data-downloads-save]').textContent = editing ? 'Save changes' : 'Add client';
        form.elements.secret.required = !editing;
        editor.querySelector('[data-downloads-secret-help]').textContent = editing
            ? editing.credential_source === 'environment'
                ? 'Leave blank to keep using the environment credential.'
                : 'Leave blank to keep the saved credential.'
            : 'Saved credentials stay on your Jellydash server.';
        if (!editing && !form.elements.name.value) form.elements.name.value = providerInfo(provider).name;
        form.elements.name.focus();
    }

    function clearSuggestions() {
        ['category', 'tag'].forEach((type) => {
            const box = editor?.querySelector(`[data-downloads-${type}-suggestions]`);
            if (box) { box.replaceChildren(); box.hidden = true; }
        });
    }

    function renderSuggestions(type, values) {
        const box = editor?.querySelector(`[data-downloads-${type}-suggestions]`);
        if (!box) return;
        box.replaceChildren();
        const available = Array.isArray(values) ? values.filter((value) => typeof value === 'string').slice(0, 50) : [];
        available.forEach((value) => {
            const button = node('button', 'downloads-suggestion', type === 'category' ? categoryLabel(management.provider, value) : value);
            button.type = 'button';
            if (type === 'category' && value === '') button.setAttribute('aria-pressed', String(management.uncategorized));
            button.addEventListener('click', () => {
                const fieldName = type === 'category' ? 'categories' : 'tags';
                const field = editor.querySelector('[data-downloads-client-form]').elements[fieldName];
                if (type === 'category' && value === '') {
                    management.uncategorized = !management.uncategorized;
                    if (management.uncategorized && management.provider === 'deluge') {
                        field.value = '';
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    button.setAttribute('aria-pressed', String(management.uncategorized));
                    return;
                }
                const current = management.provider === 'deluge' && type === 'category' ? [] : parseValues(field.value);
                if (management.provider === 'deluge' && type === 'category') {
                    management.uncategorized = false;
                    box.querySelector('[aria-pressed]')?.setAttribute('aria-pressed', 'false');
                }
                if (!current.includes(value)) current.push(value);
                field.value = current.join(', ');
                field.dispatchEvent(new Event('input', { bubbles: true }));
            });
            box.append(button);
        });
        box.hidden = available.length === 0;
    }

    function openEditor(client, trigger) {
        if (!editor) return;
        management.editing = client || null;
        management.provider = client?.provider || null;
        invalidateReceipt();
        management.uncategorized = Array.isArray(client?.categories) && client.categories.includes('');
        management.returnToManager = Boolean(manager?.open);
        management.lastTrigger = trigger || document.activeElement;
        if (manager?.open) manager.close();
        const form = editor.querySelector('[data-downloads-client-form]');
        form.reset();
        setMessage(editorError(), '');
        setMessage(editor.querySelector('[data-downloads-test-result]'), '');
        clearSuggestions();
        editor.querySelector('[data-downloads-provider-choice]').hidden = Boolean(client);
        editor.querySelector('[data-downloads-editor-fields]').hidden = !client;
        editor.querySelector('[data-downloads-editor-title]').textContent = 'Add a download client';
        editor.querySelector('[data-downloads-editor-description]').textContent = 'Connect a client to start watching its downloads.';
        if (!editor.open) editor.showModal();
        if (client) {
            chooseProvider(client.provider);
            form.elements.name.value = client.name || '';
            form.elements.url.value = client.url || '';
            form.elements.username.value = client.username || '';
            form.elements.secret.value = '';
            form.elements.verify_tls.checked = client.verify_tls !== false;
            const mode = form.querySelector(`input[name="filter_mode"][value="${client.filter_mode === 'selected' ? 'selected' : 'all'}"]`);
            if (mode) mode.checked = true;
            form.elements.categories.value = Array.isArray(client.categories) ? client.categories.filter((value) => value !== '').join(', ') : '';
            form.elements.tags.value = Array.isArray(client.tags) ? client.tags.join(', ') : '';
            if (management.uncategorized) renderSuggestions('category', ['']);
            updateFilterVisibility();
            updateRelocation();
        } else {
            editor.querySelector('[data-downloads-provider-choice] button')?.focus();
        }
    }

    function closeEditor() {
        if (!editor?.open) return;
        invalidateReceipt();
        editor.close();
    }

    async function testConnection(button) {
        const form = editor.querySelector('[data-downloads-client-form]');
        setMessage(editorError(), '');
        if (!form.reportValidity()) return;
        const payload = formPayload('test');
        if (management.editing && payload.url !== management.editing.url && !payload.secret) {
            setMessage(editorError(), 'Enter the credential again when changing the client URL.', true);
            return;
        }
        if (management.editing && payload.username !== management.editing.username && !payload.secret) {
            setMessage(editorError(), 'Enter the credential again when changing the username.', true);
            return;
        }
        const generation = ++management.testGeneration;
        const stillTestingSameConnection = () => {
            if (generation !== management.testGeneration || !editor.open) return false;
            const current = formPayload('test');
            return ['id', 'revision', 'provider', 'url', 'username', 'secret', 'verify_tls']
                .every((field) => current[field] === payload[field]);
        };
        button.disabled = true;
        button.textContent = 'Testing...';
        setMessage(editor.querySelector('[data-downloads-test-result]'), 'Testing the connection...');
        try {
            const result = await managementPost(payload);
            if (!stillTestingSameConnection()) return;
            management.receipt = result.receipt;
            const version = typeof result.version === 'string' && result.version ? ` ${result.version}` : '';
            setMessage(editor.querySelector('[data-downloads-test-result]'), `Connected to ${providerInfo(management.provider).name}${version}.`);
            renderSuggestions('category', result.categories);
            renderSuggestions('tag', management.provider === 'qbittorrent' ? result.tags : []);
        } catch (error) {
            if (!stillTestingSameConnection()) return;
            management.receipt = null;
            setMessage(editor.querySelector('[data-downloads-test-result]'), error.message, true);
        } finally {
            if (generation === management.testGeneration) {
                button.disabled = false;
                button.textContent = 'Test connection';
            }
        }
    }

    async function saveClient(event) {
        event.preventDefault();
        const form = event.currentTarget;
        setMessage(editorError(), '');
        if (!form.reportValidity()) return;
        const payload = formPayload('save');
        if (payload.filter_mode === 'selected' && payload.categories.length === 0 && payload.tags.length === 0) {
            setMessage(editorError(), management.provider === 'qbittorrent'
                ? 'Add a category or tag, or choose All downloads.'
                : `Add a ${['transmission', 'deluge'].includes(management.provider) ? 'label' : 'category'}, or choose All downloads.`, true);
            return;
        }
        if (management.provider === 'deluge' && payload.categories.length > 1) {
            setMessage(editorError(), 'Choose one Deluge label.', true);
            return;
        }
        if (management.editing && payload.url !== management.editing.url && !form.elements.confirm_relocation.checked) {
            setMessage(editorError(), 'Confirm that this is the same client at its new URL, or add it as a separate client.', true);
            form.elements.confirm_relocation.focus();
            return;
        }
        const save = editor.querySelector('[data-downloads-save]');
        save.disabled = true;
        try {
            const result = await managementPost(payload);
            const existingIndex = management.clients.findIndex((item) => item.id === result.client.id);
            if (existingIndex >= 0) management.clients[existingIndex] = result.client;
            else management.clients.push(result.client);
            form.elements.secret.value = '';
            showToast(management.editing ? 'Client changes saved.' : 'Download client added.');
            pageState.activeLimit = 6;
            pageState.queueLimit = 3;
            pageState.queueOffset = 0;
            if (root) requestFreshOverview();
            closeEditor();
        } catch (error) {
            setMessage(editorError(), error.message, true);
            if (error.status === 419) setMessage(editorError(), `${error.message} Your form values are still here.`, true);
        } finally {
            save.disabled = false;
        }
    }

    async function toggleClient(client, button) {
        setMessage(managerError(), '');
        button.disabled = true;
        try {
            const result = await managementPost({ action: 'toggle', id: client.id, revision: client.revision, enabled: !client.enabled });
            const index = management.clients.findIndex((item) => item.id === client.id);
            if (index >= 0) management.clients[index] = result.client;
            renderManager();
            showToast(result.client.enabled ? 'Client monitoring enabled.' : 'Client monitoring disabled.');
            if (root) requestFreshOverview();
        } catch (error) {
            setMessage(managerError(), error.message, true);
            button.disabled = false;
        }
    }

    function confirmRemove(client, trigger) {
        if (!removeDialog) return;
        management.removing = client;
        management.lastTrigger = trigger;
        const fallback = client.id === 'legacy-sabnzbd-env';
        removeDialog.querySelector('[data-downloads-remove-title]').textContent = fallback ? 'Use environment settings?' : 'Remove this client?';
        removeDialog.querySelector('[data-downloads-remove-copy]').textContent = fallback
            ? 'The saved override and its recorded history will be removed. Jellydash will return to the SABnzbd connection from the environment. Remote downloads are untouched.'
            : 'Its saved connection and recorded download history will be removed from Jellydash. Downloads in the client are untouched.';
        removeDialog.querySelector('[data-downloads-confirm-remove]').textContent = fallback ? 'Use environment settings' : 'Remove client';
        setMessage(removeDialog.querySelector('[data-downloads-remove-error]'), '');
        if (manager?.open) manager.close();
        removeDialog.showModal();
    }

    async function removeClient(button) {
        const client = management.removing;
        if (!client) return;
        button.disabled = true;
        try {
            await managementPost({ action: 'remove', id: client.id, revision: client.revision });
            management.clients = management.clients.filter((item) => item.id !== client.id);
            removeDialog.close();
            showToast(client.id === 'legacy-sabnzbd-env' ? 'Environment settings restored.' : 'Download client removed.');
            if (root) requestFreshOverview();
        } catch (error) {
            setMessage(removeDialog.querySelector('[data-downloads-remove-error]'), error.message, true);
        } finally {
            button.disabled = false;
        }
    }

    if (manager && editor) {
        bindDynamicActions();
        manager.querySelector('[data-downloads-add-client]')?.addEventListener('click', (event) => openEditor(null, event.currentTarget));
        document.querySelectorAll('[data-downloads-provider]').forEach((button) => button.addEventListener('click', () => chooseProvider(button.dataset.downloadsProvider)));
        const form = editor.querySelector('[data-downloads-client-form]');
        form.addEventListener('submit', saveClient);
        form.querySelectorAll('input[name="filter_mode"]').forEach((radio) => radio.addEventListener('change', updateFilterVisibility));
        ['url', 'username', 'secret'].forEach((name) => form.elements[name]?.addEventListener('input', () => {
            invalidateReceipt();
            if (name === 'url') updateRelocation();
        }));
        form.elements.verify_tls.addEventListener('change', invalidateReceipt);
        editor.querySelector('[data-downloads-test]')?.addEventListener('click', (event) => testConnection(event.currentTarget));
        removeDialog?.querySelector('[data-downloads-confirm-remove]')?.addEventListener('click', (event) => removeClient(event.currentTarget));
        document.querySelectorAll('[data-downloads-close]').forEach((button) => button.addEventListener('click', () => {
            const target = button.dataset.downloadsClose;
            if (target === 'manager') {
                manager.close();
                management.lastTrigger?.focus?.();
            } else if (target === 'editor') closeEditor();
            else if (target === 'remove') {
                removeDialog.close();
            }
        }));
        editor.addEventListener('close', () => {
            invalidateReceipt();
            form.elements.secret.value = '';
            if (management.returnToManager && manager && !manager.open) {
                management.returnToManager = false;
                renderManager();
                manager.showModal();
            } else {
                management.lastTrigger?.focus?.();
            }
        });
        removeDialog?.addEventListener('close', () => {
            management.removing = null;
            if (manager && !manager.open) {
                renderManager();
                manager.showModal();
            }
        });
    }

    hooks.formatBytes = formatBytes;
    hooks.formatEta = formatEta;
    hooks.formatRelativeTime = formatRelativeTime;
    hooks.overviewSignature = overviewSignature;
    hooks.staleOverview = staleOverview;
    hooks.emptyOverviewMode = emptyOverviewMode;
    hooks.selectedOverview = selectedOverview;
    hooks.cardSpeed = cardSpeed;
    hooks.categoryText = categoryText;
    hooks.historyStatus = historyStatus;
    hooks.renderHistory = renderHistory;
    hooks.transferSpeedState = transferSpeedState;
    hooks.parseValues = parseValues;
    hooks.renderOverview = renderOverview;
    hooks.refreshOverview = refreshOverview;
    hooks.requestFreshOverview = requestFreshOverview;
})();
