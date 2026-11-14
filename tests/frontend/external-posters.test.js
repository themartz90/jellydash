'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/external-posters.js'), 'utf8');

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
}

function group(overrides = {}) {
    return {
        key: 'group-1', fingerprint: 'fingerprint-1', title: 'Custom Show', kind: 'Series', library: 'TV Shows',
        count: 2, ids: ['episode-1', 'episode-2'], items: [{ id: 'episode-1', title: 'Episode One' }, { id: 'episode-2', title: 'Episode Two' }],
        snoozedUntil: 0, reason: 'The title needs a manager match.',
        ...overrides,
    };
}

function status(overrides = {}) {
    return {
        enabled: true,
        configRevision: 4,
        source: { blocked: false, confirmed: true, error: null },
        noticeCount: 1,
        review: [group()],
        jobs: [],
        assets: [],
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
    let panel;
    function dataAttribute(selector) {
        const match = selector.match(/^\[data-external-([a-z-]+)(?:="([^"]*)")?\]$/);
        if (!match) return null;
        const suffix = match[1].replace(/-([a-z])/g, (all, letter) => letter.toUpperCase());
        const key = 'external' + suffix.charAt(0).toUpperCase() + suffix.slice(1);
        return { key, value: match[2] };
    }
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
            this.checked = false;
            this.open = false;
            this.value = '';
            this.ownText = '';
            this.className = '';
            this.src = '';
            this.onload = null;
            this.onerror = null;
        }
        set textContent(value) { this.replaceChildren(); this.ownText = String(value); }
        get textContent() { return this.ownText + this.children.map((child) => child.textContent).join(''); }
        get isConnected() { return this === panel || Boolean(this.parentNode && this.parentNode.isConnected); }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        replaceChildren(...children) {
            this.children.forEach((child) => { child.parentNode = null; });
            this.children = [];
            this.ownText = '';
            children.forEach((child) => this.appendChild(child));
        }
        remove() {
            if (!this.parentNode) return;
            this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
            this.parentNode = null;
        }
        contains(child) { return child === this || this.children.some((item) => item.contains(child)); }
        matches(selector) {
            const attr = dataAttribute(selector);
            if (attr) return this.dataset[attr.key] !== undefined && (attr.value === undefined || this.dataset[attr.key] === attr.value);
            return selector === 'button' && this.tagName === 'BUTTON';
        }
        closest(selector) {
            if (selector === 'form[data-external-search]' && this.tagName === 'FORM' && this.dataset.externalSearch !== undefined) return this;
            if (selector === 'details[data-external-group]' && this.tagName === 'DETAILS' && this.dataset.externalGroup !== undefined) return this;
            if (this.matches(selector)) return this;
            return this.parentNode ? this.parentNode.closest(selector) : null;
        }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        focus() { document.activeElement = this; }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        querySelectorAll(selector) {
            const all = this.children.flatMap((child) => [child, ...child.querySelectorAll('*')]);
            if (selector === '*') return all;
            if (selector === 'button') return all.filter((child) => child.tagName === 'BUTTON');
            if (selector === 'input') return all.filter((child) => child.tagName === 'INPUT');
            if (selector === 'details') return all.filter((child) => child.tagName === 'DETAILS');
            if (selector === 'img') return all.filter((child) => child.tagName === 'IMG');
            const attr = dataAttribute(selector);
            if (attr) return all.filter((child) => child.matches(selector));
            return [];
        }
    }

    panel = new Element('section');
    const csrf = { content: 'csrf-external-fixture' };
    const fixed = new Map();
    const tags = {
        enabled: 'input', status: 'p', error: 'p', notices: 'div', 'review-wrap': 'details', 'review-count': 'span', review: 'div',
        'jobs-wrap': 'details', 'jobs-count': 'span', jobs: 'div', 'assets-wrap': 'details', 'assets-count': 'span', assets: 'div', pager: 'nav', page: 'span', prev: 'button', next: 'button',
    };
    Object.entries(tags).forEach(([name, tag]) => {
        const element = new Element(tag);
        element.dataset['external' + name.split('-').map((part) => part[0].toUpperCase() + part.slice(1)).join('')] = 'true';
        fixed.set(name, panel.appendChild(element));
    });
    panel.querySelector = (selector) => {
        const attr = dataAttribute(selector);
        if (attr) return fixed.get(attr.key.replace(/^external/, '').replace(/^[A-Z]/, (letter) => letter.toLowerCase()).replace(/[A-Z]/g, (letter) => '-' + letter.toLowerCase()))
            || panel.querySelectorAll(selector)[0] || null;
        return null;
    };
    const badge = new Element('span');
    const settingsLink = new Element('a');
    document.querySelector = (selector) => selector === '[data-external-review-badge]' ? badge : (selector === '[data-external-review-nav]' ? settingsLink
        : (selector === '[data-external-posters]' ? panel : (selector === 'meta[name="csrf-token"]' ? csrf : null)));
    document.createElement = (tag) => new Element(tag);
    document.createTextNode = (text) => new Element('#text').appendChildText(String(text));
    document.addEventListener = (name, callback) => { document.listeners[name] = callback; };
    const timers = new Map();
    let timerId = 0;
    const requests = [];
    const window = {
        location: { href: 'http://localhost/settings', origin: 'http://localhost' },
        confirmMessages: [],
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        confirm(message) { this.confirmMessages.push(message); return true; },
        addEventListener(name, callback) { this.listeners = this.listeners || {}; this.listeners[name] = callback; },
    };
    Element.prototype.appendChildText = function (text) { this.ownText = text; return this; };
    vm.runInNewContext(script, {
        document, window, Promise, AbortController, Error, Number, JSON, URL, Date, encodeURIComponent,
        fetch(url, options) {
            const record = { url, options, body: options.body ? JSON.parse(options.body) : null };
            requests.push(record);
            return onFetch(record, requests.length);
        },
    }, { filename: 'public/assets/js/external-posters.js' });
    return {
        panel, document, requests, timers, fixed, window, badge, settingsLink,
        all(selector) { return panel.querySelectorAll(selector); },
        click(control) { assert.ok(control, 'Expected control'); assert.equal(control.disabled, false); control.focus(); panel.listeners.click({ target: control }); },
        change(control) { panel.listeners.change({ target: control }); },
        input(control) { panel.listeners.input({ target: control }); },
        submit(form) {
            const event = { target: form, prevented: false, preventDefault() { this.prevented = true; } };
            panel.listeners.submit(event);
            return event.prevented;
        },
        poll() {
            const timer = [...timers.entries()].find(([, value]) => value.delay === 30000);
            if (!timer) throw new Error('Expected the local status poll timer.');
            timers.delete(timer[0]);
            timer[1].callback();
        },
    };
}

