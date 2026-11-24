'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { MessageChannel } = require('node:worker_threads');

const script = fs.readFileSync(path.resolve(__dirname, '..', '..', 'public', 'sw.js'), 'utf8');

function windowClient(url, currentUrl = url) {
    return {
        url,
        focused: 0,
        navigated: [],
        messages: [],
        async focus() { this.focused++; },
        async navigate(target) { this.navigated.push(target); },
        postMessage(message, ports) {
            if (message.type === 'jellydash:current-seerr-request') {
                if (currentUrl !== null) ports[0].postMessage(new URL(currentUrl, url).searchParams.get('request'));
                ports[0].close();
                return;
            }
            this.messages.push(JSON.parse(JSON.stringify(message)));
        },
    };
}

async function click(openClients, target) {
    const listeners = {};
    const opened = [];
    const scope = {
        URL, MessageChannel, setTimeout, clearTimeout,
        self: {
            location: { origin: 'https://jellydash.example.test' },
            addEventListener(name, listener) { listeners[name] = listener; },
            clients: {
                async matchAll() { return openClients; },
                async openWindow(url) {
                    opened.push(url);
                    // Installed apps may reuse their current window.
                    if (openClients.length) openClients[0].url = url;
                },
            },
        },
    };
    vm.runInNewContext(script, scope, { filename: 'public/sw.js' });
    let pending;
    let closed = false;
    listeners.notificationclick({
        notification: { data: { url: target }, close() { closed = true; } },
        waitUntil(promise) { pending = promise; },
    });
    await pending;
    assert.equal(closed, true);
    return opened;
}

(async () => {
    const settings = windowClient('https://jellydash.example.test/settings');
    const opened = await click([settings], '/now-playing');
    assert.equal(settings.focused, 1);
    assert.deepEqual(settings.navigated, []);
    assert.equal(settings.url, 'https://jellydash.example.test/settings');
    assert.deepEqual(opened, []);

    const destination = windowClient('https://jellydash.example.test/now-playing');
    const openedAgain = await click([settings, destination], '/now-playing');
    assert.equal(destination.focused, 1);
    assert.deepEqual(openedAgain, []);

    const external = await click([], 'https://unrelated.example.test/path');
    assert.deepEqual(external, ['https://jellydash.example.test/now-playing']);
    const newDestination = await click([], '/jellyseerr');
    assert.deepEqual(newDestination, ['https://jellydash.example.test/jellyseerr']);
    assert.deepEqual(settings.messages, []);

    const request = '/jellyseerr?request=92';
    const requestView = windowClient('https://jellydash.example.test' + request);
    assert.deepEqual(await click([settings, requestView], request), []);
    assert.equal(requestView.focused, 1);
    assert.deepEqual(requestView.messages, []);

    const filteredView = windowClient('https://jellydash.example.test/jellyseerr?filter=pending&request=92');
    assert.deepEqual(await click([settings, filteredView], request), []);
    assert.equal(filteredView.focused, 1);
    assert.deepEqual(filteredView.messages, []);
    const inPage = windowClient('https://jellydash.example.test/jellyseerr', request);
    const closed = windowClient('https://jellydash.example.test' + request, '/jellyseerr');
    assert.deepEqual(await click([closed, settings, inPage], request), []);
    assert.equal(inPage.focused, 1);
    assert.equal(closed.focused, 0);
    const slow = windowClient('https://jellydash.example.test' + request, null);
    assert.deepEqual(await click([slow], request), []);
    assert.equal(slow.focused, 1);
    assert.deepEqual(slow.messages, [{ type: 'jellydash:seerr-request', url: request }]);

    const settingsFocus = settings.focused;
    assert.deepEqual(await click([settings], request), []);
    assert.equal(settings.focused, settingsFocus + 1);
    assert.deepEqual(settings.navigated, []);
    assert.deepEqual(settings.messages, [{ type: 'jellydash:seerr-request', url: request }]);
    assert.deepEqual(await click([], request), ['https://jellydash.example.test' + request]);

    const elsewhere = windowClient('https://other.example.test/settings');
    assert.deepEqual(await click([elsewhere], request), ['https://jellydash.example.test' + request]);
    assert.equal(elsewhere.focused, 0);
    assert.deepEqual(elsewhere.messages, []);

    await click([settings], 'https://other.example.test' + request);
    await click([settings], '/jellyseerr?request=92&auth=logout');
    await click([settings], '/jellyseerr?request=9007199254740992');
    assert.equal(settings.messages.length, 1);
    process.stdout.write('Notification click tests passed.\n');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
