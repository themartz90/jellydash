'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const rootDir = path.resolve(__dirname, '..', '..');

function classList(initial = []) {
    const values = new Set(initial);
    return {
        add: (...names) => names.forEach((name) => values.add(name)),
        remove: (...names) => names.forEach((name) => values.delete(name)),
        toggle: (name, force) => force ? values.add(name) : values.delete(name),
        contains: (name) => values.has(name),
    };
}

function runScript(file, globals) {
    vm.runInNewContext(fs.readFileSync(path.join(rootDir, file), 'utf8'), globals, { filename: file });
}

function stream(lines) {
    const encoder = new TextEncoder();
    return new ReadableStream({
        start(controller) {
            lines.forEach((line) => controller.enqueue(encoder.encode(line)));
            controller.close();
        },
    });
}

async function testHistoryStreams() {
    const hooks = {};
    const document = {
        querySelector: () => null,
        querySelectorAll: () => [],
    };
    runScript('public/assets/js/history-import.js', {
        window: { JellydashFrontendTestHooks: hooks }, document, fetch: () => {},
        TextDecoder, Uint8Array, URLSearchParams, FormData,
    });

    const complete = await hooks.readImportNdjson(stream([
        '{"phase":"importing","processed":1,"total":1,"inserted":1,"skipped":0}\n',
        '{"phase":"done","processed":1,"total":1,"inserted":1,"skipped":0}\n',
    ]));
    assert.equal(complete.phase, 'done');

    await assert.rejects(
        hooks.readImportNdjson(stream(['{"phase":"importing","processed":1,"total":2,"inserted":1,"skipped":0}\n'])),
        /Import progress was interrupted/,
    );
    await assert.rejects(
        hooks.readImportNdjson(stream(['{"phase":"importing"'])),
        /Import progress was interrupted/,
    );
    await assert.rejects(hooks.readImportNdjson(stream([])), /Import progress was interrupted/);
    await assert.rejects(
        hooks.readImportNdjson(stream(['{"phase":"error","error":"Database write failed"}\n'])),
        /Database write failed/,
    );

    const nonOkHooks = {};
    runScript('public/assets/js/history-import.js', {
        window: { JellydashFrontendTestHooks: nonOkHooks }, document,
        fetch: async () => ({
            ok: false,
            status: 500,
            headers: { get: () => 'application/x-ndjson' },
            body: stream(['{"phase":"done","inserted":1}\n']),
        }),
        TextDecoder, Uint8Array, URLSearchParams, FormData,
    });
    await assert.rejects(
        nonOkHooks.commitHistoryImport(new FormData(), null, '/import'),
        /HTTP 500/,
    );
}

