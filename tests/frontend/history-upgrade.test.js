'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '..', '..', 'public', 'assets', 'js', 'history-library-upgrade.js'), 'utf8');
const pending = { required: true, state: 'pending', total: 3, processed: 0, percent: 0, busy: false };
const running = { ...pending, state: 'running', processed: 1, percent: 33 };

function element() {
    const listeners = {};
    return {
        hidden: false, textContent: '', style: {}, attributes: {},
        addEventListener(name, callback) { listeners[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
        focus() {},
        click() { if (listeners.click) listeners.click(); },
        event(name) { if (listeners[name]) listeners[name]({ preventDefault() {} }); },
    };
}

function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}

function harness(onFetch) {
    const nodes = new Map();
    const child = (selector) => {
        if (!nodes.has(selector)) nodes.set(selector, element());
        return nodes.get(selector);
    };
    const dialog = Object.assign(element(), {
        open: false,
        querySelector: child,
        showModal() { this.open = true; },
        close() { this.open = false; },
    });
    const reopen = element();
    reopen.hidden = true;
    const timers = new Map();
    let nextTimer = 1;
    const requests = [];
    const window = {
        setTimeout(callback, delay) { const id = nextTimer++; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
    };
    vm.runInNewContext(script, {
        document: {
            querySelector(selector) {
                if (selector === '[data-history-library-upgrade]') return dialog;
                if (selector === '[data-history-library-upgrade-reopen]') return reopen;
                if (selector === 'meta[name="csrf-token"]') return { content: 'csrf' };
                return null;
            },
        },
        window, AbortController,
        fetch: (url, options) => {
            requests.push(options.method);
            return onFetch(options.method, requests.length, options.signal);
        },
        Promise,
        Number,
        Math,
        String,
        Error,
    }, { filename: 'public/assets/js/history-library-upgrade.js' });

    return {
        dialog, reopen, requests, ready: window.jellydashUpgradeReady, child,
        get timerCount() { return [...timers.values()].filter(({ delay }) => delay < 15000).length; },
        hide() { child('[data-history-library-upgrade-hide]').click(); },
        tick(timeout = false) {
            for (const [id, { callback, delay }] of [...timers.entries()]) {
                if ((delay === 15000) !== timeout) continue;
                timers.delete(id);
                callback();
            }
        },
    };
}

async function testHungRequestsAreBoundedAndRetryChecksStatusBeforeWriting() {
    const hang = (signal) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(signal.reason), { once: true });
    });
    const stalledStatus = harness((method, count, signal) => hang(signal));
    stalledStatus.tick(true);
    await stalledStatus.ready;
    assert.equal(stalledStatus.dialog.open, false);

    const ui = harness((method, count, signal) => {
        if (count === 1) return Promise.resolve(response(pending));
        if (method === 'POST') return hang(signal);
        return Promise.resolve(response({ ...running, required: false, state: 'complete' }));
    });
    await settle();
    ui.tick();
    assert.deepEqual(ui.requests, ['GET', 'POST']);
    ui.tick(true);
    await settle();
    assert.match(ui.child('[data-history-library-upgrade-summary]').textContent, /timed out/);
    ui.child('[data-history-library-upgrade-retry]').click();
    await settle();
    assert.deepEqual(ui.requests, ['GET', 'POST', 'GET']);
    assert.equal(ui.child('[data-history-library-upgrade-title]').textContent, 'History update complete');
    assert.equal(ui.timerCount, 0);
}

function response(payload) {
    return { ok: true, json: async () => payload };
}

async function settle() {
    for (let index = 0; index < 6; ++index) await new Promise((resolve) => setImmediate(resolve));
}

async function testHiddenDialogDoesNotContinuePostingAfterPendingResponse() {
    const firstPost = deferred();
    const secondPost = deferred();
    let postCount = 0;
    const ui = harness((method) => method === 'GET'
        ? Promise.resolve(response(pending))
        : (++postCount === 1 ? firstPost.promise : secondPost.promise));

    await settle();
    assert.equal(ui.dialog.open, true);
    ui.tick();
    await settle();
    assert.equal(postCount, 1);
    ui.hide();
    assert.equal(ui.dialog.open, false);
    firstPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 0);
    ui.tick();
    assert.equal(postCount, 1);

    ui.reopen.click();
    await settle();
    ui.tick();
    await settle();
    assert.equal(postCount, 2);
    secondPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 1);
    ui.hide();
    assert.equal(ui.timerCount, 0);
}

async function testOverlappingOldResponseCannotStartAnotherLoopAfterReopen() {
    const oldPost = deferred();
    const newPost = deferred();
    let postCount = 0;
    const ui = harness((method) => method === 'GET'
        ? Promise.resolve(response(pending))
        : (++postCount === 1 ? oldPost.promise : newPost.promise));
    await settle();
    ui.tick();
    await settle();
    ui.hide();
    ui.reopen.click();
    await settle();
    ui.tick();
    await settle();
    assert.equal(postCount, 2);

    oldPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 0);
    newPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 1);
    ui.hide();
}

(async () => {
    await testHiddenDialogDoesNotContinuePostingAfterPendingResponse();
    await testOverlappingOldResponseCannotStartAnotherLoopAfterReopen();
    await testHungRequestsAreBoundedAndRetryChecksStatusBeforeWriting();
    process.stdout.write('History upgrade state tests passed.\n');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