async function settle() {
    for (let index = 0; index < 8; index += 1) await new Promise((resolve) => setImmediate(resolve));
}

async function searchAndSelectAreExplicitScopedAndStartPending() {
    let searchCount = 0;
    let selected = false;
    const ui = harness((request) => {
        if (!request.options.method && selected) {
            return Promise.resolve(response(status({ review: [], jobs: [{ ids: ['episode-1'], fingerprint: 'fingerprint-1', title: 'Custom Show', selectedTitle: 'Current Show', state: 'saved', error: null }] })));
        }
        if (request.options.method === 'POST' && request.body.action === 'search') {
            searchCount += 1;
            return Promise.resolve(response({ fingerprint: 'fingerprint-1', candidates: [{ id: 'show-1', kind: 'Series', title: 'Current Show', year: 2024, token: 'candidate-token', previewUrl: '/api/external-posters.php?preview=one' }] }));
        }
        if (request.options.method === 'POST' && request.body.action === 'select') {
            selected = true;
            return Promise.resolve(response(status({ review: [], jobs: [{ ids: ['episode-1'], fingerprint: 'fingerprint-1', title: 'Custom Show', selectedTitle: 'Current Show', state: 'pending', error: null }] })));
        }
        return Promise.resolve(response(status()));
    });
    await settle();
    assert.equal(ui.requests.filter((request) => request.options.method === 'POST').length, 0, 'Status reads must not start a search.');
    let checkboxes = ui.all('input').filter((input) => input.type === 'checkbox' && input.dataset.externalItemGroup);
    assert.equal(checkboxes.length, 2);
    assert.ok(checkboxes.every((input) => !input.checked), 'Multiple history items must start unselected.');
    const searchButton = ui.all('button').find((button) => button.dataset.externalAction === 'search');
    ui.click(searchButton);
    await settle();
    assert.equal(searchCount, 0, 'Searching requires an explicit History item selection.');

    checkboxes = ui.all('input').filter((input) => input.type === 'checkbox' && input.dataset.externalItemGroup);
    checkboxes[0].checked = true;
    ui.change(checkboxes[0]);
    const query = ui.all('input').find((input) => input.dataset.externalQuery);
    query.value = 'Current Show';
    ui.input(query);
    ui.click(ui.all('button').find((button) => button.dataset.externalAction === 'search'));
    await settle();
    const search = ui.requests.find((request) => request.body && request.body.action === 'search');
    assert.ok(search, 'Explicit search should POST once selection is present: ' + JSON.stringify(ui.requests.map((request) => request.body)));
    assert.equal(search.options.headers['X-CSRF-Token'], 'csrf-external-fixture');
    assert.deepEqual(search.body, { action: 'search', ids: ['episode-1'], fingerprint: 'fingerprint-1', query: 'Current Show', kind: 'Series', page: 1 });
    const candidateText = ui.panel.textContent;
    assert.ok(candidateText.includes('Current Show'));
    const radio = ui.all('input').find((input) => input.type === 'radio');
    assert.ok(radio);
    assert.equal(radio.checked, false, 'Candidates must not be preselected.');
    const use = ui.all('button').find((button) => button.dataset.externalAction === 'select');
    assert.equal(use.disabled, true);
    radio.checked = true;
    ui.change(radio);
    assert.equal(use.disabled, false);
    ui.click(use);
    await settle();
    const selection = ui.requests.at(-1);
    assert.deepEqual(selection.body, { action: 'select', candidateToken: 'candidate-token', ids: ['episode-1'], fingerprint: 'fingerprint-1', page: 1 });
    assert.ok(ui.panel.textContent.includes('Saving poster'));
    assert.ok(!ui.panel.textContent.includes('Poster saved.'));
    ui.document.activeElement = null;
    ui.poll();
    await settle();
    assert.ok(ui.panel.textContent.includes('Poster saved.'));
}

