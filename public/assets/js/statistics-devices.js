(function () {
    const section = document.querySelector('[data-statistics-devices]');
    const list = section?.querySelector('[data-device-list]');

    if (!section || !list) {
        return;
    }

    const iconKinds = new Set([
        'android', 'apple', 'audio', 'browser', 'chrome', 'console', 'desktop', 'device',
        'edge', 'firefox', 'home', 'integration', 'opera', 'safari', 'tv', 'windows', 'xbox',
    ]);

    function textElement(tag, className, text) {
        const element = document.createElement(tag);
        element.className = className;
        element.textContent = String(text ?? '');
        return element;
    }

    function renderDevice(item) {
        const card = document.createElement('article');
        const status = ['active', 'recent', 'older'].includes(item.status) ? item.status : 'older';
        card.className = `stats-device-row is-${status}`;

        const iconKind = iconKinds.has(item.icon) ? item.icon : 'device';
        const icon = document.createElement('span');
        icon.className = `stats-device-icon is-${iconKind}`;
        icon.setAttribute('aria-hidden', 'true');

        const graphic = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        graphic.setAttribute('viewBox', '0 0 24 24');
        graphic.setAttribute('focusable', 'false');
        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', `#stats-device-icon-${iconKind}`);
        graphic.append(use);
        icon.append(graphic);

        const identity = document.createElement('div');
        identity.className = 'stats-device-identity';
        const name = textElement('strong', '', item.name || 'Unknown device');
        name.title = item.name || 'Unknown device';
        identity.append(name);

        const client = document.createElement('span');
        client.append(textElement('span', '', item.app || 'Unknown client'));
        if (item.version) {
            client.append(textElement('b', '', item.version));
        }
        identity.append(client);

        const activity = document.createElement('div');
        activity.className = 'stats-device-activity';
        const user = textElement('span', '', item.user || 'Unknown user');
        user.title = item.user || 'Unknown user';
        const lastSeen = textElement('small', '', item.lastSeen || 'Last seen unknown');
        lastSeen.title = item.lastSeenExact || '';
        activity.append(user, lastSeen);

        card.append(icon, identity, activity);
        return card;
    }

    function setValue(name, value) {
        const target = section.querySelector(`[data-device-${name}]`);
        if (target) {
            target.textContent = String(value ?? 0);
        }
    }

    function render(payload) {
        const items = Array.isArray(payload.items) ? payload.items : [];
        setValue('known', payload.known);
        setValue('seen', payload.seen);
        setValue('active', payload.active);

        const rangeCopy = section.querySelector('[data-device-range-copy]');
        if (rangeCopy) {
            rangeCopy.textContent = payload.rangeLabel || 'seen in this period';
        }

        list.replaceChildren();
        if (items.length === 0) {
            const empty = textElement('p', 'stats-device-empty', Number(payload.known) > 0
                ? 'No devices were seen in this period.'
                : 'Jellyfin has not reported any registered devices yet.');
            list.append(empty);
        } else {
            items.forEach((item) => list.append(renderDevice(item)));
        }

        const note = section.querySelector('[data-device-note]');
        if (note) {
            note.hidden = !payload.hasMore;
        }

        const manageLink = section.querySelector('[data-device-manage]');
        if (manageLink) {
            manageLink.hidden = true;
            manageLink.removeAttribute('href');
        }
        if (manageLink && payload.manageUrl) {
            try {
                const url = new URL(payload.manageUrl);
                if ((url.protocol === 'http:' || url.protocol === 'https:') && !url.username && !url.password) {
                    manageLink.href = url.href;
                    manageLink.hidden = false;
                }
            } catch (error) {
                // Invalid configured URLs leave the optional link hidden.
            }
        }

        section.hidden = false;
    }

    async function loadDevices() {
        const range = section.dataset.range || 'week';
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = window.setTimeout(() => controller?.abort(), 8000);
        let response;
        try {
            response = await fetch(`/api/client-activity.php?range=${encodeURIComponent(range)}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller?.signal,
            });
        } finally {
            window.clearTimeout(timer);
        }
        if (!response.ok) {
            return;
        }

        render(await response.json());
    }

    loadDevices().catch(() => {
        // Optional by design: an unavailable Jellyfin server leaves this hidden.
    });
}());
