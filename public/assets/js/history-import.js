(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF_TOKEN = csrfMeta && csrfMeta.content ? csrfMeta.content : '';

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

    function preview(body, endpoint) {
        return fetch(endpoint, {
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
                    if (!last || last.phase !== 'done') {
                        throw new Error('The import stopped before it finished. Please try again.');
                    }
                    return last;
                }
                return pump();
            });
        }

        return pump();
    }

    function commit(body, onProgress, endpoint) {
        body.append('commit', '1');
        return fetch(endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/x-ndjson, application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: body,
        }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (!response.ok) {
                throw new Error('Could not import. The server returned HTTP ' + response.status + '.');
            }
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

    if (window.JellydashFrontendTestHooks) {
        window.JellydashFrontendTestHooks.readImportNdjson = readNdjson;
        window.JellydashFrontendTestHooks.commitHistoryImport = commit;
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
        document.querySelectorAll('[data-import-history-progress-bar]').forEach(function (bar) {
            bar.style.width = pct + '%';
        });
        document.querySelectorAll('[data-import-history-progress-track]').forEach(function (track) {
            track.setAttribute('aria-valuenow', String(pct));
        });
        setText(document.querySelectorAll('[data-import-history-progress-label]'), label);
    }

    function hideProgress() {
        document.querySelectorAll('[data-import-history-progress]').forEach(function (node) {
            node.hidden = true;
        });
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
    }

    function runImport(body, dialog, gen, endpoint) {
        dialog.setState('importing', { processed: 0, total: 0 });
        applyProgress({ phase: 'preparing', processed: 0, total: 0, inserted: 0, skipped: 0 });
        return commit(body, function (payload) {
            if (dialog.isCurrent(gen) || (dialog.element && dialog.element.open)) {
                dialog.setState('importing', payload);
            }
            applyProgress(payload);
        }, endpoint).then(function (payload) {
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

    function wireDropzone(form) {
        if (!form) {
            return;
        }

        var zone = form.querySelector('[data-import-dropzone]');
        var input = form.querySelector('input[type="file"]');
        var pluginBtn = form.querySelector('[data-import-plugin]');
        var alt = form.querySelector('[data-import-alt]');
        var endpoint = form.getAttribute('data-import-endpoint') || form.action;
        var sourceType = form.getAttribute('data-import-source') || 'playback-reporting';
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
            var nativeCsv = sourceType === 'jellydash';
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
                dialog.openConfirm({ source: nativeCsv ? 'jellydash' : 'file', kind: nativeCsv ? 'jellydash' : 'tsv' });
                dialog.setState('error', { error: nativeCsv ? 'Choose a Jellydash History CSV first.' : 'Drop a Playback Reporting TSV backup or playback_reporting.db first.' });
                return;
            } else {
                body.append(input.name, input.files[0]);
            }

            var gen = dialog.openConfirm({
                source: nativeCsv ? 'jellydash' : (fromPlugin ? 'plugin' : 'file'),
                kind: nativeCsv ? 'jellydash' : 'tsv',
                onConfirm: function () {
                    var importBody = new FormData();
                    if (fromPlugin) {
                        importBody.append('import_source', 'plugin');
                    } else if (input.files && input.files[0]) {
                        importBody.append(input.name, input.files[0]);
                    }
                    runImport(importBody, dialog, gen, endpoint);
                },
            });
            dialog.setState('busy');
            preview(body, endpoint).then(function (payload) {
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
                    source: nativeCsv ? 'jellydash' : (fromPlugin ? 'plugin' : 'file'),
                    importable: payload && typeof payload.importable === 'number' ? payload.importable : count,
                    skipped: payload && typeof payload.skipped === 'number' ? payload.skipped : 0,
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
        var kicker = dialog.querySelector('[data-import-history-kicker]');
        var form = dialog.querySelector('[data-import-history-form]');
        var confirmBtn = dialog.querySelector('[data-import-history-confirm]');
        var progress = dialog.querySelector('[data-import-history-progress]');
        var closeButtons = dialog.querySelectorAll('[data-import-history-close]');
        var dismissBtn = dialog.querySelector('.release-dialog-dismiss');
        var token = 0;
        var pending = null;
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
                summary.textContent = 'Jellydash is restoring the selected history. Keep this window open.';
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
            var importable = typeof options.importable === 'number' ? options.importable : count;
            var skipped = typeof options.skipped === 'number' ? options.skipped : 0;
            var plays = playLabel(source === 'jellydash' ? importable : count);
            if (source === 'jellydash' && importable <= 0) {
                title.textContent = 'History is already up to date';
                summary.textContent = 'All ' + playLabel(count) + ' in this backup are already present.';
                confirmBtn.hidden = true;
                confirmBtn.disabled = true;
                setProgressVisible(false);
                if (dismissBtn) {
                    dismissBtn.hidden = false;
                    dismissBtn.textContent = 'Close';
                }
                return;
            }
            title.textContent = 'Import ' + plays + '?';
            if (source === 'jellydash') {
                summary.textContent = 'This Jellydash backup contains ' + playLabel(count)
                    + (skipped ? '. ' + skipped + ' already present will be skipped.' : '. Nothing already in History will be duplicated.');
            } else if (source === 'plugin') {
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
            if (kicker) {
                kicker.textContent = pending.source === 'jellydash' ? 'Jellydash CSV' : 'Playback Reporting';
            }
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
            pending = null;
            token += 1;
            hideProgress();
        });
        if (form) {
            form.addEventListener('submit', function (event) {
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
                runImport(body, dialogApi, token, '/api/playback-reporting.php');
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

    getDialog();
    document.querySelectorAll('[data-import-drop]').forEach(wireDropzone);
}());
