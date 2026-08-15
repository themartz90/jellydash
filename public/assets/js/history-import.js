(function () {
    'use strict';

    var STORAGE_KEY = 'jellydash.playback-reporting-import.seen';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF_TOKEN = csrfMeta && csrfMeta.content ? csrfMeta.content : '';
    var lastHistoryRefresh = 0;

    function wasSeen() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === '1';
        } catch (error) {
            return true;
        }
    }

    function rememberSeen() {
        try {
            window.localStorage.setItem(STORAGE_KEY, '1');
        } catch (error) {
            // Storage can be blocked.
        }
    }

    function probe(forSettings) {
        var url = '/api/playback-reporting.php' + (forSettings ? '?probe=1' : '');
        return fetch(url, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Playback reporting probe failed');
            }
            return response.json();
        });
    }

    function preview(body) {
        return fetch('/api/playback-reporting.php', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: body,
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error((payload && payload.error) || 'Could not analyze the import.');
                }
                return payload;
            }, function () {
                throw new Error('Could not analyze the import.');
            });
        });
    }

    function readNdjson(stream, onProgress) {
        var reader = stream.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        var last = null;

        function consume(chunk, done) {
            buffer += chunk;
            var lines = buffer.split('\n');
            buffer = done ? '' : lines.pop() || '';
            if (done && buffer.trim() !== '') {
                lines.push(buffer);
                buffer = '';
            }
            lines.forEach(function (line) {
                line = line.trim();
                if (!line) {
                    return;
                }
                var payload = JSON.parse(line);
                last = payload;
                if (payload.phase === 'error') {
                    throw new Error(payload.error || 'Could not import.');
                }
                if (typeof onProgress === 'function') {
                    onProgress(payload);
                }
            });
        }

        function pump() {
            return reader.read().then(function (result) {
                consume(decoder.decode(result.value || new Uint8Array(), { stream: !result.done }), result.done);
                if (result.done) {
                    return last;
                }
                return pump();
            });
        }

        return pump();
    }

    function commit(body, onProgress) {
        body.append('commit', '1');
        return fetch('/api/playback-reporting.php', {
            method: 'POST',
            headers: {
                Accept: 'application/x-ndjson, application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: body,
        }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (!response.body || type.indexOf('ndjson') === -1) {
                return response.json().then(function (payload) {
                    throw new Error((payload && payload.error) || 'Could not import.');
                }, function () {
                    throw new Error('Could not import.');
                });
            }
            return readNdjson(response.body, onProgress);
        });
    }

    function playLabel(count) {
        return count === 1 ? '1 play' : String(count) + ' plays';
    }

    function setText(nodes, text) {
        nodes.forEach(function (node) {
            node.textContent = text;
        });
    }

    function applyProgress(payload) {
        payload = payload || {};
        var phase = payload.phase || 'preparing';
        var processed = typeof payload.processed === 'number' ? payload.processed : 0;
        var total = typeof payload.total === 'number' ? payload.total : 0;
        var inserted = typeof payload.inserted === 'number' ? payload.inserted : 0;
        var skipped = typeof payload.skipped === 'number' ? payload.skipped : 0;
        var pct = total > 0 ? Math.max(0, Math.min(100, Math.round((processed / total) * 100))) : (phase === 'preparing' ? 2 : 0);
        if (phase === 'done') {
            pct = 100;
        }

        var label = 'Preparing…';
        if (phase === 'importing' && total > 0) {
            label = processed + ' of ' + playLabel(total) + ' written to History';
        } else if (phase === 'done') {
            label = 'Imported ' + playLabel(inserted) + (skipped ? ', skipped ' + skipped + ' already present' : '');
            if (payload.unresolved) {
                label += ', ' + payload.unresolved + ' without Jellyfin runtime';
            }
        }

        document.querySelectorAll('[data-import-history-progress]').forEach(function (node) {
            node.hidden = false;
        });
        var banner = document.querySelector('[data-import-history-banner]');
        if (banner) {
            banner.hidden = false;
        }
        document.querySelectorAll('[data-import-history-progress-bar]').forEach(function (bar) {
            bar.style.width = pct + '%';
        });
        document.querySelectorAll('[data-import-history-progress-track]').forEach(function (track) {
            track.setAttribute('aria-valuenow', String(pct));
        });
        setText(document.querySelectorAll('[data-import-history-progress-label]'), label);

        if (phase === 'importing' || phase === 'done') {
            refreshHistory(phase === 'done');
        }
    }

    function hideProgress() {
        document.querySelectorAll('[data-import-history-progress]').forEach(function (node) {
            if (!node.closest('[data-import-history-banner]')) {
                node.hidden = true;
            }
        });
        var banner = document.querySelector('[data-import-history-banner]');
        if (banner) {
            banner.hidden = true;
        }
    }

    function refreshHistory(force) {
        if (!document.querySelector('[data-history-live]')) {
            return;
        }
        var now = Date.now();
        if (!force && now - lastHistoryRefresh < 1500) {
            return;
        }
        lastHistoryRefresh = now;

        fetch(window.location.href, {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.text();
        }).then(function (html) {
            if (!html) {
                return;
            }
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nextLive = doc.querySelector('[data-history-live]');
            var live = document.querySelector('[data-history-live]');
            if (nextLive && live) {
                live.replaceWith(nextLive);
            }
            var nextSummary = doc.querySelector('[data-history-summary]');
            var summary = document.querySelector('[data-history-summary]');
            if (nextSummary && summary) {
                summary.textContent = nextSummary.textContent;
            }
            var nextShown = doc.querySelector('[data-history-shown]');
            var shown = document.querySelector('[data-history-shown]');
            if (nextShown && shown) {
                shown.innerHTML = nextShown.innerHTML;
            }
        }).catch(function () {});
    }

    function finishImport(payload) {
        var inserted = payload && typeof payload.inserted === 'number' ? payload.inserted : 0;
        var skipped = payload && typeof payload.skipped === 'number' ? payload.skipped : 0;
        if (document.querySelector('[data-import-drop]')) {
            window.location.href = '/settings?' + new URLSearchParams({
                imported: String(inserted),
                skipped: String(skipped),
                unresolved: String(typeof payload.unresolved === 'number' ? payload.unresolved : 0),
            }).toString();
            return;
        }
        if (document.querySelector('[data-history-live]')) {
            window.location.reload();
        }
    }

    function runImport(body, dialog, gen) {
        rememberSeen();
        dialog.setState('importing', { processed: 0, total: 0 });
        applyProgress({ phase: 'preparing', processed: 0, total: 0, inserted: 0, skipped: 0 });
        return commit(body, function (payload) {
            if (dialog.isCurrent(gen) || (dialog.element && dialog.element.open)) {
                dialog.setState('importing', payload);
            }
            applyProgress(payload);
        }).then(function (payload) {
            applyProgress(payload || { phase: 'done', processed: 0, total: 0, inserted: 0, skipped: 0 });
            if (dialog.isCurrent(gen) || (dialog.element && dialog.element.open)) {
                dialog.setState('done', payload || {});
            }
            finishImport(payload || {});
        }).catch(function (error) {
            hideProgress();
            if (!dialog.isCurrent(gen) && !(dialog.element && dialog.element.open)) {
                return;
            }
            dialog.setState('error', { error: error.message || 'Could not import.' });
        });
    }

    function wireDropzone() {
        var form = document.querySelector('[data-import-drop]');
        if (!form) {
            return;
        }

        var zone = form.querySelector('[data-import-dropzone]');
        var input = form.querySelector('input[name="playback_reporting"]');
        var pluginBtn = form.querySelector('[data-import-plugin]');
        var alt = form.querySelector('[data-import-alt]');
        if (!zone || !input) {
            return;
        }

        function setOver(over) {
            zone.classList.toggle('is-over', over);
        }

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                setOver(true);
            });
        });
        zone.addEventListener('dragleave', function () {
            setOver(false);
        });
        zone.addEventListener('drop', function () {
            setOver(false);
        });
        input.addEventListener('change', function () {
            if (input.files && input.files.length) {
                form.requestSubmit();
            }
        });

        var allowSubmit = false;
        form.addEventListener('submit', function (event) {
            if (allowSubmit) {
                allowSubmit = false;
                return;
            }
            event.preventDefault();

            var submitter = event.submitter;
            var fromPlugin = !!(submitter && submitter.getAttribute('name') === 'import_source' && submitter.value === 'plugin');
            var dialog = getDialog();
            if (!dialog || !CSRF_TOKEN) {
                allowSubmit = true;
                form.requestSubmit(submitter || undefined);
                return;
            }

            var body = new FormData();
            if (fromPlugin) {
                body.append('import_source', 'plugin');
            } else if (!input.files || !input.files.length) {
                dialog.openConfirm({ source: 'file', kind: 'tsv' });
                dialog.setState('error', { error: 'Drop a Playback Reporting TSV backup or playback_reporting.db first.' });
                return;
            } else {
                body.append('playback_reporting', input.files[0]);
            }

            var gen = dialog.openConfirm({
                source: fromPlugin ? 'plugin' : 'file',
                kind: 'tsv',
                onConfirm: function () {
                    var importBody = new FormData();
                    if (fromPlugin) {
                        importBody.append('import_source', 'plugin');
                    } else if (input.files && input.files[0]) {
                        importBody.append('playback_reporting', input.files[0]);
                    }
                    runImport(importBody, dialog, gen);
                },
            });
            dialog.setState('busy');
            preview(body).then(function (payload) {
                if (!dialog.isCurrent(gen)) {
                    return;
                }
                var count = payload && typeof payload.parsed === 'number' ? payload.parsed : 0;
                var kind = payload && payload.kind ? payload.kind : (fromPlugin ? 'plugin' : 'tsv');
                if (count <= 0) {
                    dialog.setState('empty');
                    return;
                }
                dialog.setState('confirm', {
                    count: count,
                    kind: kind,
                    source: fromPlugin ? 'plugin' : 'file',
                });
            }).catch(function (error) {
                if (!dialog.isCurrent(gen)) {
                    return;
                }
                dialog.setState('error', { error: error.message || 'Could not analyze the import.' });
            });
        });

        if (pluginBtn) {
            var brokenNote = document.querySelector('[data-import-plugin-broken-note]');
            var okNote = document.querySelector('[data-import-plugin-ok-note]');
            probe(true).then(function (payload) {
                if (!payload) {
                    return;
                }
                if (payload.broken) {
                    if (brokenNote) {
                        brokenNote.hidden = false;
                    }
                    if (okNote) {
                        okNote.hidden = true;
                    }
                    return;
                }
                if ((payload.importable || payload.available) && alt) {
                    alt.hidden = false;
                }
            }).catch(function () {});
        }
    }

    var dialogApi = null;

    function getDialog() {
        if (dialogApi) {
            return dialogApi;
        }

        var dialog = document.querySelector('[data-import-history-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return null;
        }

        var title = dialog.querySelector('[data-import-history-title]');
        var summary = dialog.querySelector('[data-import-history-summary]');
        var form = dialog.querySelector('[data-import-history-form]');
        var confirmBtn = dialog.querySelector('[data-import-history-confirm]');
        var progress = dialog.querySelector('[data-import-history-progress]');
        var closeButtons = dialog.querySelectorAll('[data-import-history-close]');
        var dismissBtn = dialog.querySelector('.release-dialog-dismiss');
        var token = 0;
        var pending = null;
        var allowDialogSubmit = false;
        var importing = false;

        function isCurrent(generation) {
            return generation === token && dialog.open;
        }

        function setProgressVisible(visible) {
            if (progress) {
                progress.hidden = !visible;
            }
        }

        function setState(state, options) {
            options = options || {};
            if (!title || !summary || !confirmBtn) {
                return;
            }

            if (state === 'busy') {
                importing = false;
                title.textContent = 'Checking the import…';
                summary.textContent = 'Jellydash is counting the plays before asking you to confirm.';
                confirmBtn.hidden = true;
                confirmBtn.disabled = true;
                setProgressVisible(false);
                if (dismissBtn) {
                    dismissBtn.hidden = false;
                    dismissBtn.textContent = 'Cancel';
                }
                return;
            }

            if (state === 'importing') {
                importing = true;
                title.textContent = 'Importing history…';
                summary.textContent = 'Plays are written to History as they land. You can leave this dialog open.';
                confirmBtn.hidden = true;
                confirmBtn.disabled = true;
                setProgressVisible(true);
                if (dismissBtn) {
                    dismissBtn.hidden = true;
                }
                return;
            }

            if (state === 'done') {
                importing = false;
                title.textContent = 'Import complete';
                summary.textContent = options.inserted
                    ? 'Imported ' + playLabel(options.inserted) + (options.skipped ? ', skipped ' + options.skipped + ' already present' : '') + '.'
                    : 'Import finished.';
                confirmBtn.hidden = true;
                confirmBtn.disabled = true;
                setProgressVisible(true);
                if (dismissBtn) {
                    dismissBtn.hidden = false;
                    dismissBtn.textContent = 'Close';
                }
                return;
            }

            if (state === 'error') {
                importing = false;
                title.textContent = 'Could not import';
                summary.textContent = options.error || 'Could not analyze the import.';
                confirmBtn.hidden = true;
                confirmBtn.disabled = true;
                setProgressVisible(false);
                if (dismissBtn) {
                    dismissBtn.hidden = false;
                    dismissBtn.textContent = 'Close';
                }
                return;
            }

            if (state === 'empty') {
                importing = false;
                title.textContent = 'No plays to import';
                summary.textContent = 'Jellydash did not find any playback rows in this source.';
                confirmBtn.hidden = true;
                confirmBtn.disabled = true;
                setProgressVisible(false);
                if (dismissBtn) {
                    dismissBtn.hidden = false;
                    dismissBtn.textContent = 'Close';
                }
                return;
            }

            importing = false;
            var count = options.count || 0;
            var source = options.source || (pending && pending.source) || 'plugin';
            var kind = options.kind || (pending && pending.kind) || 'tsv';
            var plays = playLabel(count);
            title.textContent = 'Import ' + plays + '?';
            if (source === 'plugin') {
                summary.textContent = 'Jellydash found ' + plays + ' in Playback Reporting. Imported plays never trigger notifications.';
            } else if (kind === 'sqlite') {
                summary.textContent = 'This database contains ' + plays + '. Imported plays never trigger notifications.';
            } else {
                summary.textContent = 'This backup contains ' + plays + '. Imported plays never trigger notifications.';
            }
            confirmBtn.hidden = false;
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Import ' + plays;
            setProgressVisible(false);
            if (dismissBtn) {
                dismissBtn.hidden = false;
                dismissBtn.textContent = 'Not now';
            }
        }

        function dismiss() {
            if (importing) {
                return;
            }
            rememberSeen();
            pending = null;
            token += 1;
            hideProgress();
            if (dialog.open) {
                dialog.close();
            }
        }

        function openConfirm(options) {
            pending = options || {};
            token += 1;
            if (!dialog.open) {
                dialog.showModal();
            }
            return token;
        }

        closeButtons.forEach(function (button) {
            button.addEventListener('click', dismiss);
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dismiss();
            }
        });
        dialog.addEventListener('cancel', function (event) {
            if (importing) {
                event.preventDefault();
                return;
            }
            rememberSeen();
            pending = null;
            token += 1;
            hideProgress();
        });
        if (form) {
            form.addEventListener('submit', function (event) {
                if (allowDialogSubmit) {
                    allowDialogSubmit = false;
                    rememberSeen();
                    if (confirmBtn) {
                        confirmBtn.disabled = true;
                        confirmBtn.textContent = 'Importing…';
                    }
                    return;
                }
                event.preventDefault();
                if (!pending || importing) {
                    return;
                }
                if (typeof pending.onConfirm === 'function') {
                    pending.onConfirm();
                    return;
                }
                var body = new FormData();
                body.append('import_source', 'plugin');
                runImport(body, dialogApi, token);
            });
        }

        dialogApi = {
            openConfirm: openConfirm,
            setState: setState,
            isCurrent: isCurrent,
            element: dialog,
        };

        return dialogApi;
    }

    function wireModal() {
        var dialog = getDialog();
        if (!dialog || wasSeen()) {
            return;
        }

        var shown = false;
        var started = false;

        function showWhenIdle() {
            if (shown || started || wasSeen()) {
                return;
            }
            var release = document.querySelector('[data-release-dialog]');
            if (release && release.open) {
                release.addEventListener('close', showWhenIdle, { once: true });
                return;
            }

            started = true;
            probe(false).then(function (payload) {
                if (shown || wasSeen()) {
                    return;
                }
                if (!payload || payload.broken || !payload.history_empty) {
                    return;
                }
                if (!payload.importable && !payload.available) {
                    return;
                }
                if (release && release.open) {
                    started = false;
                    release.addEventListener('close', showWhenIdle, { once: true });
                    return;
                }
                if (!CSRF_TOKEN) {
                    return;
                }

                shown = true;
                var body = new FormData();
                body.append('import_source', 'plugin');
                var gen = dialog.openConfirm({
                    source: 'plugin',
                    kind: 'plugin',
                    onConfirm: function () {
                        var importBody = new FormData();
                        importBody.append('import_source', 'plugin');
                        runImport(importBody, dialog, gen);
                    },
                });
                dialog.setState('busy');
                preview(body).then(function (result) {
                    if (!dialog.isCurrent(gen) || wasSeen()) {
                        return;
                    }
                    var count = result && typeof result.parsed === 'number' ? result.parsed : 0;
                    if (count <= 0) {
                        rememberSeen();
                        shown = false;
                        if (dialog.element.open) {
                            dialog.element.close();
                        }
                        return;
                    }
                    dialog.setState('confirm', {
                        count: count,
                        kind: 'plugin',
                        source: 'plugin',
                    });
                }).catch(function () {
                    shown = false;
                    if (dialog.element.open) {
                        dialog.element.close();
                    }
                });
            }).catch(function () {});
        }

        document.addEventListener('jellydash:release-dialog-settled', showWhenIdle, { once: true });
        window.setTimeout(showWhenIdle, 6000);
    }

    getDialog();
    wireDropzone();
    wireModal();
}());