async function staleSearchRepliesDoNotReplaceNewQueryResults() {
    const oldSearch = deferred();
    let searchCount = 0;
    const ui = harness((request) => {
        if (request.options.method === 'POST' && request.body.action === 'search') {
            searchCount += 1;
            return searchCount === 1 ? oldSearch.promise : Promise.resolve(response({ fingerprint: 'fingerprint-1', candidates: [{ token: 'new-token', title: 'New results', kind: 'Series' }] }));
        }
        return Promise.resolve(response(status()));
    });
    await settle();
    const box = ui.all('input').find((input) => input.type === 'checkbox');
    box.checked = true;
    ui.change(box);
    const query = ui.all('input').find((input) => input.dataset.externalQuery);
    query.value = 'older query';
    ui.input(query);
    ui.click(ui.all('button').find((button) => button.dataset.externalAction === 'search'));
    const firstSignal = ui.requests.at(-1).options.signal;
    query.value = 'newer query';
    ui.input(query);
    assert.equal(firstSignal.aborted, true);
    ui.click(ui.all('button').find((button) => button.dataset.externalAction === 'search'));
    await settle();
    oldSearch.resolve(response({ fingerprint: 'fingerprint-1', candidates: [{ token: 'old-token', title: 'Old results', kind: 'Series' }] }));
    await settle();
    assert.ok(ui.panel.textContent.includes('New results'));
    assert.ok(!ui.panel.textContent.includes('Old results'));
}

async function aSingleKnownHistoryItemStartsSelected() {
    const ui = harness(() => Promise.resolve(response(status({
        review: [group({ key: 'single', fingerprint: 'single-fingerprint', count: 1, ids: ['movie-1'], items: [{ id: 'movie-1', title: 'One Movie' }] })],
    }))));
    await settle();
    const checkbox = ui.all('input').find((input) => input.type === 'checkbox');
    assert.ok(checkbox);
    assert.equal(checkbox.checked, true);
}

async function snoozeKeepsTheReviewGroupVisible() {
    let postBody;
    const ui = harness((request) => {
        if (request.options.method === 'POST') {
            postBody = request.body;
            return Promise.resolve(response(status({ noticeCount: 0, review: [group({ snoozedUntil: 1_900_000_000 })] })));
        }
        return Promise.resolve(response(status()));
    });
    await settle();
    assert.equal(ui.badge.hidden, false);
    assert.equal(ui.settingsLink.href, '/settings#external-poster-review');
    ui.click(ui.all('button').find((button) => button.dataset.externalAction === 'snooze'));
    await settle();
    assert.deepEqual(postBody, { action: 'snooze', ids: ['episode-1', 'episode-2'], fingerprint: 'fingerprint-1', page: 1 });
    assert.ok(ui.panel.textContent.includes('Custom Show'));
    assert.ok(ui.panel.textContent.includes('Skipped for 7 days'));
    assert.equal(ui.all('details').filter((details) => details.dataset.externalGroup).length, 1);
    assert.equal(ui.badge.hidden, true, 'Shared snooze must clear the current Settings badge immediately.');
    assert.equal(ui.settingsLink.href, '/settings');
}

