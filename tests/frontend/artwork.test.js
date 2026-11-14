'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/artwork.js'), 'utf8');

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
}

function status(overrides = {}) {
    return {
        source: { blocked: false, error: null, legacyConfirmed: true, configured: true, otherSources: 0 },
        summary: { saved: 1, pending: 2, review: 1, bytes: 2048, limitBytes: 1048576 },
        groups: [{ title: 'Show <one>', library: 'TV Shows', kind: 'Episode', count: 2, ids: ['episode-1', 'episode-2'], fingerprint: 'fingerprint-1' }],
        posters: [{ id: 'series-1', title: 'Show <one>', kind: 'Series', hasImage: true, suppressed: false, revision: 3, manualIds: [], manualFingerprint: '' }],
        page: 1,
        pages: 1,
        ...overrides,
    };
}

function response(body, statusCode = 200) {
    return { ok: statusCode >= 200 && statusCode < 300, status: statusCode, json: async () => body };
}

function harness(onFetch) {
    const document = { hidden: false, activeElement: null, listeners: {} };
    class Element {
        constructor(tag = 'div') {
            this.tagName = tag.toUpperCase();
            this.parentNode = null;
            this.children = [];
            this.dataset = {};
            this.attributes = {};
            this.listeners = {};
            this.disabled = false;
            this.hidden = false;
            this.value = '';
            this.ownText = '';
        }
        set textContent(value) { this.replaceChildren(); this.ownText = String(value); }
        get textContent() { return this.ownText + this.children.map((child) => child.textContent).join(''); }
        get lastChild() { return this.children.length ? this.children[this.children.length - 1] : null; }
        get isConnected() { return this === panel || Boolean(this.parentNode && this.parentNode.isConnected); }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        replaceChildren(...children) {
            this.children.forEach((child) => { child.parentNode = null; });
            this.children = [];
            this.ownText = '';
            children.forEach((child) => this.appendChild(child));
        }
        contains(child) { return child === this || this.children.some((item) => item.contains(child)); }
        closest(selector) {
            if (selector === 'button' && this.tagName === 'BUTTON') return this;
            if (selector === 'form[data-artwork-search]' && this.tagName === 'FORM' && this.dataset.artworkSearch) return this;
            return this.parentNode ? this.parentNode.closest(selector) : null;
        }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        getAttribute(name) { return this.attributes[name] ?? null; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        focus() { document.activeElement = this; }
        querySelector(selector) {
            if (selector === 'input[name="search"]') return this.querySelectorAll('input').find((input) => input.name === 'search') || null;
            const match = selector.match(/^\[data-artwork-results="([^"]+)"\]$/);
            if (match) return this.querySelectorAll('[data-artwork-results]').find((element) => element.dataset.artworkResults === match[1]) || null;
            return null;
        }
        querySelectorAll(selector) {
            const all = this.children.flatMap((child) => [child, ...child.querySelectorAll('*')]);
            if (selector === '*') return all;
            if (selector === 'button') return all.filter((child) => child.tagName === 'BUTTON');
            if (selector === 'input') return all.filter((child) => child.tagName === 'INPUT');
            if (selector === '[data-artwork-focus-key]') return all.filter((child) => child.dataset.artworkFocusKey !== undefined);
            if (selector === '[data-artwork-results]') return all.filter((child) => child.dataset.artworkResults !== undefined);
            return [];
        }
    }

    const panel = new Element('section');
    const title = new Element('strong');
    const nodes = new Map();
    const tags = {
        status: 'p', error: 'p', notices: 'div', summary: 'dl', saved: 'dd', pending: 'dd', review: 'dd', storage: 'dd',
        refresh: 'button', retry: 'button', legacy: 'div', backfill: 'button', 'groups-wrap': 'details',
        'groups-count': 'span', groups: 'div', 'posters-wrap': 'details', 'posters-count': 'span', posters: 'div',
        pager: 'nav', page: 'span', prev: 'button', next: 'button',
    };
    Object.keys(tags).forEach((name) => {
        const element = new Element(tags[name]);
        element.dataset['artwork' + name.split('-').map((part) => part[0].toUpperCase() + part.slice(1)).join('')] = 'true';
        nodes.set(name, panel.appendChild(element));
    });
    panel.querySelector = (selector) => {
        const match = selector.match(/^\[data-artwork-([a-z-]+)\]$/);
        if (match) return nodes.get(match[1]) || null;
        const resultsMatch = selector.match(/^\[data-artwork-results="([^"]+)"\]$/);
        if (resultsMatch) return panel.querySelectorAll('[data-artwork-results]').find((element) => element.dataset.artworkResults === resultsMatch[1]) || null;
        return null;
    };
    document.querySelector = (selector) => {
        if (selector === '[data-artwork-recovery]') return panel;
        if (selector === 'meta[name="csrf-token"]') return { content: 'csrf-artwork-fixture' };
        return null;
    };
    document.getElementById = (id) => id === 'artwork-recovery-title' ? title : null;
    document.addEventListener = (name, callback) => { document.listeners[name] = callback; };
    document.createElement = (tag) => new Element(tag);
    const timers = new Map();
    const requests = [];
    const windowListeners = {};
    let timerId = 0;
    const window = {
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        addEventListener(name, callback) { windowListeners[name] = callback; },
        confirm() { return true; },
    };
    vm.runInNewContext(script, {
        document, window, Promise, AbortController, Error, Number, JSON, encodeURIComponent,
        fetch(url, options) {
            const record = { url, options, body: options.body ? JSON.parse(options.body) : null };
            requests.push(record);
            return onFetch(record, requests.length);
        },
    }, { filename: 'public/assets/js/artwork.js' });
    return {
        panel, title, document, nodes, requests, timers,
        button(action) { return panel.querySelectorAll('button').find((item) => item.dataset.artworkAction === action); },
        submit(form) {
            const event = { target: form, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
            panel.listeners.submit(event);
            return event.defaultPrevented;
        },
        click(control) { assert.ok(control, 'The action control must exist.'); if (!control.disabled) { control.focus(); panel.listeners.click({ target: control }); } },
        settle,
    };
}

async function settle() {
    for (let index = 0; index < 8; index += 1) await new Promise((resolve) => setImmediate(resolve));
}

async function searchAndLinkSendScopedPayloadWithCsrf() {
    const ui = harness((request) => {
        if (request.url.includes('search=')) {
            return Promise.resolve(response({ candidates: [{ id: 'target-series', title: 'Current Show <script>', year: 2025, kind: 'Series', poster: '/unused' }] }));
        }
        if (request.options.method === 'POST') {
            return Promise.resolve(response(status({ groups: [], summary: { saved: 2, pending: 1, review: 0, bytes: 4096, limitBytes: 1048576 } })));
        }
        return Promise.resolve(response(status()));
    });
    await settle();
    const form = ui.nodes.get('groups').children[0].children.find((child) => child.tagName === 'FORM');
    const input = form.querySelector('input[name="search"]');
    input.value = 'Current Show';
    ui.submit(form);
    await settle();
    const searchRequest = ui.requests.find((request) => request.url.includes('search='));
    assert.equal(searchRequest.options.method || 'GET', 'GET');
    assert.match(searchRequest.url, /search=Current%20Show&kind=Series/);
    const candidateText = ui.nodes.get('groups').textContent;
    assert.ok(candidateText.includes('Current Show <script>'), 'API titles must be rendered as text.');
    const link = ui.button('link');
    ui.click(link);
    await settle();
    const mutation = ui.requests.at(-1);
    assert.equal(mutation.options.method, 'POST');
    assert.equal(mutation.options.headers['X-CSRF-Token'], 'csrf-artwork-fixture');
    assert.deepEqual(mutation.body, {
        action: 'link', ids: ['episode-1', 'episode-2'], fingerprint: 'fingerprint-1', targetId: 'target-series',
    });
    assert.equal(ui.document.activeElement, ui.title);
    assert.equal(ui.nodes.get('saved').textContent, '2');
}

async function staleSearchRepliesCannotReplaceTheCurrentCandidates() {
    const first = deferred();
    let searches = 0;
    const ui = harness((request) => {
        if (request.url.includes('search=')) {
            searches += 1;
            return searches === 1 ? first.promise : Promise.resolve(response({ candidates: [{ id: 'new-result', title: 'New result', kind: 'Series' }] }));
        }
        return Promise.resolve(response(status()));
    });
    await settle();
    const form = ui.nodes.get('groups').children[0].children.find((child) => child.tagName === 'FORM');
    const input = form.querySelector('input[name="search"]');
    input.value = 'old query';
    ui.submit(form);
    const firstSignal = ui.requests.at(-1).options.signal;
    input.value = 'new query';
    ui.submit(form);
    await settle();
    assert.equal(firstSignal.aborted, true);
    first.resolve(response({ candidates: [{ id: 'stale-result', title: 'Stale result', kind: 'Series' }] }));
    await settle();
    assert.ok(ui.nodes.get('groups').textContent.includes('New result'));
    assert.ok(!ui.nodes.get('groups').textContent.includes('Stale result'));
}

async function actionErrorsAreShownAndControlsRecover() {
    const ui = harness((request) => request.options.method === 'POST'
        ? Promise.resolve(response({ error: 'The poster could not be cleared.' }, 409))
        : Promise.resolve(response(status())));
    await settle();
    const clear = ui.button('clear');
    ui.click(clear);
    await settle();
    const mutation = ui.requests.at(-1);
    assert.equal(mutation.options.headers['X-CSRF-Token'], 'csrf-artwork-fixture');
    assert.deepEqual(mutation.body, { action: 'clear', id: 'series-1', revision: 3 });
    assert.equal(ui.nodes.get('error').hidden, false);
    assert.equal(ui.nodes.get('error').textContent, 'The poster could not be cleared.');
    assert.equal(clear.disabled, false);
}

async function submittingSearchDuringMutationPreventsNavigationAndDoesNotFetch() {
    const pendingMutation = deferred();
    const ui = harness((request) => request.options.method === 'POST'
        ? pendingMutation.promise
        : Promise.resolve(response(status())));
    await settle();
    const clear = ui.button('clear');
    ui.click(clear);
    const countBeforeSubmit = ui.requests.length;
    const form = ui.nodes.get('groups').children[0].children.find((child) => child.tagName === 'FORM');

    assert.equal(ui.submit(form), true, 'Owned search forms must always prevent native navigation.');
    assert.equal(ui.requests.length, countBeforeSubmit, 'A search must not start while a mutation is pending.');

    pendingMutation.resolve(response(status()));
    await settle();
}

async function groupLabelsDescribeMediaItemsAndBoundPrefilledSearch() {
    const longTitle = 'a'.repeat(159) + '😀' + 'tail';
    const ui = harness(() => Promise.resolve(response(status({
        source: { blocked: false, error: 'capacity', legacyConfirmed: true, configured: true, otherSources: 0 },
        groups: [{ title: longTitle, library: 'TV Shows', kind: 'Episode', count: 150, ids: ['ep-1', 'ep-2'], fingerprint: 'fp' }],
    }))));
    await settle();
    const group = ui.nodes.get('groups').children[0];
    const input = group.querySelector('input[name="search"]');
    assert.equal(input.value.length, 159, 'The truncated title must not end with an unpaired high surrogate.');
    assert.match(group.textContent, /TV show · 150 episodes/);
    assert.match(group.textContent, /first 2 of 150 episodes/);
    assert.match(ui.nodes.get('notices').textContent, /storage limit prevented new saves\. existing saved posters are kept/i);
}

(async () => {
    await searchAndLinkSendScopedPayloadWithCsrf();
    await staleSearchRepliesCannotReplaceTheCurrentCandidates();
    await actionErrorsAreShownAndControlsRecover();
    await submittingSearchDuringMutationPreventsNavigationAndDoesNotFetch();
    await groupLabelsDescribeMediaItemsAndBoundPrefilledSearch();
    process.stdout.write('Artwork frontend behavior tests passed.\n');
})().catch((error) => {
    process.stderr.write(error.stack + '\n');
    process.exitCode = 1;
});
