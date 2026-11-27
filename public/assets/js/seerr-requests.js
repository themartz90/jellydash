(function () {
    const workspace = document.querySelector('[data-seerr-workspace]');
    const dialog = document.querySelector('[data-seerr-dialog]');
    if (!workspace || !dialog) return;

    const queue = workspace.querySelector('[data-seerr-queue]');
    const announcement = workspace.querySelector('[data-seerr-announcement]');
    const counts = new Map(Array.from(workspace.querySelectorAll('[data-seerr-count]'),
        (badge) => [badge.dataset.seerrCount, badge]));
    const activeFilter = workspace.dataset.seerrFilter;
    const range = workspace.querySelector('[data-seerr-range]');
    const stats = document.querySelector('[data-seerr-stats]');
    const scopeGroup = document.querySelector('[data-seerr-stat-scope]');
    const scope = scopeGroup.querySelector('#seerr-stat-scope');
    const available = stats.querySelector('[data-seerr-available]');
    const partial = stats.querySelector('[data-seerr-partial]');
    const requested = stats.querySelector('[data-seerr-requested]');
    const detail = dialog.querySelector('[data-seerr-detail]');
    const closeButton = dialog.querySelector('[data-seerr-close]');
    const detailFeedback = dialog.querySelector('[data-seerr-detail-feedback]');
    const actionStatus = workspace.parentElement.querySelector('[data-seerr-action-status]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const scrollArea = workspace.closest('.dashboard-content');
    let queueUrl = new URL(workspace.dataset.queueUrl, location.href);
    let queueLoad;
    let detailLoad;
    let editLoad;
    let profileLoad;
    let fileLoad;
    let openId = null;
    let opener = null;
    let openerIndex = -1;
    let detailScroll = null;
    let working = false;
    let statusTimer;

    const requestedId = () => new URLSearchParams(location.search).get('request');
    const validId = (value) => /^[1-9]\d{0,15}$/.test(value || '') && Number.isSafeInteger(Number(value));

    async function fragment(url, kind, signal) {
        const endpoint = new URL(url);
        endpoint.searchParams.set('fragment', kind);
        const response = await fetch(endpoint, { signal, cache: 'no-store' });
        if (!response.ok || response.redirected) throw new Error('Request unavailable');
        return response;
    }

    function showMessage(target, message, buttonAttribute) {
        const note = document.createElement('p');
        note.textContent = message;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'history-pager-btn';
        button.setAttribute(buttonAttribute, '');
        button.textContent = 'Try again';
        target.replaceChildren(note, button);
    }

    function updateArtwork(row, card) {
        const title = card.querySelector('[data-seerr-title]');
        const year = card.querySelector('[data-seerr-year]');
        const poster = card.querySelector('[data-seerr-poster]');
        title.textContent = row.title;
        title.title = row.title;
        year.textContent = row.year ? ' · ' + row.year : '';
        if (!row.poster || poster.querySelector('img')) return;

        const image = document.createElement('img');
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        poster.prepend(image);
        poster.querySelector('.seerr-poster-fallback').hidden = true;
        image.src = row.poster;
    }

    async function enrich(url, signal) {
        try {
            // Four media lookups per response, with at most six requests for a 24-card page.
            for (let batch = 0; batch < 6; batch++) {
                const response = await fragment(url, 'metadata', signal);
                const payload = await response.json();
                if (signal.aborted) return;
                const cards = new Map(Array.from(queue.querySelectorAll('[data-seerr-request]'), (card) => [card.dataset.seerrRequest, card]));
                for (const row of payload.requests) {
                    const card = cards.get(String(row.id));
                    if (card) updateArtwork(row, card);
                }
                if (!payload.remaining) return;
            }
        } catch (error) {
            // The visible queue remains usable while title or artwork is unavailable.
        }
    }

    function restoreScroll() {
        const saved = history.state?.jellydashContentScrollTop;
        if (Number.isFinite(saved)) scrollArea.scrollTop = saved;
    }

    function updateCount(filter, total) {
        const badge = counts.get(filter);
        if (!badge) return;
        const known = Number.isSafeInteger(total) && total >= 0;
        badge.textContent = known ? total.toLocaleString() : '';
        badge.hidden = !known || (total === 0 && filter !== 'all');
    }

    async function loadCounts(url, signal) {
        try {
            const response = await fragment(url, 'counts', signal);
            const totals = await response.json();
            if (signal.aborted) return;
            counts.forEach((badge, filter) => {
                if (filter !== activeFilter) updateCount(filter, totals[filter]);
            });
        } catch (error) {
            // An unavailable tab total leaves its label and the loaded queue in place.
        }
    }

    function updatePageContext(page) {
        const known = page && page.dataset.total !== '';
        range.hidden = !known;
        stats.hidden = !known || activeFilter !== 'all' || page.dataset.last === '0';
        scopeGroup.hidden = stats.hidden;
        if (!known) {
            announcement.textContent = 'Could not load requests.';
            return;
        }

        const number = (field) => Number(page.dataset[field]).toLocaleString();
        const start = number('first');
        const end = number('last');
        const total = number('total');
        const visible = start === end ? start : `${start}–${end}`;
        range.textContent = `Showing ${visible} of ${total}`;
        scope.textContent = page.dataset.state === 'cached' ? 'On this saved page' : 'On this page';
        available.textContent = number('available');
        partial.hidden = page.dataset.partial === '0';
        partial.textContent = ` · ${number('partial')} partial`;
        requested.textContent = number('requested');
        updateCount(activeFilter, Number(page.dataset.total));
        announcement.textContent = `${range.textContent}. Page ${page.dataset.page} of ${page.dataset.pages}.`;
    }

    async function loadQueue() {
        queueLoad?.abort();
        const { signal } = (queueLoad = new AbortController());
        counts.forEach((badge, filter) => updateCount(filter, null));
        queue.setAttribute('aria-busy', 'true');
        try {
            const response = await fragment(queueUrl, 'queue', signal);
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            const page = template.content.querySelector('[data-seerr-page]');
            if (signal.aborted) return;
            if (!page) throw new Error('Missing request page');
            queue.replaceChildren(template.content);
            queueUrl = new URL(page.dataset.url, location.href);
            const current = new URL(queueUrl);
            if (requestedId() !== null) current.searchParams.set('request', requestedId());
            if (current.href !== location.href) history.replaceState(history.state, '', current);

            updatePageContext(page);
            restoreScroll();
            if (page.dataset.state === 'current') void loadCounts(queueUrl, signal);
            if (page.dataset.enrich === '1') void enrich(queueUrl, signal);
        } catch (error) {
            if (!signal.aborted) {
                showMessage(queue, 'Could not load requests. If your session expired, reload to sign in.', 'data-seerr-retry');
                updatePageContext(null);
            }
        } finally {
            if (!signal.aborted) queue.setAttribute('aria-busy', 'false');
        }
    }

    async function loadDetail(id) {
        detailLoad?.abort();
        editLoad?.abort();
        profileLoad?.abort();
        fileLoad?.abort();
        const { signal } = (detailLoad = new AbortController());
        if (id !== openId || !dialog.open) {
            detailFeedback.textContent = '';
            detailFeedback.hidden = true;
        }
        openId = id;
        detail.textContent = 'Loading request…';
        if (!dialog.open) {
            detailScroll = scrollArea.scrollTop;
            dialog.showModal();
        }
        try {
            if (!validId(id)) {
                detail.textContent = 'This request could not be found.';
                return;
            }
            const url = new URL(queueUrl);
            url.searchParams.set('request', id);
            const response = await fragment(url, 'detail', signal);
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (signal.aborted) return;
            if (!template.content.querySelector('[data-seerr-detail-result]')) throw new Error('Missing request details');
            detail.replaceChildren(template.content);
        } catch (error) {
            if (!signal.aborted) showMessage(detail, 'Could not load this request from Seerr.', 'data-seerr-retry-detail');
        }
    }

    function syncDetail() {
        const id = requestedId();
        if (id !== null) {
            if (id !== openId || !dialog.open) void loadDetail(id);
            return;
        }
        detailLoad?.abort();
        editLoad?.abort();
        profileLoad?.abort();
        fileLoad?.abort();
        if (openId === null) return;

        if (location.href !== queueUrl.href) history.replaceState(history.state, '', queueUrl);
        const closingId = openId;
        openId = null;
        if (dialog.open) dialog.close();
        if (detailScroll !== null) scrollArea.scrollTop = detailScroll;
        const cards = Array.from(queue.querySelectorAll('[data-seerr-request]'));
        const card = cards.includes(opener) ? opener : cards.find((link) => link.dataset.seerrRequest === closingId)
            ?? cards[Math.min(openerIndex, cards.length - 1)];
        opener = null;
        openerIndex = -1;
        const focus = card ?? workspace.querySelector('[aria-current="page"]');
        focus?.focus({ preventScroll: true });
    }

    function closeDetail() {
        if (history.state?.seerrFromQueue) {
            history.back();
        } else {
            history.replaceState(history.state, '', queueUrl);
            syncDetail();
        }
    }

    function hideDetailActions(source, hidden) {
        source.querySelectorAll('[data-seerr-detail-controls], [data-seerr-edit-start]')
            .forEach((element) => { element.hidden = hidden; });
    }

    function showDeleteConfirmation(controls, show) {
        hideDetailActions(controls, show);
        controls.querySelector('[data-seerr-delete-confirmation]').hidden = !show;
        controls.querySelector(show ? '[data-seerr-delete-cancel]' : '[data-seerr-delete-start]')
            .focus({ preventScroll: !show });
    }

    async function openFileRemoval(source) {
        fileLoad?.abort();
        const { signal } = (fileLoad = new AbortController());
        const panel = source.querySelector('[data-seerr-file-panel]');
        const content = panel.querySelector('[data-seerr-file-content]');
        const confirm = panel.querySelector('[data-seerr-action="remove-files"]');
        const cancel = panel.querySelector('[data-seerr-file-cancel]');
        hideDetailActions(source, true);
        panel.hidden = false;
        confirm.hidden = true;
        delete confirm.dataset.fileToken;
        content.textContent = 'Checking the library target…';
        cancel.focus();
        try {
            const url = new URL(queueUrl);
            url.searchParams.set('request', source.dataset.seerrActionRequest);
            url.searchParams.set('version', source.dataset.seerrVersion);
            const response = await fragment(url, 'file-removal', signal);
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (signal.aborted) return;
            const result = template.content.querySelector('[data-seerr-file-result]');
            if (!result) throw new Error('Missing file removal target');
            content.replaceChildren(template.content);
            if (result.dataset.fileToken) {
                confirm.dataset.fileToken = result.dataset.fileToken;
                confirm.hidden = false;
            }
            if (document.activeElement === cancel) result.querySelector('h4').focus();
        } catch (error) {
            if (!signal.aborted) content.textContent = 'Could not verify the library target. Close this review and try again.';
        }
    }

    function cancelFileRemoval() {
        const panel = detail.querySelector('[data-seerr-file-panel]:not([hidden])');
        if (!panel || working) return false;
        fileLoad?.abort();
        panel.hidden = true;
        const source = panel.closest('[data-seerr-action-request]');
        hideDetailActions(source, false);
        source.querySelector('[data-seerr-file-start]').focus();
        return true;
    }

    function cancelDeleteConfirmation() {
        const confirmation = detail.querySelector('[data-seerr-delete-confirmation]:not([hidden])');
        if (!confirmation || working) return false;
        showDeleteConfirmation(confirmation.closest('[data-seerr-action-request]'), false);
        return true;
    }

    function editSelection(form) {
        const fields = new FormData(form);
        const change = { optionsVersion: form.dataset.optionsVersion };
        if (form.querySelector('[name="season"]')) change.seasons = fields.getAll('season').map(Number);
        if (fields.has('destination')) {
            change.destination = fields.get('destination');
            change.profile = fields.get('profile');
        }
        return change;
    }

    function updateEditor(form) {
        const destination = form.elements.destination?.value;
        form.querySelectorAll('[data-seerr-profile]').forEach((label) => {
            label.hidden = label.dataset.seerrProfile !== destination;
            label.querySelector('select').disabled = label.hidden;
        });
        const change = editSelection(form);
        const save = form.closest('[data-seerr-editor]').querySelector('[data-seerr-save-edit]');
        save.disabled = (change.seasons?.length === 0) || (change.destination && !change.profile)
            || JSON.stringify(change) === form.dataset.initialSelection;
    }

    async function changeDestination(form) {
        profileLoad?.abort();
        const destination = form.elements.destination.value;
        const status = form.querySelector('[data-seerr-profile-status]');
        form.querySelector('[data-seerr-loaded-profile]')?.remove();
        form.dataset.optionsVersion = form.dataset.baseVersion;
        status.hidden = true;
        updateEditor(form);
        if (form.querySelector('[name="profile"]:enabled')) return;

        const { signal } = (profileLoad = new AbortController());
        status.hidden = false;
        status.textContent = 'Loading quality profiles…';
        const url = new URL(queueUrl);
        url.searchParams.set('request', form.closest('[data-seerr-action-request]').dataset.seerrActionRequest);
        url.searchParams.set('version', form.dataset.version);
        url.searchParams.set('base', form.dataset.baseVersion);
        url.searchParams.set('destination', destination);
        try {
            const response = await fragment(url, 'editor', signal);
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (signal.aborted) return;
            const options = template.content.querySelector('[data-seerr-edit-form]');
            const profile = Array.from(options?.querySelectorAll('[data-seerr-profile]') ?? [])
                .find((label) => label.dataset.seerrProfile === destination);
            if (!profile || options.dataset.baseVersion !== form.dataset.baseVersion) throw new Error('Unverified destination');
            profile.setAttribute('data-seerr-loaded-profile', '');
            form.dataset.optionsVersion = options.dataset.optionsVersion;
            status.before(profile);
            status.hidden = true;
            updateEditor(form);
        } catch (error) {
            if (!signal.aborted) status.textContent = 'Could not verify this destination. Choose another or reopen the editor.';
        }
    }

    async function openEditor(source) {
        editLoad?.abort();
        profileLoad?.abort();
        const { signal } = (editLoad = new AbortController());
        const editor = source.querySelector('[data-seerr-editor]');
        const content = editor.querySelector('[data-seerr-edit-content]');
        const save = editor.querySelector('[data-seerr-save-edit]');
        hideDetailActions(source, true);
        editor.hidden = false;
        save.hidden = true;
        content.textContent = 'Loading editing options…';
        const cancel = editor.querySelector('[data-seerr-edit-cancel]');
        cancel.focus();
        try {
            const url = new URL(queueUrl);
            url.searchParams.set('request', source.dataset.seerrActionRequest);
            url.searchParams.set('version', source.dataset.seerrVersion);
            const response = await fragment(url, 'editor', signal);
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (signal.aborted) return;
            if (!template.content.querySelector('[data-seerr-editor-result]')) throw new Error('Missing editor options');
            content.replaceChildren(template.content);
            const form = content.querySelector('[data-seerr-edit-form]');
            if (form) {
                form.dataset.initialSelection = JSON.stringify(editSelection(form));
                updateEditor(form);
                save.hidden = false;
            }
            if (document.activeElement === cancel) content.querySelector('h4').focus();
        } catch (error) {
            if (!signal.aborted) content.textContent = 'Could not load editing options. Close the editor and try again.';
        }
    }

    function cancelEditor() {
        const editor = detail.querySelector('[data-seerr-editor]:not([hidden])');
        if (!editor || working) return false;
        editLoad?.abort();
        profileLoad?.abort();
        editor.hidden = true;
        const source = editor.closest('[data-seerr-action-request]');
        hideDetailActions(source, false);
        const edit = source.querySelector('[data-seerr-edit-start]');
        edit.focus();
        return true;
    }

    function showActionStatus(id, message, failure = false, duration = 0) {
        if (dialog.open && id === openId) {
            detailFeedback.hidden = false;
            detailFeedback.dataset.failure = String(failure);
            detailFeedback.textContent = message;
            return;
        }
        clearTimeout(statusTimer);
        actionStatus.hidden = false;
        actionStatus.dataset.failure = String(failure);
        actionStatus.textContent = message;
        if (duration) statusTimer = setTimeout(() => { actionStatus.hidden = true; }, duration);
    }

    async function runAction(button, form = null) {
        const source = button.closest('[data-seerr-action-request]');
        if (working || !source?.isConnected) return;
        const id = source.dataset.seerrActionRequest;
        const action = form ? 'edit' : button.dataset.seerrAction;
        const fromDetail = detail.contains(source);
        const fileToken = button.dataset.fileToken;
        if (action === 'remove-files' && (!fromDetail || !fileToken || button.hidden)) return;
        const links = Array.from(queue.querySelectorAll('[data-seerr-request]'));
        const index = links.findIndex((link) => link.dataset.seerrRequest === id);
        const beforeScroll = scrollArea.scrollTop;
        const edit = form ? editSelection(form) : undefined;
        const version = form ? form.dataset.version : source.dataset.seerrVersion;
        const controls = [...queue.querySelectorAll('[data-seerr-action]'), ...detail.querySelectorAll('button, input, select')]
            .filter((control) => !control.disabled);
        working = true;
        source.setAttribute('aria-busy', 'true');
        controls.forEach((control) => { control.disabled = true; });
        if (fromDetail) closeButton.focus({ preventScroll: true });
        showActionStatus(id, 'Sending the action to Seerr…');
        let refresh = true;
        try {
            const response = await fetch('/api/seerr-request.php', {
                method: 'POST', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    id: Number(id), action, version, edit, fileToken,
                    confirmed: fromDetail && (action === 'delete' || action === 'remove-files'),
                }),
            });
            const result = await response.json();
            refresh = result.refresh === true || response.status === 401 || response.status === 403;
            if (action === 'delete' && response.ok && result.refresh === true && dialog.open && openId === id) {
                // A card opens a history entry. Wait for Back before refreshing the queue URL.
                const navigation = history.state?.seerrFromQueue
                    ? new Promise(resolve => window.addEventListener('popstate', resolve, { once: true }))
                    : null;
                closeDetail();
                if (navigation) await navigation;
            }
            showActionStatus(id, result.message ?? result.error ?? 'Could not complete the action.', !response.ok, response.ok ? 8000 : 18000);
        } catch (error) {
            showActionStatus(id, 'The outcome is unknown. Check the current request before trying again.', true, 18000);
        } finally {
            if (refresh) {
                if (dialog.open && openId === id) {
                    closeButton.focus();
                    await loadDetail(id);
                }
                const restoreQueueFocus = !fromDetail || dialog.open || queue.contains(document.activeElement);
                await loadQueue();
                scrollArea.scrollTop = beforeScroll;
                if (!dialog.open && restoreQueueFocus && document.activeElement === document.body) {
                    const fresh = Array.from(queue.querySelectorAll('[data-seerr-request]'));
                    const focus = fresh.find((candidate) => candidate.dataset.seerrRequest === id)
                        ?? fresh[Math.min(index, fresh.length - 1)] ?? workspace.querySelector('[aria-current="page"]');
                    focus?.focus({ preventScroll: true });
                }
            }
            source.removeAttribute('aria-busy');
            controls.forEach((control) => { control.disabled = false; });
            if (!refresh && button.isConnected && dialog.open === fromDetail) button.focus({ preventScroll: true });
            working = false;
        }
    }

    workspace.addEventListener('click', (event) => {
        const action = event.target.closest('[data-seerr-action]');
        if (action) {
            void runAction(action);
            return;
        }
        const card = event.target.closest('[data-seerr-request]');
        if (card && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            opener = card;
            openerIndex = Array.from(queue.querySelectorAll('[data-seerr-request]')).indexOf(card);
            const current = { ...history.state, jellydashContentScrollTop: scrollArea.scrollTop };
            history.replaceState(current, '', queueUrl);
            history.pushState({ ...current, seerrFromQueue: true }, '', card.href);
            syncDetail();
        } else if (event.target.closest('[data-seerr-retry]')) {
            void loadQueue();
        }
    });

    workspace.addEventListener('error', (event) => {
        if (!event.target.matches?.('[data-seerr-poster] img')) return;
        const poster = event.target.parentElement;
        event.target.remove();
        poster.querySelector('.seerr-poster-fallback').hidden = false;
    }, true);

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!cancelFileRemoval() && !cancelEditor() && !cancelDeleteConfirmation()) closeDetail();
    });
    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && (cancelFileRemoval() || cancelEditor() || cancelDeleteConfirmation())) event.preventDefault();
    });
    closeButton.addEventListener('click', closeDetail);
    detail.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button || working) return;
        const source = button.closest('[data-seerr-action-request]');
        if (button.matches('[data-seerr-action]')) {
            void runAction(button);
        } else if (button.matches('[data-seerr-file-start]')) {
            void openFileRemoval(source);
        } else if (button.matches('[data-seerr-file-cancel]')) {
            cancelFileRemoval();
        } else if (button.matches('[data-seerr-edit-start]')) {
            void openEditor(source);
        } else if (button.matches('[data-seerr-edit-cancel]')) {
            cancelEditor();
        } else if (button.matches('[data-seerr-delete-start], [data-seerr-delete-cancel]')) {
            showDeleteConfirmation(source, button.matches('[data-seerr-delete-start]'));
        } else if (button.matches('[data-seerr-retry-detail]') && openId !== null) {
            closeButton.focus({ preventScroll: true });
            void loadDetail(openId);
        }
    });
    detail.addEventListener('change', (event) => {
        const form = event.target.closest('[data-seerr-edit-form]');
        if (!form || working) return;
        if (event.target.name === 'destination') {
            void changeDestination(form);
        } else {
            updateEditor(form);
        }
    });
    detail.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-seerr-edit-form]');
        if (!form) return;
        event.preventDefault();
        const save = form.closest('[data-seerr-editor]').querySelector('[data-seerr-save-edit]');
        if (!save.disabled) void runAction(save, form);
    });
    window.addEventListener('popstate', syncDetail);

    void loadQueue();
    syncDetail();
}());
