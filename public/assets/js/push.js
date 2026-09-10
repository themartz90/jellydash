// Playback push notifications: wires every [data-push-toggle] control (the
// header bell + the sidebar "Notifications" row) to subscribe/unsubscribe the
// browser to Web Push. Kept external because the page CSP blocks inline scripts.
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="vapid-public-key"]');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-push-toggle]'));
    if (!meta || !meta.content || !csrfMeta || !csrfMeta.content || toggles.length === 0) {
        return; // Server hasn't configured VAPID, or nothing to wire up.
    }

    var VAPID_KEY = meta.content;
    var CSRF_TOKEN = csrfMeta.content;
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var busy = false;
    var NETWORK_TIMEOUT_MS = 8000;

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    var LABELS = {
        on: 'On',
        off: 'Off',
        working: 'Working…',
        blocked: 'Blocked in browser',
        unsupported: 'Notifications need browser support.',
        timeout: 'Setup timed out. Try again.',
        error: 'Could not update. Try again.'
    };

    function setState(state, detail) {
        toggles.forEach(function (el) {
            el.hidden = false;
            var isOn = state === 'on';
            el.classList.toggle('is-on', isOn);
            el.setAttribute('aria-pressed', isOn ? 'true' : 'false');
            el.disabled = state === 'working' || state === 'blocked' || state === 'unsupported';
            var label = el.querySelector('[data-push-state]');
            if (label) {
                label.textContent = LABELS[state] || '';
            }
            if (state === 'blocked' || state === 'unsupported') {
                el.title = state === 'blocked'
                    ? 'Notifications are blocked for this site. Allow them in your browser settings.'
                    : LABELS.unsupported;
            } else if (state === 'timeout' || state === 'error') {
                el.title = detail || LABELS[state];
            } else {
                el.removeAttribute('title');
            }
        });
    }

    function waitForServiceWorker() {
        return new Promise(function (resolve, reject) {
            var timer = window.setTimeout(function () {
                var error = new Error('Service worker setup timed out');
                error.name = 'TimeoutError';
                reject(error);
            }, NETWORK_TIMEOUT_MS);

            navigator.serviceWorker.ready.then(function (registration) {
                window.clearTimeout(timer);
                resolve(registration);
            }, function (error) {
                window.clearTimeout(timer);
                reject(error);
            });
        });
    }

    function postJson(url, body) {
        return new Promise(function (resolve, reject) {
            var settled = false;
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            var timer = window.setTimeout(function () {
                if (settled) {
                    return;
                }
                settled = true;
                if (controller) {
                    controller.abort();
                }
                var error = new Error('The request timed out');
                error.name = 'TimeoutError';
                reject(error);
            }, NETWORK_TIMEOUT_MS);
            var options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                credentials: 'same-origin',
                body: body ? JSON.stringify(body) : null
            };
            if (controller) {
                options.signal = controller.signal;
            }
            fetch(url, options).then(function (response) {
                if (response.ok) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    window.clearTimeout(timer);
                    resolve(response);
                    return;
                }
                var fallback = 'The request failed with HTTP ' + response.status + '.';
                response.json().then(function (responseBody) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    window.clearTimeout(timer);
                    var message = responseBody && typeof responseBody.error === 'string' && responseBody.error
                        ? responseBody.error
                        : fallback;
                    var error = new Error(message);
                    error.userMessage = message;
                    reject(error);
                }, function () {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    window.clearTimeout(timer);
                    reject(new Error(fallback));
                });
            }, function (error) {
                if (settled) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                reject(error);
            });
        });
    }

    function storeSubscription(subscription, sendConfirmation) {
        return postJson('/api/push/subscribe.php', subscription).then(function () {
            setState('on');
            if (sendConfirmation) {
                postJson('/api/push/test.php', { scope: 'current' }).catch(function () {});
            }
        });
    }

    function setFailedState(error, action) {
        console.warn('[jellydash] ' + action + ' notifications failed:', error && error.name, error && error.message);
        setState(error && error.name === 'TimeoutError' ? 'timeout' : 'error', error && error.userMessage);
    }

    function subscribe() {
        setState('working');
        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                setState(permission === 'denied' ? 'blocked' : 'off');
                return;
            }
            return waitForServiceWorker().then(function (reg) {
                return reg.pushManager.getSubscription().then(function (existing) {
                    return existing || reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(VAPID_KEY)
                    });
                });
            }).then(function (sub) {
                return storeSubscription(sub, true);
            }).catch(function (err) {
                // Surface the real reason (push-service errors differ per
                // browser/OS) instead of silently snapping back to off.
                setFailedState(err, 'enabling');
            });
        });
    }

    function unsubscribe() {
        setState('working');
        return waitForServiceWorker().then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            if (!sub) {
                setState('off');
                return;
            }
            var endpoint = sub.endpoint;
            return sub.unsubscribe().then(function () {
                return postJson('/api/push/unsubscribe.php', { endpoint: endpoint }).catch(function () {});
            }).then(function () {
                setState('off');
            });
        }).catch(function (err) {
            setFailedState(err, 'disabling');
        });
    }

    function onToggle() {
        if (busy) {
            return;
        }
        busy = true;
        var isOn = this.classList.contains('is-on');
        (isOn ? unsubscribe() : subscribe()).then(function () {
            busy = false;
        }, function () {
            busy = false;
        });
    }

    if (!supported) {
        // Reveal only the labelled row so the user sees *why*; a bare disabled
        // bell in the header would just be confusing.
        toggles.forEach(function (el) {
            if (el.querySelector('[data-push-state]')) {
                el.hidden = false;
                el.disabled = true;
                el.querySelector('[data-push-state]').textContent = LABELS.unsupported;
            }
        });
        return;
    }

    toggles.forEach(function (el) {
        el.addEventListener('click', onToggle);
    });

    // A browser subscription can outlive its server registration. Confirm it
    // with Jellydash before showing the controls as enabled.
    if (Notification.permission === 'denied') {
        setState('blocked');
    } else {
        busy = true;
        setState('working');
        waitForServiceWorker().then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            if (!sub) {
                setState('off');
                return;
            }
            return storeSubscription(sub, false);
        }).catch(function (error) {
            setFailedState(error, 'reconciling');
        }).then(function () {
            busy = false;
        });
    }
})();
