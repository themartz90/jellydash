(function () {
    'use strict';

    var resolveUpgrade;
    window.jellydashUpgradeReady = new Promise(function (resolve) {
        resolveUpgrade = resolve;
    });

    var dialog = document.querySelector('[data-history-library-upgrade]');
    var title = dialog && dialog.querySelector('[data-history-library-upgrade-title]');
    var summary = dialog && dialog.querySelector('[data-history-library-upgrade-summary]');
    var count = dialog && dialog.querySelector('[data-history-library-upgrade-count]');
    var percent = dialog && dialog.querySelector('[data-history-library-upgrade-percent]');
    var track = dialog && dialog.querySelector('[data-history-library-upgrade-track]');
    var bar = dialog && dialog.querySelector('[data-history-library-upgrade-bar]');
    var note = dialog && dialog.querySelector('[data-history-library-upgrade-note]');
    var hideButton = dialog && dialog.querySelector('[data-history-library-upgrade-hide]');
    var errorActions = dialog && dialog.querySelector('[data-history-library-upgrade-error]');
    var retry = dialog && dialog.querySelector('[data-history-library-upgrade-retry]');
    var continueButton = dialog && dialog.querySelector('[data-history-library-upgrade-continue]');
    var completeActions = dialog && dialog.querySelector('[data-history-library-upgrade-complete]');
    var closeButton = dialog && dialog.querySelector('[data-history-library-upgrade-close]');
    var reopenButton = document.querySelector('[data-history-library-upgrade-reopen]');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta && csrfMeta.content ? csrfMeta.content : '';
    var settled = false;
    var timer = null;
    var pollGeneration = 0;

    function stopPolling() {
        pollGeneration += 1;
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    function settle() {
        if (settled) {
            return;
        }
        settled = true;
        stopPolling();
        resolveUpgrade();
    }

    if (!dialog || typeof dialog.showModal !== 'function' || !title || !summary || !count || !percent || !track || !bar || !note || !hideButton || !errorActions || !retry || !continueButton || !completeActions || !closeButton || !reopenButton || !csrfToken) {
        settle();
        return;
    }

    function request(method) {
        var controller = new AbortController();
        var timedOut = false;
        var timeout = window.setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, 15000);
        var options = {
            method: method,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        };
        if (method === 'POST') {
            options.headers['X-CSRF-Token'] = csrfToken;
        }

        return fetch('/api/history-library-upgrade.php', options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload || typeof payload.required !== 'boolean') {
                    throw new Error(payload && payload.error ? payload.error : 'Jellydash could not continue the History update.');
                }
                return payload;
            }, function () {
                throw new Error('Jellydash could not continue the History update.');
            });
        }).catch(function (error) {
            if (timedOut) throw new Error('The History update request timed out. Jellydash will check its progress before continuing.');
            throw error;
        }).finally(function () { window.clearTimeout(timeout); });
    }

    function progressLabel(processed, total) {
        if (total <= 0) {
            return 'Preparing your History…';
        }
        return 'Checking ' + processed + ' of ' + total + ' plays';
    }

    function render(payload) {
        var total = Math.max(0, Number(payload.total) || 0);
        var processed = Math.min(total, Math.max(0, Number(payload.processed) || 0));
        var value = Math.min(100, Math.max(0, Number(payload.percent) || 0));
        count.textContent = progressLabel(processed, total);
        percent.textContent = Math.round(value) + '%';
        track.setAttribute('aria-valuenow', String(Math.round(value)));
        bar.style.width = value + '%';
    }

    function showError(message) {
        stopPolling();
        hideButton.hidden = true;
        title.textContent = 'Could not finish the History update';
        summary.textContent = message;
        completeActions.hidden = true;
        errorActions.hidden = false;
        retry.focus();
    }

    function finish(payload) {
        stopPolling();
        hideButton.hidden = true;
        render(payload);
        title.textContent = 'History update complete';
        summary.textContent = 'Jellydash matched older plays to their real Jellyfin libraries wherever Jellyfin could still find the item.';
        count.textContent = 'Checked ' + payload.total + (payload.total === 1 ? ' play' : ' plays');
        note.textContent = 'This one-time update is complete.';
        errorActions.hidden = true;
        completeActions.hidden = false;
        closeButton.focus();
        reopenButton.hidden = true;
    }

    function advance(generation) {
        if (generation !== pollGeneration || !dialog.open) {
            return;
        }
        timer = null;
        errorActions.hidden = true;
        completeActions.hidden = true;
        hideButton.hidden = false;
        title.textContent = 'Updating your History';
        summary.textContent = 'Jellydash is matching older plays to their real Jellyfin libraries.';
        note.textContent = 'Progress is saved. You can close Jellydash if you need to.';

        request('POST').then(function (payload) {
            if (generation !== pollGeneration || !dialog.open) {
                return;
            }
            render(payload);
            if (payload.state === 'complete') {
                finish(payload);
                return;
            }
            timer = window.setTimeout(function () { advance(generation); }, payload.busy ? 700 : 180);
        }).catch(function (error) {
            if (generation !== pollGeneration || !dialog.open) {
                return;
            }
            showError(error.message || 'Jellydash could not continue the History update.');
        });
    }

    function hideForNow() {
        stopPolling();
        if (dialog.open) {
            dialog.close();
        }
        reopenButton.hidden = false;
        settle();
    }

    function showStatus(payload) {
        stopPolling();
        if (!payload.required) {
            if (dialog.open && payload.state === 'complete') {
                finish(payload);
                return;
            }
            if (dialog.open) dialog.close();
            reopenButton.hidden = true;
            settle();
            return;
        }

        render(payload);
        if (!dialog.open) {
            dialog.showModal();
        }
        dialog.focus();
        reopenButton.hidden = true;
        if (payload.state === 'complete') {
            finish(payload);
            return;
        }
        errorActions.hidden = true;
        completeActions.hidden = true;
        hideButton.hidden = false;
        var generation = pollGeneration;
        timer = window.setTimeout(function () { advance(generation); }, 120);
    }

    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        hideForNow();
    });
    hideButton.addEventListener('click', hideForNow);
    continueButton.addEventListener('click', hideForNow);
    function reconcileStatus() {
        stopPolling();
        var generation = pollGeneration;
        retry.disabled = true;
        reopenButton.disabled = true;
        request('GET').then(function (status) {
            if (generation === pollGeneration) showStatus(status);
        }).catch(function (error) {
            if (generation !== pollGeneration) return;
            if (!dialog.open) {
                dialog.showModal();
            }
            reopenButton.hidden = true;
            showError(error.message || 'Jellydash could not continue the History update.');
        }).finally(function () {
            retry.disabled = false;
            reopenButton.disabled = false;
        });
    }
    retry.addEventListener('click', reconcileStatus);
    reopenButton.addEventListener('click', reconcileStatus);
    closeButton.addEventListener('click', function () {
        stopPolling();
        if (dialog.open) {
            dialog.close();
        }
        reopenButton.hidden = true;
        settle();
    });

    request('GET').then(showStatus).catch(function () {
        settle();
    });
}());
