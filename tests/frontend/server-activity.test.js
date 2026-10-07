'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function node(tag = 'div', text = '') {
    return {
        tag, textContent: text, value: '', hidden: false, disabled: false, children: [], attrs: {}, listeners: {},
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        setAttribute(name, value) { this.attrs[name] = value; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
        get options() { return this.children; },
    };
}

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/server-activity.js'), 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));
const NOW = 1791374430000;
const ready = (data) => ({ state: 'ready', stale: false, data, message: '' });
const overview = { timezone: 'UTC', server: ready({ name: 'Studio', version: '10.11.0', url: 'http://jellyfin.test/web/' }), tasks: ready({ running: [], recent: [] }) };
const events = (overrides = {}) => ready({ anchor: NOW / 1000, page: 1, pages: 3, items: [{ timestamp: NOW / 1000 - 30, name: '<img src=x onerror=alert(1)>', type: 'TaskCompleted', severity: 'Information', has_user: false }], types: ['TaskCompleted', 'PluginUpdated'], total: 70, timezone: 'UTC', ...overrides });

function harness() {
    const nodes = {};
    for (const name of ['filters', 'refresh', 'type', 'server-name', 'server-meta', 'open-jellyfin', 'server-state', 'running', 'recent-tasks', 'events', 'pagination', 'coverage', 'tasks-message', 'events-message', 'task-count', 'event-count', 'event-summary', 'server', 'server-message', 'task-results', 'page-label', 'prev', 'next', 'updated', 'range', 'custom-dates']) nodes[name] = node();
    for (const name of ['server', 'running', 'events']) nodes[name].attrs['aria-busy'] = 'true';
    const controls = { range: nodes.range, type: nodes.type, severity: node(), actor: node(), start: node(), end: node() };
    nodes.filters.elements = Object.assign(Object.values(controls), controls);
    nodes.type.children = [Object.assign(node('option', 'All types'), { value: '' })];
    const requests = [];
    const timers = [];
    let now = NOW;
    const document = { hidden: false, listeners: {}, querySelector(selector) { return selector === '[data-server-activity-root]' ? node() : nodes[selector.slice(15, -1)]; }, createElement: node, createElementNS: (_, tag) => node(tag), createTextNode: (text) => node('#text', text), addEventListener(name, callback) { this.listeners[name] = callback; } };
    const window = { location: { href: 'http://dashboard.test/server-activity', search: '' }, listeners: {}, setTimeout: () => 1, clearTimeout() {}, setInterval: (callback) => timers.push(callback), addEventListener(name, callback) { this.listeners[name] = callback; }, history: { pushState() {}, replaceState() {} } };
    class TestDate extends Date { static now() { return now; } }
    const context = { document, window, Date: TestDate, Intl, URL, URLSearchParams, AbortController, Error, Number, JSON, Option: function (text, value) { return Object.assign(node('option', text), { value }); }, FormData: function () { return Object.entries(controls).filter(([, control]) => !control.disabled).map(([name, control]) => [name, control.value]); }, fetch(url, options) { return new Promise((resolve, reject) => { const request = { url, options, resolve: (payload, status = 200) => resolve({ status, ok: status === 200, json: async () => payload }) }; requests.push(request); options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' }))); }); } };
    vm.runInNewContext(source, context);
    return { nodes, requests, document, window, tick(ms) { now += ms; timers.forEach((callback) => callback()); } };
}

(async () => {
    const h = harness();
    assert.equal(h.requests.length, 2);
    h.tick(5000);
    assert.equal(h.requests.length, 2, 'Polling must allow a slow initial request to finish.');
    h.requests[0].resolve(overview); h.requests[1].resolve(events());
    await flush();
    assert.equal(h.nodes['event-count'].textContent, '70 events');
    assert.equal(h.nodes.events.children[1].children[2].children[0].textContent, '<img src=x onerror=alert(1)>', 'Remote text is inserted literally.');

    h.nodes.type.value = 'PluginUpdated';
    h.nodes.refresh.listeners.click();
    h.requests[2].resolve(overview); h.requests[3].resolve(events({ types: ['TaskCompleted', 'NewType'] }));
    await flush();
    assert.equal(h.nodes.type.value, 'PluginUpdated', 'Refresh preserves the draft selection.');
    assert.ok(h.nodes.type.options.some((option) => option.value === 'PluginUpdated'));

    h.nodes.next.listeners.click();
    const interrupted = h.requests.at(-1);
    assert.match(interrupted.url, /page=2/);
    h.document.hidden = true; h.document.listeners.visibilitychange();
    await flush();
    assert.equal(h.nodes['event-count'].textContent, '70 events', 'Visibility cancellation preserves the current rows.');
    h.document.hidden = false; h.document.listeners.visibilitychange();
    const resumed = h.requests.at(-1);
    assert.match(resumed.url, /page=2/);
    assert.equal(new URL(resumed.url, 'http://dashboard.test').searchParams.get('anchor'), String(NOW / 1000));
    h.requests.at(-2).resolve(overview); resumed.resolve(events({ page: 2 }));
    await flush();
    assert.equal(h.nodes['page-label'].textContent, 'Page 2 of 3');

    h.nodes.refresh.listeners.click();
    const old = h.requests.at(-1);
    h.nodes.refresh.listeners.click();
    old.resolve(events({ total: 999 }));
    h.requests.at(-2).resolve(overview); h.requests.at(-1).resolve(events({ total: 12 }));
    await flush();
    assert.equal(h.nodes['event-count'].textContent, '12 events', 'An older request cannot replace newer results.');

    h.nodes.refresh.listeners.click();
    h.requests.at(-1).resolve({}, 403);
    await flush();
    assert.equal(h.nodes.refresh.disabled, true);
    assert.equal(h.nodes['updated'].textContent, 'Access denied');
    for (const name of ['server', 'running', 'events']) assert.equal(h.nodes[name].attrs['aria-busy'], 'false');
    assert.equal(h.nodes['open-jellyfin'].hidden, true);
    assert.equal(h.nodes['event-count'].textContent, '');
    const count = h.requests.length; h.tick(60000);
    assert.equal(h.requests.length, count, 'Access denial stops polling.');
    const expired = harness();
    expired.requests[0].resolve({}, 401);
    await flush();
    assert.equal(expired.nodes.updated.textContent, 'Sign in required');
    assert.equal(expired.nodes.events.children[1].href, '/login');
    for (const name of ['server', 'running', 'events']) assert.equal(expired.nodes[name].attrs['aria-busy'], 'false');
    console.log('Server Activity frontend tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
