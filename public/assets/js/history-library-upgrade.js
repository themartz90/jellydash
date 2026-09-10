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

    function settle() {
        if (settled) {
            return;
        }
        settled = true;
        if (timer !== null) {
            window.clearTimeout(timer);
        }
        resolveUpgrade();
    }

    if (!dialog || typeof dialog.showModal !== 'function' || !title || !summary || !count || !percent || !track || !bar || !note || !errorActions || !retry || !continueButton || !completeActions || !closeButton || !reopenButton || !csrfToken) {
        settle();
        return;
    }

    function request(method) {
        var options = {
            method: method,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        };
        if (method === 'POST') {
            options.headers['X-CSRF-Token'] = csrfToken;
        }

        return fetch('/api/history-library-upgrade.php', options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload && payload.error ? payload.error : 'Jellydash could not continue the History update.');
                }
                return payload;
            }, function () {
                throw new Error('Jellydash could not continue the History update.');
            });
        });
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
        title.textContent = 'We couldn\'t finish the update';
        summary.textContent = message;
        completeActions.hidden = true;
        errorActions.hidden = false;
        retry.focus();
    }

    function finish(payload) {
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

    function advance() {
        errorActions.hidden = true;
        completeActions.hidden = true;
        title.textContent = 'Updating your History';
        summary.textContent = 'Jellydash is matching older plays to their real Jellyfin libraries.';
        note.textContent = 'Progress is saved. You can close Jellydash if you need to.';

        request('POST').then(function (payload) {
            render(payload);
            if (payload.state === 'complete') {
                finish(payload);
                return;
            }
            timer = window.setTimeout(advance, payload.busy ? 700 : 180);
        }).catch(function (error) {
            showError(error.message || 'Jellydash could not continue the History update.');
        });
    }

    function hideForNow() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
        if (dialog.open) {
            dialog.close();
        }
        reopenButton.hidden = false;
        settle();
    }

    function showStatus(payload) {
        if (!payload.required) {
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
        timer = window.setTimeout(advance, 120);
    }

    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        hideForNow();
    });
    retry.addEventListener('click', advance);
    continueButton.addEventListener('click', hideForNow);
    reopenButton.addEventListener('click', function () {
        request('GET').then(showStatus).catch(function (error) {
            if (!dialog.open) {
                dialog.showModal();
            }
            reopenButton.hidden = true;
            showError(error.message || 'Jellydash could not continue the History update.');
        });
    });
    closeButton.addEventListener('click', function () {
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
