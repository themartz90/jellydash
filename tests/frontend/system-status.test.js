'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function node() {
    return {
        textContent: '', className: '', disabled: false, hidden: false, value: '', children: [], firstChild: null,
        appendChild(child) { this.children.push(child); this.firstChild = this.children[0] || null; },
        removeChild(child) { this.children = this.children.filter((item) => item !== child); this.firstChild = this.children[0] || null; },
        addEventListener(type, callback) { this['on' + type] = callback; },
        setAttribute(name, value) { this[name] = value; },
        querySelector(selector) { return this.nodes[selector]; }, focus() {}, select() {},
    };
}

const summary = node();
const components = node();
const copy = node(); copy.disabled = true;
const feedback = node();
const manual = node(); manual.hidden = true;
const manualText = node();
const root = node();
let anchorScrolls = 0;
root.scrollIntoView = () => { anchorScrolls++; };
const nav = node();
const navLabel = node();
const navDot = node();
root.nodes = {
    '[data-system-status-summary]': summary,
    '[data-system-status-components]': components,
    '[data-system-status-copy]': copy,
    '[data-system-status-feedback]': feedback,
    '[data-system-status-manual]': manual,
    '[data-system-status-text]': manualText,
};

const hooks = {};
let response = { ok: true, status: 200, json: async () => ({
    state: 'healthy', components: [{ label: 'History collector', state: 'healthy', message: 'Running.', last_success_at: Math.floor(Date.now() / 1000) }],
    diagnostics: { version: 'test', database: 'sqlite' }, secret: 'must not copy',
}) };
let copied = '';
const document = {
    hidden: true,
    querySelector: (selector) => ({
        '[data-system-status]': root,
        '[data-system-status-nav]': nav,
        '[data-system-status-nav-label]': navLabel,
        '[data-system-status-nav-dot]': navDot,
    }[selector] || null),
    createElement: () => node(),
    addEventListener() {},
};
const window = { location: { hash: '#system-status' }, JellydashFrontendTestHooks: hooks, setTimeout: () => 1, clearTimeout() {}, setInterval() {} };
const context = {
    document, window, navigator: { clipboard: { writeText: async (text) => { copied = text; } } },
    fetch: async () => response, AbortController, Error, Date, Number, JSON,
};
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/system-status.js'), 'utf8'), context);

(async () => {
    hooks.systemStatus.render(await response.json());
    assert.equal(summary.textContent, 'Background checks are healthy');
    assert.equal(navLabel.textContent, 'Healthy');
    assert.equal(nav['aria-label'], 'System status, healthy');
    assert.equal(copy.disabled, false);
    assert.equal(anchorScrolls, 1, 'Settle the anchor after asynchronous rows expand the page.');
    hooks.systemStatus.render(await response.json());
    assert.equal(anchorScrolls, 1, 'Polling must not move the reader.');
    await hooks.systemStatus.copyDiagnostics();
    assert.deepEqual(JSON.parse(copied), { version: 'test', database: 'sqlite' });

    hooks.systemStatus.renderFailure({ status: 503 });
    assert.equal(summary.textContent, 'Status unavailable');
    assert.equal(navLabel.textContent, 'Status unknown');
    assert.equal(copy.disabled, true);

    hooks.systemStatus.renderFailure({ status: 401 });
    const message = components.children[0].children[1];
    assert.equal(message.children[0].textContent, 'Session expired');
    assert.equal(message.children[1].href, '/login');

    hooks.systemStatus.render({ state: 'healthy', components: [], diagnostics: { safe: true } });
    context.navigator.clipboard = null;
    await hooks.systemStatus.copyDiagnostics();
    assert.equal(manual.hidden, false);
    assert.equal(manualText.value, JSON.stringify({ safe: true }, null, 2));
    assert.match(feedback.textContent, /copy them manually/);

    hooks.systemStatus.render({ state: 'attention', components: [{ id: 'playback_notifications', label: 'Notifications', state: 'delayed', message: 'Retrying.', last_success_at: null, last_delivery_at: Math.floor(Date.now() / 1000), pending_retries: 2 }], diagnostics: { safe: 'updated' } });
    assert.equal(manual.hidden, false);
    assert.equal(manualText.value, JSON.stringify({ safe: true }, null, 2));
    assert.match(components.children[0].children[1].children[1].textContent, /No successful check yet/);
    assert.match(components.children[0].children[1].children[1].textContent, /Last delivery attempt just now/);
    assert.match(components.children[0].children[1].children[1].textContent, /2 pending retries/);

    hooks.systemStatus.render({ state: 'unknown', components: [{ id: 'request_notifications', label: 'Request notifications', state: 'disabled', message: 'Notifications are disabled.', last_success_at: null }], diagnostics: { safe: true } });
    assert.equal(components.children[0].children[1].children[1].textContent, 'Notifications are disabled.');

    document.hidden = false;
    response = { ok: false, status: 503 };
    await hooks.systemStatus.refresh();
    assert.equal(summary.textContent, 'Status unavailable');
    hooks.systemStatus.render({ state: 'healthy', components: [], diagnostics: { safe: true } });
    context.navigator.clipboard = null;
    await hooks.systemStatus.copyDiagnostics();
    hooks.systemStatus.renderFailure({ status: 401 });
    assert.equal(manual.hidden, true);
    assert.equal(manualText.value, '');
    console.log('System status frontend tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
