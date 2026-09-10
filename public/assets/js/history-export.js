(function () {
    'use strict';

    var dialog = document.querySelector('[data-history-export-dialog]');
    var openButton = document.querySelector('[data-history-export-open]');
    var form = dialog && dialog.querySelector('[data-history-export-form]');
    var countBox = dialog && dialog.querySelector('[data-history-export-count]');
    var countLabel = dialog && dialog.querySelector('[data-history-export-count-label]');
    var downloadButton = dialog && dialog.querySelector('[data-history-export-download]');
    var allButton = dialog && dialog.querySelector('[data-history-export-all]');
    var closeButtons = dialog && dialog.querySelectorAll('[data-history-export-close]');
    var downloadFrame = dialog && dialog.querySelector('[data-history-export-frame]');
    var timer = null;
    var requestController = null;
    var downloadRequested = false;

    if (!dialog || typeof dialog.showModal !== 'function' || !openButton || !form || !countBox || !countLabel || !downloadButton || !allButton || !closeButtons || !downloadFrame) {
        return;
    }

    function setCountState(state, label) {
        countBox.className = 'history-export-count is-' + state;
        countLabel.textContent = label;
        downloadButton.disabled = state !== 'ready';
    }

    function params(preview) {
        var values = new URLSearchParams(new FormData(form));
        if (values.get('range') !== 'custom') {
            values.delete('start');
            values.delete('end');
        }
        if (preview) {
            values.set('preview', '1');
        }
        return values;
    }

    function updateCount() {
        if (requestController) {
            requestController.abort();
        }
        requestController = typeof AbortController === 'function' ? new AbortController() : null;
        setCountState('loading', 'Checking matching plays…');

        fetch('/api/history-export.php?' + params(true).toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: requestController ? requestController.signal : undefined,
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload && payload.error ? payload.error : 'Could not count matching plays.');
                }
                return payload;
            });
        }).then(function (payload) {
            var plays = Math.max(0, Number(payload.plays) || 0);
            if (plays === 0) {
                setCountState('empty', 'No plays match these choices');
                return;
            }
            setCountState('ready', plays + (plays === 1 ? ' play is ready to export' : ' plays are ready to export'));
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            setCountState('error', 'Could not check these choices');
        });
    }

    function scheduleCount() {
        if (timer !== null) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(updateCount, 180);
    }

    function closeDialog() {
        if (dialog.open) {
            dialog.close();
        }
    }

    openButton.addEventListener('click', function () {
        dialog.showModal();
        updateCount();
    });
    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeDialog);
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            closeDialog();
        }
    });
    form.addEventListener('input', scheduleCount);
    form.addEventListener('change', scheduleCount);
    form.addEventListener('formdata', function (event) {
        if (event.formData.get('range') !== 'custom') {
            event.formData.delete('start');
            event.formData.delete('end');
        }
    });
    form.addEventListener('submit', function () {
        downloadRequested = true;
        setCountState('loading', 'Download requested. Your browser will save the CSV when it is ready.');
        window.setTimeout(closeDialog, 0);
    });
    downloadFrame.addEventListener('load', function () {
        if (!downloadRequested) {
            return;
        }
        try {
            var frameDocument = downloadFrame.contentDocument;
            var contentType = frameDocument && String(frameDocument.contentType || '').toLowerCase();
            if (contentType && contentType !== 'text/csv' && contentType !== 'application/octet-stream') {
                downloadRequested = false;
                setCountState('error', contentType.indexOf('text/html') !== -1
                    ? 'The export session expired or the download could not start.'
                    : 'Could not prepare the History export.');
                if (!dialog.open) {
                    dialog.showModal();
                }
            }
        } catch (error) {
            // A successful attachment may not expose a document to its target frame.
        }
    });
    allButton.addEventListener('click', function () {
        form.elements.search.value = '';
        form.elements.user.value = '';
        form.elements.library.value = '';
        form.elements.client.value = '';
        form.elements.method.value = '';
        form.elements.range.value = 'all';
        updateCount();
    });
}());