function element(initialClasses = []) {
    return {
        textContent: '', innerHTML: '', classList: classList(initialClasses), children: [], attributes: {},
        addEventListener() {}, querySelectorAll() { return []; },
        appendChild(child) { this.children.push(child); },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
}

async function testNowPlayingTransitions() {
    const root = element();
    const label = element();
    const dot = element(['is-idle']);
    const nav = element(['is-loading']);
    const selectors = {
        '[data-now-playing-root]': root,
        '[data-live-label]': label,
        '[data-live-dot]': dot,
        '[data-nav-count]': nav,
    };
    const document = {
        querySelector: (selector) => selectors[selector] || null,
        querySelectorAll: () => [],
        createElement: () => element(),
        addEventListener() {},
    };
    const hooks = {};
    const intervals = [];
    let response = { ok: true, status: 200, json: async () => ({ streams: [], stats: { active_streams: 2, active_users: 1 } }) };
    const window = {
        JellydashFrontendTestHooks: hooks,
        setInterval: (callback) => intervals.push(callback),
        dispatchEvent() {},
    };
    runScript('public/assets/js/now-playing.js', {
        window, document, fetch: async () => response,
        CustomEvent: function () {}, Element: function () {}, Date, Array, Number,
    });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(nav.textContent, '2');

    response = { ok: false, status: 503 };
    await hooks.refreshNowPlaying().catch(hooks.renderNowPlayingError);
    assert.equal(nav.textContent, '-');
    assert.equal(root.classList.contains('is-stale'), true);
    assert.match(label.textContent, /^Updates paused/);

    response = { ok: true, status: 200, json: async () => ({ streams: [], stats: { active_streams: 0, active_users: 0 } }) };
    await hooks.refreshNowPlaying();
    assert.equal(root.classList.contains('is-stale'), false);
    assert.equal(dot.classList.contains('is-stale'), false);
    assert.equal(label.textContent, 'No active sessions');
    assert.equal(nav.textContent, '0');
    response = { ok: false, status: 401 };
    await hooks.refreshNowPlaying().catch(hooks.renderNowPlayingError);
    assert.equal(label.children[0].href, '/login');
    assert.equal(label.children[0].textContent, 'Sign in again');

    const loginLink = label.children[0];
    await hooks.refreshNowPlaying();
    assert.equal(label.children[0], loginLink, 'Authentication expiry remains terminal until reload.');

}

async function testNowPlayingDiagnosticsRefresh() {
    const listeners = {};
    const document = {
        activeElement: null,
        addEventListener(type, listener) { listeners[type] = listener; },
    };
    class Node {
        constructor(tagName, attributes = {}, text = null) {
            this.tagName = tagName;
            this.nodeType = text === null ? 1 : 3;
            this.nodeValue = text;
            this.attrs = { ...attributes };
            this.childNodes = [];
            this.parentNode = null;
            this.style = {};
            this.classList = {
                contains: (name) => this.className.split(' ').includes(name),
                toggle: (name, value) => {
                    const classes = new Set(this.className.split(' ').filter(Boolean));
                    value ? classes.add(name) : classes.delete(name);
                    this.className = [...classes].join(' ');
                },
                remove: (...names) => names.forEach((name) => this.classList.toggle(name, false)),
            };
        }
        get attributes() { return Object.entries(this.attrs).map(([name, value]) => ({ name, value })); }
        get dataset() { return { streamId: this.attrs['data-stream-id'] }; }
        get className() { return this.attrs.class || ''; }
        set className(value) { this.attrs.class = value; }
        get children() { return this.childNodes.filter((node) => node.nodeType === 1); }
        get outerHTML() {
            if (this.nodeType === 3) return this.nodeValue;
            const attributes = Object.entries(this.attrs).map(([name, value]) => ` ${name}="${value}"`).join('');
            return `<${this.tagName}${attributes}>${this.innerHTML}</${this.tagName}>`;
        }
        get innerHTML() { return this.childNodes.map((node) => node.outerHTML).join(''); }
        set innerHTML(markup) {
            this.childNodes.slice().forEach((node) => node.remove());
            parse(`<div>${markup}</div>`).childNodes.slice().forEach((node) => this.append(node));
        }
        get textContent() { return this.nodeType === 3 ? this.nodeValue : this.childNodes.map((node) => node.textContent).join(''); }
        set textContent(value) { this.childNodes = []; this.append(new Node(null, {}, String(value))); }
        get inert() { return Object.hasOwn(this.attrs, 'inert'); }
        set inert(value) { value ? this.setAttribute('inert', '') : this.removeAttribute('inert'); }
        get disabled() { return Object.hasOwn(this.attrs, 'disabled'); }
        set disabled(value) { value ? this.setAttribute('disabled', '') : this.removeAttribute('disabled'); }
        get tabIndex() { return this.attrs.tabindex === undefined ? (this.tagName === 'BUTTON' ? 0 : -1) : Number(this.attrs.tabindex); }
        set tabIndex(value) { this.setAttribute('tabindex', String(value)); }
        setAttribute(name, value) {
            this.attrs[name] = String(value);
            if (name === 'style') this.style.width = String(value).match(/width: ([^;]+)/)?.[1] || '';
        }
        getAttribute(name) { return this.attrs[name] ?? null; }
        hasAttribute(name) { return Object.hasOwn(this.attrs, name); }
        removeAttribute(name) { delete this.attrs[name]; }
        append(node) { this.insertBefore(node, null); }
        insertBefore(node, before) {
            node.remove();
            node.parentNode = this;
            this.childNodes.splice(before ? this.childNodes.indexOf(before) : this.childNodes.length, 0, node);
        }
        remove() {
            if (this.parentNode && this.contains(document.activeElement)) document.activeElement = null;
            if (this.parentNode) this.parentNode.childNodes.splice(this.parentNode.childNodes.indexOf(this), 1);
            this.parentNode = null;
        }
        replaceWith(node) { this.parentNode.insertBefore(node, this); this.remove(); }
        contains(node) { return this === node || this.childNodes.some((child) => child.contains(node)); }
        matches(selector) {
            if (selector.startsWith('[')) return this.hasAttribute(selector.slice(1, -1));
            if (selector.startsWith('.')) return selector.slice(1).split('.').every((name) => this.classList.contains(name));
            return this.tagName === selector.toUpperCase();
        }
        closest(selector) { return this.matches(selector) ? this : this.parentNode?.closest(selector) || null; }
        querySelectorAll(selector) {
            return this.childNodes.flatMap((child) => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
        }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        addEventListener(type, listener) { this.listener = listener; }
        focus() { if (!this.closest('[inert]') && !this.disabled) document.activeElement = this; }
        click() { root.listener({ target: this }); }
    }
    function parse(markup) {
        const fragment = new Node('FRAGMENT');
        const stack = [fragment];
        for (const token of markup.match(/<[^>]*>|[^<]+/g) || []) {
            if (token.startsWith('</')) { stack.pop(); continue; }
            if (!token.startsWith('<')) { stack.at(-1).append(new Node(null, {}, token)); continue; }
            const tag = token.match(/^<([\w-]+)/)[1].toUpperCase();
            const attributes = {};
            for (const match of token.matchAll(/([\w-]+)(?:="([^"]*)")?/g)) {
                if (match.index > 1) attributes[match[1]] = match[2] || '';
            }
            const node = new Node(tag, attributes);
            const width = attributes.style?.match(/width: ([^;]+)/)?.[1];
            if (width) node.style.width = width;
            stack.at(-1).append(node);
            if (!['IMG', 'INPUT', 'BR'].includes(tag)) stack.push(node);
        }
        return fragment.children[0];
    }
    const root = new Node('DIV');
    const grid = new Node('DIV', { class: 'stream-grid' });
    root.append(grid);
    const label = new Node('SPAN');
    const dot = new Node('SPAN');
    const before = new Node('BUTTON');
    const after = new Node('BUTTON');
    document.querySelector = (selector) => ({ '[data-now-playing-root]': root, '[data-live-label]': label, '[data-live-dot]': dot }[selector] || null);
    document.createElement = () => ({
        set innerHTML(markup) { this.content = { firstElementChild: parse(markup) }; },
    });
    const hooks = {};
    const first = { id: 'same-session', itemId: 'episode-1', diagnosticsId: 'same-diagnostics', title: 'First episode',
        subtitle: 'S1 E1', avatarUrl: '/avatar-one.png', isTranscode: true, sourceMediaLabel: 'HEVC · MKV', outputMediaLabel: 'H.264 · TS',
        transcodeReasons: ['Video codec not supported'], bitrateLabel: '8 Mbps', progressPct: '25%', timeLabel: '10:00 / 40:00' };
    let payload = { streams: [first], stats: { active_streams: 1, active_users: 1 } };
    let selection = null;
    runScript('public/assets/js/now-playing.js', {
        window: { JellydashFrontendTestHooks: hooks, setInterval() {}, dispatchEvent() {}, getSelection: () => selection },
        document, fetch: async () => ({ ok: true, json: async () => payload }),
        Element: Node, CustomEvent: function () {}, Date, Array, Number,
    });
    await new Promise((resolve) => setImmediate(resolve));
    const card = grid.children[0];
    const opener = card.querySelector('[data-diagnostics-open]');
    const close = card.querySelector('[data-diagnostics-close]');
    opener.focus();
    opener.click(); // Native Enter activates this same click handler.
    assert.equal(document.activeElement, close);
    assert.equal(opener.disabled || opener.inert || opener.tabIndex < 0, true);

    const avatar = card.querySelector('.watcher-avatar');
    const avatarImage = avatar.querySelector('[data-avatar-img]');
    // Model the state installed by avatars.js after the image loads.
    avatar.classList.toggle('has-image', true);
    avatarImage.setAttribute('data-avatar-bound', '1');
    await hooks.refreshNowPlaying();
    assert.equal(avatar.classList.contains('has-image'), true, 'A protected poll keeps a loaded avatar visible');
    assert.equal(avatar.querySelector('[data-avatar-img]'), avatarImage);
    assert.equal(avatarImage.getAttribute('data-avatar-bound'), '1', 'Retained images keep their existing event bindings');

    payload = { ...payload, streams: [{ ...first, itemId: 'episode-2', title: 'Next episode', subtitle: 'S1 E2',
        sourceMediaLabel: 'AV1 · MP4', outputMediaLabel: 'H.264 · MP4',
        transcodeReasons: ['Audio codec not supported', 'Subtitle format not supported'], bitrateLabel: '3 Mbps',
        progressPct: '5%', timeLabel: '2:00 / 40:00', isPaused: true, statusLabel: 'Paused' }] };
    await hooks.refreshNowPlaying();
    assert.equal(card.querySelector('h3').textContent, 'Next episode');
    assert.match(card.querySelector('[data-diagnostics-overlay]').textContent, /AV1 · MP4/);
    assert.match(card.querySelector('[data-diagnostics-overlay]').textContent, /Audio codec not supported/);
    assert.match(card.querySelector('[data-diagnostics-overlay]').textContent, /3 Mbps/);
    assert.doesNotMatch(card.textContent, /First episode|HEVC|Video codec not supported|8 Mbps/);
    assert.equal(card.querySelector('[data-progress-time]').textContent, '2:00 / 40:00');
    assert.equal(card.querySelector('[data-progress-fill]').style.width, '5%');
    assert.match(card.querySelector('[data-stream-status]').textContent, /Paused/);
    assert.equal(card.querySelector('[data-stream-status]').classList.contains('is-paused'), true);
    assert.equal(card.querySelector('[data-diagnostics-close]'), close);
    assert.equal(card.querySelector('[data-diagnostics-open]'), opener);
    assert.equal(document.activeElement, close);
    assert.equal(card.classList.contains('is-diagnostics-open'), true);
    assert.equal(card.querySelector('[data-diagnostics-overlay]').inert, false);
    assert.equal(opener.disabled || opener.inert || opener.tabIndex < 0, true);

    // Model native sequential focus from the enabled, non-inert controls.
    const tabOrder = [before, opener, close, after].filter((node) => !node.disabled && node.tabIndex >= 0 && !node.closest('[inert]'));
    assert.deepEqual(tabOrder, [before, close, after]);
    assert.equal(tabOrder[tabOrder.indexOf(close) - 1], before); // Shift+Tab
    assert.equal(tabOrder[tabOrder.indexOf(close) + 1], after); // Tab
    listeners.keydown({ key: 'Escape' });
    assert.equal(document.activeElement, opener);
    assert.equal(opener.disabled || opener.inert || opener.tabIndex < 0, false);
    assert.equal(card.querySelector('[data-diagnostics-overlay]').inert, true);

    payload = { ...payload, streams: [{ ...payload.streams[0], progressPct: '8%', timeLabel: '3:12 / 40:00' }] };
    await hooks.refreshNowPlaying();
    assert.equal(document.activeElement, opener);
    assert.equal(card.querySelector('[data-diagnostics-open]'), opener);
    assert.equal(card.querySelector('[data-progress-time]').textContent, '3:12 / 40:00');
    assert.equal(avatar.classList.contains('has-image'), true, 'A focused opener also preserves the loaded avatar');
    payload = { ...payload, streams: [{ ...payload.streams[0], avatarUrl: '/avatar-two.png' }] };
    await hooks.refreshNowPlaying();
    assert.equal(avatar.classList.contains('has-image'), false, 'A changed avatar waits for its own successful load');
    assert.equal(avatarImage.getAttribute('src'), '/avatar-two.png');
    assert.equal(avatarImage.getAttribute('data-avatar-bound'), '1');
    avatar.classList.toggle('has-image', true);
    payload = { ...payload, streams: [{ ...payload.streams[0], avatarUrl: '' }] };
    await hooks.refreshNowPlaying();
    assert.equal(avatar.classList.contains('has-image'), false, 'Removing the image restores the initials');
    assert.equal(avatar.querySelector('[data-avatar-img]'), null);
    opener.click();
    close.click();
    assert.equal(document.activeElement, opener);

    opener.click();
    payload = { ...payload, streams: [{ ...payload.streams[0], bitrateLabel: '', transcodeReasons: [] }] };
    await hooks.refreshNowPlaying();
    assert.equal(card.querySelector('[data-diagnostics-close]'), close);
    assert.equal(card.querySelector('[data-diagnostics-open]'), opener);
    assert.equal(document.activeElement, close);
    assert.doesNotMatch(card.textContent, /3 Mbps|Audio codec not supported|Subtitle format not supported/);

    after.focus();
    await hooks.refreshNowPlaying();
    assert.equal(document.activeElement, after);
    close.click();
    after.focus();
    const selectedTitle = card.querySelector('h3').childNodes[0];
    selection = { isCollapsed: false, anchorNode: selectedTitle, focusNode: selectedTitle };
    await hooks.refreshNowPlaying();
    assert.equal(card.querySelector('h3').childNodes[0], selectedTitle);
    assert.equal(selectedTitle.nodeValue, 'Next episode');
    assert.equal(document.activeElement, after);
    selection = null;
    opener.click();
    close.focus();

    const originalQuerySelector = document.querySelector;
    document.querySelector = (selector) => selector === '[data-nav-toggle]' ? before : originalQuerySelector(selector);
    payload = { ...payload, streams: [{ ...payload.streams[0], isTranscode: false, methodLabel: 'Direct Play' }] };
    await hooks.refreshNowPlaying();
    assert.equal(card.querySelector('[data-diagnostics-overlay]'), null);
    assert.equal(card.classList.contains('is-diagnostics-open'), false);
    assert.equal(document.activeElement, before);

    // A loses its focused diagnostics while B refreshes later in the same poll.
    const second = { ...first, id: 'second-session', itemId: 'second-item', diagnosticsId: 'second-diagnostics', title: 'Surviving stream' };
    payload = { ...payload, streams: [first, second] };
    await hooks.refreshNowPlaying();
    grid.children[0].querySelector('[data-diagnostics-open]').click();
    assert.equal(document.activeElement, grid.children[0].querySelector('[data-diagnostics-close]'));
    payload = { ...payload, streams: [{ ...first, isTranscode: false, methodLabel: 'Direct Play' }, second] };
    await hooks.refreshNowPlaying();
    const survivor = grid.children[1].querySelector('[data-diagnostics-open]');
    assert.equal(document.activeElement === survivor, true, 'Focus returns to the surviving opener after every card refresh finishes');
    assert.equal(root.contains(document.activeElement), true);
    assert.equal(survivor.disabled, false);

    // Removing focused A must also wait until B has finished reconciling.
    payload = { ...payload, streams: [first, second] };
    await hooks.refreshNowPlaying();
    grid.children[0].querySelector('[data-diagnostics-open]').click();
    payload = { ...payload, streams: [second] };
    await hooks.refreshNowPlaying();
    assert.equal(document.activeElement === grid.children[0].querySelector('[data-diagnostics-open]'), true);
    assert.equal(root.contains(document.activeElement), true);
}

async function testNavCountTransitions() {
    const nav = element(['is-loading']);
    const document = { querySelector: (selector) => selector === '[data-nav-count]' ? nav : null };
    const hooks = {};
    let response = { ok: true, status: 200, json: async () => ({ stats: { active_streams: 4 } }) };
    runScript('public/assets/js/nav-count.js', {
        window: { JellydashFrontendTestHooks: hooks, setInterval() {} }, document,
        fetch: async () => response, Number,
    });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(nav.textContent, '4');

    response = { ok: false, status: 503 };
    await hooks.refreshNavCount().catch(hooks.clearNavCount);
    assert.equal(nav.textContent, '-');
    assert.equal(nav.classList.contains('is-stale'), true);

    response = { ok: true, status: 200, json: async () => ({ stats: { active_streams: 1 } }) };
    await hooks.refreshNavCount();
    assert.equal(nav.textContent, '1');
    assert.equal(nav.classList.contains('is-stale'), false);
}

async function testNavCountLifecycleAndSingleFlight() {
    const nav = element(['is-loading']);
    const documentListeners = {};
    const windowListeners = {};
    const intervals = [];
    let fetchCount = 0;
    let resolveFetch;
    const document = {
        hidden: true,
        querySelector: (selector) => selector === '[data-nav-count]' ? nav : null,
        addEventListener(type, callback) { documentListeners[type] = callback; },
    };
    const hooks = {};
    const window = {
        JellydashFrontendTestHooks: hooks,
        setInterval(callback) { intervals.push(callback); },
        setTimeout() { return 1; },
        clearTimeout() {},
        addEventListener(type, callback) { windowListeners[type] = callback; },
    };
    const fetch = () => {
        fetchCount += 1;
        return new Promise((resolve) => { resolveFetch = resolve; });
    };

    runScript('public/assets/js/nav-count.js', { window, document, fetch, Number });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(fetchCount, 0);
    assert.equal(intervals.length, 1);

    document.hidden = false;
    documentListeners.visibilitychange();
    hooks.refreshNavCount();
    assert.equal(fetchCount, 1);

    resolveFetch({ ok: true, status: 200, json: async () => ({ stats: { active_streams: 3 } }) });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(nav.textContent, '3');

    windowListeners.pageshow();
    assert.equal(fetchCount, 2);
}

async function testDeviceManagementLinkVisibility() {
    async function render(manageUrl) {
        const manage = element();
        manage.hidden = false;
        const list = element();
        list.replaceChildren = function () { this.children = []; };
        list.append = function (child) { this.children.push(child); };
        const note = element();
        const rangeCopy = element();
        const section = element();
        section.hidden = true;
        section.dataset = { range: 'week' };
        section.querySelector = (selector) => ({
            '[data-device-list]': list,
            '[data-device-range-copy]': rangeCopy,
            '[data-device-note]': note,
            '[data-device-manage]': manage,
        }[selector] || null);
        const document = {
            querySelector: (selector) => selector === '[data-statistics-devices]' ? section : null,
            createElement: () => element(),
            createElementNS: () => element(),
        };
        const window = { setTimeout() { return 1; }, clearTimeout() {} };
        const fetch = async () => ({
            ok: true,
            json: async () => ({ items: [], known: 0, seen: 0, active: 0, manageUrl }),
        });

        runScript('public/assets/js/statistics-devices.js', { window, document, fetch, URL, Set, Number, AbortController });
        await new Promise((resolve) => setImmediate(resolve));
        return manage;
    }

    const hidden = await render('');
    assert.equal(hidden.hidden, true);
    assert.equal(hidden.attributes.href, undefined);

    const visible = await render('https://jellyfin.example/web/#/dashboard/devices');
    assert.equal(visible.hidden, false);
    assert.equal(visible.href, 'https://jellyfin.example/web/#/dashboard/devices');
}

function testDashboardContentScrollRestoration() {
    const listeners = {};
    const frames = [];
    const pane = { scrollTop: 684 };
    let replacedState = null;
    let replacedUrl = null;
    const window = {
        history: {
            state: { existing: 'kept' },
            replaceState(state, title, url) {
                this.state = state;
                replacedState = state;
                replacedUrl = url;
            },
        },
        location: { href: 'http://jellydash.loc/statistics?range=all' },
        addEventListener(type, callback) { listeners[type] = callback; },
        requestAnimationFrame(callback) { frames.push(callback); },
        scrollY: 321,
    };
    const document = {
        querySelector: (selector) => selector === '.dashboard-content' ? pane : null,
    };

    runScript('public/assets/js/dashboard-scroll.js', { window, document, Number });
    listeners.pagehide();

    assert.equal(replacedState.existing, 'kept');
    assert.equal(replacedState.jellydashContentScrollTop, 684);
    assert.equal(replacedUrl, window.location.href);

    pane.scrollTop = 0;
    listeners.pageshow();
    assert.equal(frames.length, 1);
    frames.shift()();
    assert.equal(pane.scrollTop, 684);
    assert.equal(window.scrollY, 321);
}

(async () => {
    await testHistoryStreams();
    await testNowPlayingTransitions();
    await testNowPlayingDiagnosticsRefresh();
    await testNavCountTransitions();
    await testNavCountLifecycleAndSingleFlight();
    await testDeviceManagementLinkVisibility();
    testDashboardContentScrollRestoration();
    console.log('Frontend state tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
