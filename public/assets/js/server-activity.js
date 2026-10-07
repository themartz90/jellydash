(function () {
    'use strict';
    const root = document.querySelector('[data-server-activity-root]');
    if (!root) return;
    const find = (name) => document.querySelector(`[data-activity-${name}]`);
    const form = find('filters');
    const refresh = find('refresh');
    const typeControl = find('type');
    const queryKeys = ['range', 'type', 'severity', 'actor', 'start', 'end', 'anchor', 'page'];
    let query = new URLSearchParams();
    let page = 1;
    let pages = 1;
    let timezone = 'UTC';
    let overviewController;
    let eventController;
    let eventRequest = 0;
    let overviewRequest = 0;
    let stopped = false;
    let lastOverview = 0;
    let lastEvents = 0;

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = String(text);
        return node;
    }

    function message(name, text) {
        const node = find(name);
        node.textContent = text || '';
        node.hidden = !text;
    }

    function empty(target, title, detail) {
        const note = element('p', 'activity-empty');
        if (title) note.append(element('strong', '', title));
        if (detail) note.append(document.createTextNode(detail));
        target.replaceChildren(note);
    }

    function formattedDate(timestamp, options) {
        return new Intl.DateTimeFormat(undefined, { timeZone: timezone, ...options }).format(new Date(timestamp * 1000));
    }

    function localDay(timestamp) {
        return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(timestamp * 1000));
    }

    function readableType(value) { return String(value || 'Other event').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_:.-]+/g, ' '); }
    function duration(seconds) {
        if (!Number.isFinite(seconds)) return '';
        if (seconds < 60) return `${seconds}s`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
        return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
    }

    function status(node, text, state) { node.className = `activity-state is-${state}`; node.textContent = text; }

    function stopForAccess(code) {
        stopped = true;
        overviewController?.abort();
        eventController?.abort();
        for (const name of ['server', 'running', 'events']) find(name).setAttribute('aria-busy', 'false');
        find('updated').textContent = code === 401 ? 'Sign in required' : 'Access denied';
        find('server-name').textContent = 'Server Activity';
        find('server-meta').textContent = '';
        find('open-jellyfin').hidden = true;
        status(find('server-state'), code === 401 ? 'Sign in required' : 'Access denied', 'warning');
        empty(find('running'), '', 'Server Activity is available to owners and admins.');
        find('recent-tasks').hidden = true;
        empty(find('events'), '', code === 401 ? 'Sign in to view Server Activity.' : 'Your account cannot view Server Activity.');
        if (code === 401) {
            const link = element('a', 'activity-button', 'Sign in');
            link.href = '/login';
            find('events').append(link);
        }
        find('pagination').hidden = true;
        message('coverage', '');
        message('tasks-message', '');
        message('events-message', '');
        find('task-count').textContent = '';
        find('event-count').textContent = '';
        find('event-summary').textContent = '';
        refresh.disabled = true;
        for (const control of form.elements) control.disabled = true;
    }

    async function request(params, controller) {
        const timer = window.setTimeout(() => controller.abort(), 13000);
        try {
            const response = await fetch(`/api/server-activity.php?${params}`, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
            if (response.status === 401 || response.status === 403) { stopForAccess(response.status); return null; }
            const payload = await response.json();
            if (!response.ok && response.status !== 409) throw new Error(payload.error || 'Server Activity could not be loaded. Try refreshing shortly.');
            return payload;
        } finally { window.clearTimeout(timer); }
    }

    function renderServer(section) {
        const target = find('server');
        target.setAttribute('aria-busy', 'false');
        const data = section.data;
        message('server-message', section.state !== 'ready' ? section.message : '');
        if (!data) {
            find('server-name').textContent = section.state === 'unconfigured' ? 'Jellyfin is not configured' : 'Server details unavailable';
            find('server-meta').textContent = 'Other sections can still load when available.';
            find('open-jellyfin').hidden = true;
            status(find('server-state'), section.state === 'forbidden' ? 'Limited access' : 'Unavailable', 'warning');
            return;
        }
        find('server-name').textContent = data.name;
        find('server-meta').textContent = [`Jellyfin ${data.version}`, data.package].filter(Boolean).join(' · ');
        status(find('server-state'), section.stale ? 'Last known details' : data.pending_restart ? 'Restart pending' : 'Connected', section.stale || data.pending_restart ? 'warning' : 'ready');
        const link = find('open-jellyfin');
        try {
            const url = new URL(data.url);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error();
            link.href = url.href;
            link.hidden = false;
        } catch { link.hidden = true; }
    }

    function renderTasks(section) {
        const target = find('running');
        target.setAttribute('aria-busy', 'false');
        message('tasks-message', section.state !== 'ready' ? section.message : '');
        const data = section.data;
        if (!data) {
            empty(target, 'Tasks unavailable', section.state === 'forbidden' ? 'Check that your Jellyfin credential can read scheduled tasks.' : 'Try refreshing shortly.');
            find('recent-tasks').hidden = true;
            find('task-count').textContent = section.state === 'checking' ? 'Refreshing' : 'Unavailable';
            return;
        }
        find('task-count').textContent = section.stale ? 'Last known' : data.running.length ? `${data.running.length} running` : 'Idle';
        target.replaceChildren();
        if (!data.running.length) empty(target, 'No tasks are running', 'Jellyfin has no maintenance work in progress.');
        for (const task of data.running) {
            const row = element('div', 'activity-task');
            const head = element('div', 'activity-task-head');
            head.append(element('span', 'activity-task-name', task.name));
            head.append(element('span', 'activity-result-status', section.stale ? 'Last seen running' : task.progress === null ? task.state : `${task.progress}%`));
            row.append(head);
            if (!section.stale) {
                const progress = element('div', `activity-progress${task.progress === null ? ' is-unknown' : ''}`);
                progress.setAttribute('role', 'progressbar');
                progress.setAttribute('aria-label', `${task.name} progress`);
                progress.setAttribute('aria-valuemin', '0');
                progress.setAttribute('aria-valuemax', '100');
                if (task.progress !== null) { progress.setAttribute('aria-valuenow', String(task.progress)); const bar = element('span'); bar.style.width = `${task.progress}%`; progress.append(bar); }
                else progress.setAttribute('aria-valuetext', 'Progress is not reported');
                row.append(progress);
            }
            target.append(row);
        }
        const results = find('task-results');
        results.replaceChildren();
        find('recent-tasks').hidden = !data.recent.length;
        for (const task of data.recent) {
            const row = element('div', 'activity-task-result');
            const copy = element('div');
            copy.append(element('div', 'activity-task-name', task.name));
            copy.append(element('p', 'activity-task-caption', [formattedDate(task.ended_at, { hour: '2-digit', minute: '2-digit', month: 'short', day: 'numeric' }), duration(task.duration)].filter(Boolean).join(' · ')));
            row.append(copy, element('span', `activity-result-status is-${task.status.toLowerCase()}`, task.status));
            results.append(row);
        }
    }

    function renderEvents(section) {
        const target = find('events');
        target.setAttribute('aria-busy', 'false');
        message('events-message', section.state !== 'ready' ? section.message : '');
        const data = section.data;
        if (!data) {
            empty(target, section.state === 'expired' ? 'Refresh this activity view' : 'Activity unavailable', section.state === 'forbidden' ? 'Check that your Jellyfin credential can read the activity log.' : 'Use Refresh to try again.');
            find('pagination').hidden = true;
            find('event-count').textContent = 'Unavailable';
            find('event-summary').textContent = 'Events could not be read.';
            message('coverage', '');
            return;
        }
        timezone = data.timezone || timezone;
        page = data.page;
        pages = data.pages;
        query.set('anchor', String(data.anchor));
        query.set('page', String(page));
        const currentType = typeControl.value;
        const appliedType = query.get('type') || '';
        const types = [...new Set([...(data.types || []), ...[currentType, appliedType].filter(Boolean)])].sort();
        if (Array.from(typeControl.options).slice(1).map((option) => option.value).join('|') !== types.join('|')) {
            typeControl.replaceChildren(new Option('All types', ''));
            for (const type of types) typeControl.append(new Option(readableType(type), type));
            typeControl.value = currentType;
        }
        find('event-count').textContent = `${data.total} ${data.total === 1 ? 'event' : 'events'}`;
        find('event-summary').textContent = `Snapshot at ${formattedDate(data.anchor, { hour: '2-digit', minute: '2-digit', second: '2-digit' })} · ${data.timezone}${section.stale ? ' · Last known activity' : ''}`;
        message('coverage', data.limited ? `Filters apply to ${data.read_count.toLocaleString()} read events out of ${data.source_total.toLocaleString()} returned by Jellyfin for this snapshot. Older events may be missing; narrow the dates or check Jellyfin for the full log.` : '');
        target.replaceChildren();
        if (!data.items.length) empty(target, data.limited ? 'No matches in the events read' : 'No events match these filters', 'Choose another period or clear a filter.');
        let day = '';
        for (const event of data.items) {
            const nextDay = localDay(event.timestamp);
            if (nextDay !== day) { day = nextDay; target.append(element('h3', 'activity-date-heading', formattedDate(event.timestamp, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' }))); }
            const row = element('article', 'activity-event');
            const time = element('time', '', formattedDate(event.timestamp, { hour: '2-digit', minute: '2-digit', hour12: false }));
            time.dateTime = new Date(event.timestamp * 1000).toISOString();
            time.title = formattedDate(event.timestamp, { dateStyle: 'full', timeStyle: 'long' });
            const marker = element('span', `activity-event-marker is-${event.severity.toLowerCase()}`);
            marker.setAttribute('aria-hidden', 'true');
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', ['Warning', 'Error', 'Critical'].includes(event.severity) ? 'M12 5v9m0 4h.01' : 'M5 12l4 4l10 -10');
            svg.append(path); marker.append(svg);
            const copy = element('div');
            copy.append(element('div', 'activity-event-name', event.name));
            const meta = element('div', 'activity-event-meta');
            meta.append(element('span', '', readableType(event.type)), element('span', `activity-event-level is-${event.severity.toLowerCase()}`, event.severity), element('span', '', event.has_user ? 'User event' : 'System event'));
            copy.append(meta); row.append(time, marker, copy); target.append(row);
        }
        find('pagination').hidden = pages <= 1;
        find('page-label').textContent = `Page ${page} of ${pages}`;
        find('prev').disabled = page <= 1;
        find('next').disabled = page >= pages;
    }

    async function loadOverview() {
        if (stopped || document.hidden) return;
        overviewController?.abort();
        lastOverview = Date.now();
        const id = ++overviewRequest;
        overviewController = new AbortController();
        try {
            const payload = await request(new URLSearchParams({ section: 'overview' }), overviewController);
            if (!payload || id !== overviewRequest || stopped) return;
            timezone = payload.timezone || timezone;
            renderServer(payload.server); renderTasks(payload.tasks);
            find('updated').textContent = `Checked ${formattedDate(Math.floor(Date.now() / 1000), { hour: '2-digit', minute: '2-digit' })}`;
        } catch (error) {
            if (error.name === 'AbortError' && id !== overviewRequest) return;
            if (stopped) return;
            const section = { state: 'unavailable', message: 'Server details could not be loaded. Try refreshing shortly.', data: null };
            renderServer(section); renderTasks(section);
            find('updated').textContent = 'Could not refresh';
        }
        lastOverview = Date.now();
    }

    async function loadEvents(advance = false) {
        if (stopped || document.hidden) return;
        if (advance) { query.delete('anchor'); query.set('page', '1'); page = 1; }
        eventController?.abort();
        lastEvents = Date.now();
        const id = ++eventRequest;
        eventController = new AbortController();
        const params = new URLSearchParams(query); params.set('section', 'activity');
        find('events').setAttribute('aria-busy', 'true');
        try {
            const payload = await request(params, eventController);
            if (!payload || id !== eventRequest || stopped) return;
            renderEvents(payload);
            updateUrl(false);
        } catch (error) {
            if (error.name === 'AbortError' && id !== eventRequest) return;
            if (stopped) return;
            renderEvents({ state: 'unavailable', message: error.name === 'AbortError' ? 'Activity took too long to load. Try refreshing shortly.' : error.message, data: null });
        }
        lastEvents = Date.now();
    }

    function customDates() {
        const custom = find('range').value === 'custom';
        find('custom-dates').hidden = !custom;
        for (const name of ['start', 'end']) { form.elements[name].disabled = !custom; form.elements[name].required = custom; }
    }

    function restoreQuery() {
        query = new URLSearchParams();
        const values = new URLSearchParams(window.location.search);
        for (const key of queryKeys) { if (values.has(key)) query.set(key, values.get(key)); }
        page = Number(query.get('page') || 1);
        for (const key of ['range', 'severity', 'actor', 'start', 'end']) form.elements[key].value = query.get(key) || (key === 'range' ? 'day' : '');
        const type = query.get('type');
        if (type && !Array.from(typeControl.options).some((option) => option.value === type)) typeControl.append(new Option(readableType(type), type));
        typeControl.value = type || '';
        customDates();
    }

    function updateUrl(push) {
        const url = new URL(window.location.href);
        for (const key of queryKeys) url.searchParams.delete(key);
        for (const [key, value] of query) if (value) url.searchParams.set(key, value);
        window.history[push ? 'pushState' : 'replaceState']({}, '', url);
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        query = new URLSearchParams(new FormData(form));
        page = 1;
        updateUrl(true);
        loadEvents(true);
    });
    form.addEventListener('reset', () => { window.setTimeout(() => { query = new URLSearchParams(); page = 1; customDates(); updateUrl(true); loadEvents(true); }, 0); });
    find('range').addEventListener('change', customDates);
    for (const [name, delta] of [['prev', -1], ['next', 1]]) find(name).addEventListener('click', () => { page = Math.max(1, Math.min(pages, page + delta)); query.set('page', String(page)); updateUrl(true); loadEvents(); });
    refresh.addEventListener('click', () => { loadOverview(); loadEvents(true); });
    window.addEventListener('popstate', () => { restoreQuery(); loadEvents(); });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            ++overviewRequest; ++eventRequest;
            overviewController?.abort(); eventController?.abort();
        } else {
            loadOverview(); loadEvents(page === 1);
        }
    });
    window.setInterval(() => {
        if (document.hidden || stopped) return;
        if (Date.now() - lastOverview >= 15000) loadOverview();
        if (page === 1 && Date.now() - lastEvents >= 30000) loadEvents(true);
    }, 5000);
    restoreQuery();
    loadOverview();
    loadEvents();
})();