async function rejectedConfigurationRestoresThePreviousToggle() {
    const ui = harness((request) => request.options.method === 'POST'
        ? Promise.resolve(response({ error: 'The setting could not be saved.' }, 409))
        : Promise.resolve(response(status())));
    await settle();
    const enabled = ui.fixed.get('enabled');
    enabled.checked = false;
    ui.change(enabled);
    await settle();
    assert.equal(ui.requests.at(-1).body.action, 'configure');
    assert.deepEqual(ui.requests.at(-1).body, { action: 'configure', enabled: false, configRevision: 4 });
    assert.equal(enabled.checked, true, 'Rejected changes must restore the saved setting.');
    assert.ok(ui.fixed.get('error').textContent.includes('could not be saved'));
}

async function sharedAssetClearConfirmsScopeAndUsesTheAssetFingerprint() {
    let postBody;
    const asset = { key: 'asset-key', title: 'Current Show', kind: 'Series', providerId: 'tmdb-55', bodySize: 4096, refCount: 3, fingerprint: 'asset-fingerprint', imageUrl: '/api/image.php?item=asset-key&type=External' };
    const ui = harness((request) => {
        if (request.options.method === 'POST') {
            postBody = request.body;
            return Promise.resolve(response(status({ review: [], assets: [] })));
        }
        return Promise.resolve(response(status({ assets: [asset] })));
    });
    await settle();
    assert.ok(ui.panel.textContent.includes('Clear image everywhere'));
    const preview = ui.all('img').find((image) => image.className === 'external-posters-asset-preview');
    assert.ok(preview);
    assert.equal(preview.src, 'http://localhost/api/image.php?item=asset-key&type=External');
    ui.click(ui.all('button').find((button) => button.dataset.externalAction === 'clear-asset'));
    await settle();
    assert.equal(ui.window.confirmMessages.length, 1);
    assert.ok(ui.window.confirmMessages[0].includes('3 linked titles'));
    assert.ok(ui.window.confirmMessages[0].includes('pause for every linked title'));
    assert.deepEqual(postBody, { action: 'clearAsset', assetKey: 'asset-key', fingerprint: 'asset-fingerprint' });
}

async function candidatePreviewQueueIsBoundedAndImageErrorsReleaseSlots() {
    const candidates = Array.from({ length: 4 }, (_, index) => ({
        token: 'token-' + index, title: 'Candidate ' + index, kind: 'Series', previewUrl: '/preview/' + index,
    }));
    const ui = harness((request) => request.body && request.body.action === 'search'
        ? Promise.resolve(response({ fingerprint: 'fingerprint-1', candidates }))
        : Promise.resolve(response(status({ review: [group({ count: 1, ids: ['episode-1'], items: [{ id: 'episode-1', title: 'Episode One' }] })] }))));
    await settle();
    ui.click(ui.all('button').find((button) => button.dataset.externalAction === 'search'));
    await settle();
    const previews = ui.all('img').filter((image) => image.dataset.externalPreview !== undefined);
    assert.equal(previews.length, 4);
    assert.equal(previews.filter((image) => image.src !== '').length, 2, 'At most two candidate previews may load at once.');
    const first = previews.find((image) => image.dataset.externalPreview.endsWith('-0'));
    const second = previews.find((image) => image.dataset.externalPreview.endsWith('-1'));
    const third = previews.find((image) => image.dataset.externalPreview.endsWith('-2'));
    const fourth = previews.find((image) => image.dataset.externalPreview.endsWith('-3'));
    assert.equal(typeof first.onerror, 'function');
    assert.equal(second.isConnected, true);
    first.onerror();
    assert.equal(second.isConnected, true, 'The other preview must remain attached.');
    assert.equal(third.src, 'http://localhost/preview/2', 'A failed preview must release its slot for the next candidate.');
    assert.equal(previews.filter((image) => image.src !== '').length, 2);
    assert.equal(typeof second.onerror, 'function', 'The other active preview must remain tracked.');
    second.onerror();
    assert.equal(fourth.src, 'http://localhost/preview/3', 'The preview queue must continue after another failure.');
    assert.equal(previews.filter((image) => image.src !== '').length, 2);
}

