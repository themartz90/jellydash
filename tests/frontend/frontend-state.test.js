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
        '{"phase":"importing","processed":1,"total":1}\n',
        '{"phase":"done","inserted":1,"skipped":0}\n',
    ]));
    assert.equal(complete.phase, 'done');

    await assert.rejects(
        hooks.readImportNdjson(stream(['{"phase":"importing","processed":1,"total":2}\n'])),
        /stopped before it finished/,
    );
    await assert.rejects(
        hooks.readImportNdjson(stream(['{"phase":"importing"'])),
        /JSON|property value/,
    );
    await assert.rejects(hooks.readImportNdjson(stream([])), /stopped before it finished/);
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

    response = { ok: false, status: 401 };
    await hooks.refreshNowPlaying().catch(hooks.renderNowPlayingError);
    assert.equal(label.children[0].href, '/login');
    assert.equal(label.children[0].textContent, 'Sign in again');

    response = { ok: true, status: 200, json: async () => ({ streams: [], stats: { active_streams: 0, active_users: 0 } }) };
    await hooks.refreshNowPlaying();
    assert.equal(root.classList.contains('is-stale'), false);
    assert.equal(dot.classList.contains('is-stale'), false);
    assert.equal(label.textContent, 'No active sessions');
    assert.equal(nav.textContent, '0');
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

(async () => {
    await testHistoryStreams();
    await testNowPlayingTransitions();
    await testNavCountTransitions();
    console.log('Frontend state tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
