'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '..', '..', 'public', 'assets', 'js', 'push.js'), 'utf8');

function classList() {
    const values = new Set();
    return {
        toggle(name, force) { force ? values.add(name) : values.delete(name); },
        contains(name) { return values.has(name); },
    };
}

function toggleElement() {
    const label = { textContent: '' };
    const listeners = {};
    return {
        hidden: true,
        disabled: false,
        attributes: {},
        classList: classList(),
        querySelector(selector) { return selector === '[data-push-state]' ? label : null; },
        addEventListener(name, callback) { listeners[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        click() { return listeners.click.call(this); },
        label,
    };
}

function response(ok, status, error) {
    return {
        ok,
        status,
        json: async () => error ? { error } : { ok: true },
    };
}

function harness(options = {}) {
    const toggle = toggleElement();
    const requests = [];
    const timers = new Map();
    let nextTimer = 1;
    let permissionRequests = 0;
    const subscription = options.subscription === undefined ? {
        endpoint: 'https://updates.push.services.mozilla.com/wpush/v2/existing',
        keys: { p256dh: 'key', auth: 'secret' },
        unsubscribe: async () => true,
    } : options.subscription;
    const registration = {
        pushManager: {
            getSubscription: async () => subscription,
            subscribe: async () => options.createdSubscription,
        },
    };
    const notification = {
        permission: options.permission || 'granted',
        requestPermission: async () => {
            permissionRequests += 1;
            return notification.permission;
        },
    };
    const window = {
        PushManager: function () {},
        Notification: notification,
        atob(value) { return Buffer.from(value, 'base64').toString('binary'); },
        setTimeout(callback) {
            const id = nextTimer++;
            timers.set(id, callback);
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
    };
    const document = {
        querySelector(selector) {
            if (selector === 'meta[name="vapid-public-key"]') {
                return { content: 'AQ' };
            }
            if (selector === 'meta[name="csrf-token"]') {
                return { content: 'csrf' };
            }
            return null;
        },
        querySelectorAll(selector) { return selector === '[data-push-toggle]' ? [toggle] : []; },
    };
    const fetch = async (url, init) => {
        requests.push({ url, init });
        return options.fetch ? options.fetch(url, init, requests.length) : response(true, 200);
    };
    vm.runInNewContext(script, {
        window,
        document,
        navigator: { serviceWorker: { ready: Promise.resolve(registration) } },
        Notification: notification,
        PushManager: window.PushManager,
        fetch,
        AbortController,
        Uint8Array,
        Error,
        JSON,
        Promise,
        console: { warn() {} },
    }, { filename: 'public/assets/js/push.js' });

    return {
        toggle,
        requests,
        get permissionRequests() { return permissionRequests; },
        runTimers() {
            const callbacks = Array.from(timers.values());
            timers.clear();
            callbacks.forEach((callback) => callback());
        },
    };
}

async function settle() {
    for (let index = 0; index < 6; ++index) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

async function testExistingSubscriptionIsConfirmedBeforeShowingOn() {
    const state = harness();
    assert.equal(state.toggle.label.textContent, 'Working…');
    assert.equal(state.toggle.disabled, true);

    state.toggle.click();
    await settle();

    assert.equal(state.permissionRequests, 0);
    assert.equal(state.toggle.label.textContent, 'On');
    assert.equal(state.toggle.attributes['aria-pressed'], 'true');
    assert.deepEqual(state.requests.map((request) => request.url), ['/api/push/subscribe.php']);

    state.toggle.click();
    await settle();
    assert.equal(state.toggle.label.textContent, 'Off');
    assert.deepEqual(state.requests.map((request) => request.url), [
        '/api/push/subscribe.php',
        '/api/push/unsubscribe.php',
    ]);
}

async function testRejectedReconciliationStaysRetryable() {
    let subscribeAttempts = 0;
    const state = harness({
        fetch: async (url) => {
            if (url === '/api/push/subscribe.php' && ++subscribeAttempts === 1) {
                return response(false, 409, 'This account has reached its notification device limit.');
            }
            return response(true, 200);
        },
    });
    await settle();

    assert.equal(state.toggle.label.textContent, 'Could not update. Try again.');
    assert.equal(state.toggle.disabled, false);
    assert.equal(state.toggle.title, 'This account has reached its notification device limit.');
    assert.equal(state.requests.some((request) => request.url === '/api/push/test.php'), false);

    state.toggle.click();
    await settle();
    assert.equal(state.permissionRequests, 1);
    assert.equal(state.toggle.label.textContent, 'On');
    assert.deepEqual(state.requests.map((request) => request.url), [
        '/api/push/subscribe.php',
        '/api/push/subscribe.php',
        '/api/push/test.php',
    ]);
}

async function testMissingBrowserSubscriptionStartsOffAndCanBeEnabled() {
    const createdSubscription = {
        endpoint: 'https://updates.push.services.mozilla.com/wpush/v2/new',
        keys: { p256dh: 'new-key', auth: 'new-secret' },
        unsubscribe: async () => true,
    };
    const state = harness({ subscription: null, createdSubscription });
    await settle();
    assert.equal(state.toggle.label.textContent, 'Off');
    assert.equal(state.requests.length, 0);

    state.toggle.click();
    await settle();
    assert.equal(state.toggle.label.textContent, 'On');
    assert.deepEqual(state.requests.map((request) => request.url), [
        '/api/push/subscribe.php',
        '/api/push/test.php',
    ]);
}

async function testReconciliationNetworkWaitIsBounded() {
    const state = harness({ fetch: async () => new Promise(() => {}) });
    await settle();
    state.runTimers();
    await settle();

    assert.equal(state.toggle.label.textContent, 'Setup timed out. Try again.');
    assert.equal(state.toggle.disabled, false);
}

async function testReconciliationErrorBodyWaitIsBounded() {
    const state = harness({
        fetch: async () => ({ ok: false, status: 409, json: async () => new Promise(() => {}) }),
    });
    await settle();
    assert.equal(state.toggle.label.textContent, 'Working…');
    state.runTimers();
    await settle();

    assert.equal(state.toggle.label.textContent, 'Setup timed out. Try again.');
    assert.equal(state.toggle.disabled, false);
}

async function testLateReconciliationCompletionCannotOverrideRetryAndDisable() {
    let resolveLate;
    let subscribeAttempts = 0;
    const state = harness({
        fetch: async (url) => {
            if (url === '/api/push/subscribe.php' && ++subscribeAttempts === 1) {
                return new Promise((resolve) => { resolveLate = resolve; });
            }
            return response(true, 200);
        },
    });
    await settle();
    state.runTimers();
    await settle();
    assert.equal(state.toggle.label.textContent, 'Setup timed out. Try again.');

    state.toggle.click();
    await settle();
    assert.equal(state.toggle.label.textContent, 'On');
    state.toggle.click();
    await settle();
    assert.equal(state.toggle.label.textContent, 'Off');

    resolveLate(response(true, 200));
    await settle();
    assert.equal(state.toggle.label.textContent, 'Off');
}

(async () => {
    await testExistingSubscriptionIsConfirmedBeforeShowingOn();
    await testRejectedReconciliationStaysRetryable();
    await testMissingBrowserSubscriptionStartsOffAndCanBeEnabled();
    await testReconciliationNetworkWaitIsBounded();
    await testReconciliationErrorBodyWaitIsBounded();
    await testLateReconciliationCompletionCannotOverrideRetryAndDisable();
    process.stdout.write('Push state tests passed.\n');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