async function automaticSuggestionsAreLocalScopedAndUnselected() {
    let selected = false;
    const ui = harness((request) => {
        if (request.body && request.body.action === 'suggestions') {
            return Promise.resolve(response({ fingerprint: 'fingerprint-1', origin: 'discovery', queryTitle: '進撃の巨人', candidates: [
                { id: '1429', kind: 'Series', title: '進撃の巨人', year: 2013, token: 'suggestion-token', previewUrl: '/preview/native', recordedYears: [2013], yearCoverage: 1, selectedCount: 1 },
            ] }));
        }
        if (request.body && request.body.action === 'select') {
            assert.deepEqual(request.body.ids, ['episode-1']);
            selected = true;
            return Promise.resolve(response(status({ review: [], noticeCount: 0, jobs: [{ ids: ['episode-1'], title: '進撃の巨人', selectedTitle: '進撃の巨人', state: 'pending' }] })));
        }
        return Promise.resolve(response(status({ review: [group({ discovery: { status: 'ready', count: 1 } })] })));
    });
    await settle();
    assert.equal(ui.requests.length, 1, 'Reading discovered counts must not start a search.');
    ui.click(ui.all('button').find((item) => item.dataset.externalAction === 'suggestions'));
    await settle();
    assert.equal(ui.requests.length, 1, 'A multi-item group must stay unselected until the manager chooses rows.');
    const item = ui.all('input').find((element) => element.dataset.externalItemGroup && element.value === 'episode-1');
    item.checked = true;
    ui.change(item);
    ui.click(ui.all('button').find((element) => element.dataset.externalAction === 'suggestions'));
    await settle();
    assert.deepEqual(ui.requests[1].body, { action: 'suggestions', ids: ['episode-1'], fingerprint: 'fingerprint-1', page: 1 });
    const radio = ui.all('input').find((element) => element.dataset.externalCandidateGroup);
    assert.equal(radio.checked, false);
    assert.ok(ui.panel.textContent.includes('Suggestions for saved title: 進撃の巨人'));
    radio.checked = true;
    radio.value = 'suggestion-token';
    ui.change(radio);
    ui.click(ui.all('button').find((element) => element.dataset.externalAction === 'select'));
    await settle();
    assert.equal(selected, true);
    assert.ok(ui.panel.textContent.includes('Saving poster'));
}

async function nativeQueriesPassThroughAndManualEntriesDoNotPromiseAutomaticWork() {
    const ui = harness((request) => request.body
        ? Promise.resolve(response({ fingerprint: 'fingerprint-1', candidates: [] }))
        : Promise.resolve(response(status({ review: [group({ ids: ['episode-1'], count: 1, discovery: { status: 'manual', count: 0 } })] }))));
    await settle();
    assert.ok(ui.panel.textContent.includes('This entry needs manual review.'));
    assert.ok(!ui.panel.textContent.includes('waiting for a background check'));
    const query = ui.all('input').find((element) => element.dataset.externalQuery);
    query.value = '進撃の巨人';
    ui.input(query);
    ui.click(ui.all('button').find((element) => element.dataset.externalAction === 'search'));
    await settle();
    assert.equal(ui.requests[1].body.query, '進撃の巨人');
}

(async () => {
    await searchAndSelectAreExplicitScopedAndStartPending();
    await staleSearchRepliesDoNotReplaceNewQueryResults();
    await aSingleKnownHistoryItemStartsSelected();
    await snoozeKeepsTheReviewGroupVisible();
    await rejectedConfigurationRestoresThePreviousToggle();
    await sharedAssetClearConfirmsScopeAndUsesTheAssetFingerprint();
    await candidatePreviewQueueIsBoundedAndImageErrorsReleaseSlots();
    await automaticSuggestionsAreLocalScopedAndUnselected();
    await nativeQueriesPassThroughAndManualEntriesDoNotPromiseAutomaticWork();
    process.stdout.write('External poster frontend behavior tests passed.\n');
})().catch((error) => {
    process.stderr.write(error.stack + '\n');
    process.exitCode = 1;
});
