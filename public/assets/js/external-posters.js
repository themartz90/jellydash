(function () {
    'use strict';

    var panel = document.querySelector('[data-external-posters]');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!panel || !csrf || !csrf.content) return;

    var endpoint = '/api/external-posters.php';
    var page = 1;
    var pages = 1;
    var statusData = null;
    var busy = false;
    var stopped = false;
    var statusSequence = 0;
    var statusController = null;
    var pollTimer = null;
    var searchSequence = 0;
    var activeSearches = new Map();
    var groupStates = new Map();
    var openGroups = new Set();
    var previewQueue = [];
    var activePreviewRecords = new Set();
    var activePreviews = 0;

    function find(name) {
        return panel.querySelector('[data-external-' + name + ']');
    }

    function node(tag, text, className) {
        var element = document.createElement(tag);
        if (text !== undefined && text !== null) element.textContent = String(text);
        if (className) element.className = className;
        return element;
    }

    function button(label, action, key) {
        var element = node('button', label, 'settings-secondary-btn');
        element.type = 'button';
        element.dataset.externalAction = action;
        if (key) {
            element.dataset.externalKey = key;
            element.dataset.externalFocusKey = action + '-' + key;
        } else {
            element.dataset.externalFocusKey = action;
        }
        return element;
    }

    function errorMessage(body, fallback) {
        return body && typeof body.error === 'string' && body.error.trim() !== '' ? body.error : fallback;
    }

    function request(url, action, payload, signal) {
        var controller = new AbortController();
        var externalSignal = signal || null;
        var abortFromCaller = function () { controller.abort(); };
        if (externalSignal) {
            if (externalSignal.aborted) controller.abort();
            else externalSignal.addEventListener('abort', abortFromCaller, { once: true });
        }
        var timeout = window.setTimeout(function () { controller.abort(); }, 25000);
        signal = controller.signal;
        var options = { credentials: 'same-origin', cache: 'no-store', signal: signal };
        if (action) {
            options.method = 'POST';
            options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content };
            options.body = JSON.stringify(Object.assign({ action: action }, payload || {}));
        }
        return fetch(url, options).then(function (response) {
            if (response.status === 401 || response.status === 403 || response.status === 419) stopped = true;
            return response.json().catch(function () { throw new Error('Online poster status could not be read.'); }).then(function (body) {
                if (!response.ok) {
                    var error = new Error(errorMessage(body, 'The online poster request failed.'));
                    error.status = response.status;
                    throw error;
                }
                return body;
            });
        }).finally(function () {
            window.clearTimeout(timeout);
            if (externalSignal) externalSignal.removeEventListener('abort', abortFromCaller);
        });
    }

    function validStatus(data) {
        return data && typeof data === 'object'
            && typeof data.enabled === 'boolean'
            && Number.isInteger(data.configRevision)
            && data.source && typeof data.source === 'object'
            && Array.isArray(data.review) && Array.isArray(data.jobs) && Array.isArray(data.assets)
            && Number.isInteger(data.page) && Number.isInteger(data.pages);
    }

    function groupSignature(group) {
        return String(group.fingerprint || '') + '|' + (Array.isArray(group.ids) ? group.ids.join('|') : '');
    }

    function groupState(group) {
        var key = String(group.key || group.fingerprint || '');
        var signature = groupSignature(group);
        var current = groupStates.get(key);
        if (!current || current.signature !== signature) {
            if (current && current.controller) current.controller.abort();
            current = {
                signature: signature,
                selectedIds: Array.isArray(group.ids) && group.ids.length === 1 ? [String(group.ids[0])] : [],
                query: String(group.title || ''),
                candidates: [],
                chosenToken: '',
                searchError: '',
                resultSource: '',
                resultQuery: '',
                controller: null,
                sequence: 0,
            };
            groupStates.set(key, current);
        }
        return { key: key, state: current };
    }

    function clearGroupSearch(state) {
        if (state.controller) state.controller.abort();
        state.controller = null;
        state.candidates = [];
        state.chosenToken = '';
        state.searchError = '';
        state.resultSource = '';
        state.resultQuery = '';
    }

    function cancelSearches() {
        activeSearches.forEach(function (controller) { controller.abort(); });
        activeSearches.clear();
        groupStates.forEach(function (state) {
            if (state.controller) state.controller.abort();
            state.controller = null;
        });
    }

    function safePreviewUrl(value) {
        if (typeof value !== 'string' || value === '') return '';
        try {
            var url = new URL(value, window.location.href);
            return url.origin === window.location.origin ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function finishPreview(record, failed) {
        if (record.finished) return;
        record.finished = true;
        window.clearTimeout(record.timeout);
        record.image.onload = null;
        record.image.onerror = null;
        activePreviewRecords.delete(record);
        activePreviews = Math.max(0, activePreviews - 1);
        if (failed) {
            record.image.removeAttribute('src');
            record.image.hidden = true;
        }
        pumpPreviewQueue();
    }

    function pumpPreviewQueue() {
        activePreviewRecords.forEach(function (record) {
            if (!record.image.isConnected) {
                record.image.removeAttribute('src');
                finishPreview(record, false);
            }
        });
        previewQueue = previewQueue.filter(function (record) { return record.image.isConnected; });
        while (activePreviews < 2 && previewQueue.length > 0) {
            let record = previewQueue.shift();
            if (!record.image.isConnected) continue;
            activePreviews += 1;
            activePreviewRecords.add(record);
            record.image.loading = 'eager';
            record.image.onload = function () { finishPreview(record, false); };
            record.image.onerror = function () { finishPreview(record, true); };
            record.timeout = window.setTimeout(function () { finishPreview(record, true); }, 15000);
            record.image.src = record.url;
        }
    }

    function queuePreview(image, url) {
        if (!url) return;
        previewQueue.push({ image: image, url: url, timeout: null, finished: false });
    }

    function formatBytes(value) {
        var bytes = Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
        if (bytes < 1024) return Math.round(bytes) + ' bytes';
        var amount = bytes / 1024;
        var unit = 'KiB';
        if (amount >= 1024) {
            amount /= 1024;
            unit = 'MiB';
        }
        return amount.toFixed(amount >= 10 ? 0 : 1) + ' ' + unit;
    }

    function displayError(message) {
        var element = find('error');
        element.textContent = message || '';
        element.hidden = !message;
    }

    function sourceErrorText(error) {
        var messages = {
            source_changed: 'Jellyfin identity changed. Online recovery is paused until you review the connection.',
            server_changed: 'Jellyfin identity changed. Online recovery is paused until you review the connection.',
            request_failed: 'Jellyfin could not be reached. Saved online posters remain stored.',
            provider_unavailable: 'The online metadata provider is unavailable. You can retry later.',
            storage_limit: 'The storage limit prevented new saves. Existing saved posters are kept.',
            capacity: 'The storage limit prevented new saves. Existing saved posters are kept.',
        };
        return messages[String(error)] || 'Online poster recovery reported an operational issue. Saved copies remain stored.';
    }

    function renderNotices(data) {
        var notices = find('notices');
        notices.replaceChildren();
        if (data.source.blocked) {
            notices.appendChild(node('p', sourceErrorText(data.source.error || 'source_changed'), 'external-posters-warning is-blocked'));
        } else if (data.source.confirmed === false) {
            var legacy = node('p', 'Older History still needs a server identity check before online recovery can use it.', 'external-posters-warning');
            var link = node('a', 'Review existing saved posters');
            link.href = '#artwork-recovery';
            legacy.appendChild(document.createTextNode(' '));
            legacy.appendChild(link);
            notices.appendChild(legacy);
        } else if (data.source.error) {
            notices.appendChild(node('p', sourceErrorText(data.source.error), 'external-posters-warning'));
        }
        if (!data.enabled) {
            notices.appendChild(node('p', 'Online recovery is off. Saved online posters stay available until cleared or expired. No new provider requests will start.', 'external-posters-notice'));
        }
        if (Number(data.noticeCount) > 0) {
            notices.appendChild(node('p', Number(data.noticeCount) + ' poster ' + (Number(data.noticeCount) === 1 ? 'match needs' : 'matches need') + ' review.', 'external-posters-notice'));
        }
    }

    function renderGroup(group, index) {
        var details = node('details', null, 'external-posters-group');
        var keyState = groupState(group);
        var key = keyState.key;
        var state = keyState.state;
        details.dataset.externalGroup = key;
        details.open = openGroups.has(key);

        var summary = node('summary');
        summary.textContent = (group.title || 'Untitled media') + ' (' + (group.kind === 'Series' ? 'TV series' : 'Movie') + ')';
        details.appendChild(summary);

        var meta = node('p', null, 'external-posters-group-meta');
        meta.textContent = (group.library ? group.library + ' · ' : '') + Number(group.count || 0) + ' affected History item' + (Number(group.count) === 1 ? '' : 's');
        details.appendChild(meta);
        if (Number(group.snoozedUntil || 0) > Math.floor(Date.now() / 1000)) {
            details.appendChild(node('p', 'Skipped for 7 days. This choice is shared across managers.', 'external-posters-notice'));
        }
        if (Number(group.count || 0) > (Array.isArray(group.ids) ? group.ids.length : 0)) {
            details.appendChild(node('p', 'Showing the first ' + (Array.isArray(group.ids) ? group.ids.length : 0) + ' of ' + Number(group.count) + ' History items.', 'external-posters-group-meta'));
        }
        if (group.reason) details.appendChild(node('p', group.reason, 'external-posters-group-meta'));

        var itemLabels = new Map();
        (Array.isArray(group.items) ? group.items : []).forEach(function (item) {
            if (item && typeof item.id === 'string') itemLabels.set(item.id, String(item.title || item.id));
        });
        var choices = node('fieldset', null, 'external-posters-items');
        var legend = node('legend', group.ids && group.ids.length > 1 ? 'Choose the History items for this match' : 'Affected History item');
        choices.appendChild(legend);
        (Array.isArray(group.ids) ? group.ids : []).forEach(function (id, itemIndex) {
            id = String(id);
            var label = node('label', null, 'external-posters-item-choice');
            var input = node('input');
            input.type = 'checkbox';
            input.value = id;
            input.name = 'external-items-' + index;
            input.dataset.externalItemGroup = key;
            input.checked = state.selectedIds.indexOf(id) !== -1;
            label.appendChild(input);
            label.appendChild(node('span', itemLabels.get(id) || id));
            choices.appendChild(label);
        });
        details.appendChild(choices);

        var form = node('form', null, 'external-posters-search');
        form.dataset.externalSearch = key;
        var queryId = 'external-query-' + index;
        var queryLabel = node('label', 'Search title online');
        queryLabel.setAttribute('for', queryId);
        var query = node('input');
        query.type = 'search';
        query.id = queryId;
        query.name = 'query';
        query.maxLength = 160;
        query.value = state.query;
        query.dataset.externalQuery = key;
        queryLabel.appendChild(query);
        form.appendChild(queryLabel);
        var search = button('Search online', 'search', key);
        search.disabled = busy || !dataEnabled() || Boolean(statusData && statusData.source.blocked);
        form.appendChild(search);
        details.appendChild(form);

        if (group.discovery) {
            var discovered = group.discovery;
            var discoveryText = discovered.status === 'ready'
                ? Number(discovered.count) + (Number(discovered.count) === 1 ? ' suggestion' : ' suggestions') + ' found for the saved title. Choose affected items, then review the results.'
                : (discovered.status === 'empty' ? 'The automatic lookup returned no usable suggestions. Try another title or its original name.'
                    : (discovered.status === 'unavailable' ? 'The automatic lookup could not complete. It will retry later, or you can search by title.'
                        : (discovered.status === 'paused' ? 'Automatic title lookup is paused. You can resume recovery when ready.'
                            : (discovered.status === 'manual' ? 'This entry needs manual review. Search by title or its original name.'
                                : 'Automatic title lookup is waiting for a background check. You can also search now.'))));
            details.appendChild(node('p', discoveryText, 'external-posters-group-meta'));
            if (discovered.status === 'ready' && Number(discovered.count) > 0) {
                var reviewSuggestions = button('Review suggestions', 'suggestions', key);
                reviewSuggestions.disabled = busy || !dataEnabled() || Boolean(statusData && statusData.source.blocked);
                details.appendChild(reviewSuggestions);
            }
        }

        if (state.searchError) {
            var searchError = node('p', state.searchError, 'external-posters-error');
            searchError.dataset.externalSearchError = key;
            details.appendChild(searchError);
        }
        if (state.candidates.length) {
            if (state.resultSource === 'discovery') details.appendChild(node('p', 'Suggestions for saved title: ' + state.resultQuery + '. Confirm the correct title before saving.', 'external-posters-notice'));
            var results = node('div', null, 'external-posters-candidates');
            results.dataset.externalCandidates = key;
            state.candidates.forEach(function (candidate, candidateIndex) {
                var card = node('div', null, 'external-posters-candidate');
                var preview = safePreviewUrl(candidate.previewUrl);
                if (preview) {
                    var image = node('img');
                    image.alt = '';
                    image.dataset.externalPreview = key + '-' + candidateIndex;
                    queuePreview(image, preview);
                    card.appendChild(image);
                }
                var copy = node('div', null, 'external-posters-candidate-copy');
                var label = node('label', null, 'external-posters-candidate-choice');
                var radio = node('input');
                radio.type = 'radio';
                radio.name = 'external-candidate-' + index;
                radio.value = String(candidate.token || '');
                radio.dataset.externalCandidateGroup = key;
                radio.checked = state.chosenToken !== '' && state.chosenToken === radio.value;
                label.appendChild(radio);
                var title = String(candidate.title || 'Untitled result');
                var detail = title + (candidate.year ? ' (' + String(candidate.year) + ')' : '') + ' · ' + String(candidate.kind || group.kind || 'Result') + ' · TMDB';
                label.appendChild(node('span', detail));
                copy.appendChild(label);
                if (Array.isArray(candidate.recordedYears) && candidate.recordedYears.length > 0) {
                    var evidence = 'Recorded year' + (candidate.recordedYears.length === 1 ? '' : 's') + ': ' + candidate.recordedYears.join(', ')
                        + ' (' + Number(candidate.yearCoverage || 0) + ' of ' + Number(candidate.selectedCount || 0) + ' selected entries).';
                    if (candidate.year && candidate.recordedYears.indexOf(Number(candidate.year)) === -1) evidence += ' The result has a different year.';
                    copy.appendChild(node('small', evidence));
                }
                card.appendChild(copy);
                results.appendChild(card);
            });
            details.appendChild(results);
            var use = button('Use selected poster', 'select', key);
            use.disabled = busy || state.chosenToken === '' || state.selectedIds.length === 0;
            details.appendChild(use);
        } else if (state.searchError) {
            var retrySearch = button('Retry search', 'retry-search', key);
            retrySearch.disabled = busy;
            details.appendChild(retrySearch);
        }

        var actions = node('div', null, 'external-posters-actions');
        var snooze = button('Skip for 7 days', 'snooze', key);
        snooze.disabled = busy;
        snooze.setAttribute('aria-label', 'Skip this poster match for 7 days. This is shared across managers.');
        actions.appendChild(snooze);
        var allSuppressed = (group.ids || []).length > 0 && (group.ids || []).every(function (id) {
            return (statusData.jobs || []).some(function (job) { return job.state === 'suppressed' && job.ids.indexOf(id) !== -1; });
        });
        var suppress = button(allSuppressed ? 'Resume online recovery' : 'Keep placeholder', 'suppress', key);
        suppress.dataset.externalSuppressed = allSuppressed ? 'false' : 'true';
        suppress.disabled = busy;
        actions.appendChild(suppress);
        details.appendChild(actions);
        return details;
    }

    function dataEnabled() {
        return Boolean(statusData && statusData.enabled);
    }

    function renderJob(job, index) {
        var article = node('article', null, 'external-posters-job');
        var heading = String(job.title || job.selectedTitle || 'Selected poster');
        article.appendChild(node('h4', heading));
        var stateText = {
            pending: 'Saving poster…',
            saved: 'Poster saved.',
            suppressed: 'Keeping the placeholder for this item.',
            present: 'Jellyfin has its own poster, which keeps priority.',
            review: 'This match still needs review.',
            waiting: 'Waiting for the next automatic poster check.',
        }[job.state] || 'Online poster status is pending.';
        article.appendChild(node('p', stateText));
        if (job.selectedTitle) article.appendChild(node('small', 'Selected poster: ' + String(job.selectedTitle)));
        if (job.error) {
            var reasons = {
                throttled: 'The provider asked Jellydash to wait before trying again.',
                capacity: 'There is no room for another saved image.',
                request_failed: 'Jellyfin or the image provider could not be reached.',
                invalid_image: 'The downloaded image could not be validated.',
                oversized: 'The image exceeded the poster size limit.',
                decoder_missing: 'PHP needs GD enabled to validate posters.',
                no_exact_match: 'The provider did not return a single verified match.',
                selection_changed: 'The provider now returns a different poster. Review the match again.',
            };
            var prefix = job.state === 'waiting' ? 'Automatic recovery is delayed. '
                : (job.state === 'saved' ? 'The saved poster is available, but renewal is delayed. ' : 'The selected poster has not been saved yet. ');
            article.appendChild(node('p', prefix + (reasons[String(job.error)] || 'A later check will try again.'), 'external-posters-error'));
        }
        if (job.state === 'pending' && job.error) {
            var retry = button('Retry poster download', 'retry-job', 'job-' + index);
            retry.dataset.externalJob = String(index);
            article.appendChild(retry);
        }
        if (job.state === 'suppressed') {
            var resume = button('Resume online recovery', 'resume-job', 'job-' + index);
            resume.dataset.externalJob = String(index);
            article.appendChild(resume);
        }
        if (job.state === 'saved' || job.state === 'present' || job.state === 'pending') {
            var unlink = button('Remove match', 'unlink-job', 'job-' + index);
            unlink.dataset.externalJob = String(index);
            article.appendChild(unlink);
        }
        return article;
    }

    function renderAsset(asset, index) {
        var article = node('article', null, 'external-posters-asset');
        var row = node('div', null, 'external-posters-asset-row');
        var imageUrl = safePreviewUrl(asset.imageUrl);
        if (imageUrl) {
            var image = node('img', null, 'external-posters-asset-preview');
            image.alt = String(asset.title || 'Saved poster') + ' preview';
            queuePreview(image, imageUrl);
            row.appendChild(image);
        }
        var copy = node('div', null, 'external-posters-asset-copy');
        copy.appendChild(node('h4', asset.title || 'Saved online image'));
        var kind = asset.kind === 'Series' ? 'TV series' : 'Movie';
        copy.appendChild(node('small', kind + ' · TMDB ' + String(asset.providerId || '') + ' · ' + formatBytes(asset.bodySize) + ' · ' + Number(asset.refCount || 0) + ' linked title' + (Number(asset.refCount) === 1 ? '' : 's')));
        var clear = button('Clear image everywhere', 'clear-asset', 'asset-' + index);
        clear.dataset.externalAsset = String(index);
        copy.appendChild(clear);
        row.appendChild(copy);
        article.appendChild(row);
        return article;
    }

    function render(data, focusKey) {
        statusData = data;
        page = data.page;
        pages = Math.max(1, data.pages);
        var attention = Math.max(0, Number(data.noticeCount) || 0);
        var badge = document.querySelector('[data-external-review-badge]');
        var settingsLink = document.querySelector('[data-external-review-nav]');
        if (badge) {
            badge.hidden = attention === 0;
            badge.textContent = String(attention);
            badge.setAttribute('aria-label', attention + ' poster matches to review');
        }
        if (settingsLink) {
            settingsLink.href = attention > 0 ? '/settings#external-poster-review' : '/settings';
            settingsLink.setAttribute('aria-label', attention > 0 ? 'Settings, posters need review' : 'Settings');
        }
        find('enabled').checked = data.enabled;
        find('enabled').disabled = busy;
        find('status').textContent = data.enabled ? 'Online poster recovery is enabled.' : 'Online poster recovery is disabled.';
        renderNotices(data);

        var review = find('review');
        review.replaceChildren();
        data.review.forEach(function (group, index) { review.appendChild(renderGroup(group, index)); });
        find('review-wrap').hidden = data.review.length === 0;
        find('review-count').textContent = data.noticeCount ? '(' + Number(data.noticeCount) + ')' : '';

        var jobs = find('jobs');
        jobs.replaceChildren();
        data.jobs.forEach(function (job, index) { jobs.appendChild(renderJob(job, index)); });
        find('jobs-wrap').hidden = data.jobs.length === 0;
        find('jobs-count').textContent = data.jobs.length ? '(' + data.jobs.length + ')' : '';

        var assets = find('assets');
        assets.replaceChildren();
        data.assets.forEach(function (asset, index) { assets.appendChild(renderAsset(asset, index)); });
        find('assets-wrap').hidden = data.assets.length === 0;
        find('assets-count').textContent = data.assets.length ? '(' + data.assets.length + ')' : '';

        var pager = find('pager');
        pager.hidden = pages <= 1;
        find('page').textContent = 'Page ' + page + ' of ' + pages;
        find('prev').disabled = busy || page <= 1;
        find('next').disabled = busy || page >= pages;
        if (focusKey) {
            var focusTarget = Array.from(panel.querySelectorAll('[data-external-focus-key]')).find(function (element) {
                return element.dataset.externalFocusKey === focusKey;
            });
            if (focusTarget && typeof focusTarget.focus === 'function') focusTarget.focus();
        }
        pumpPreviewQueue();
    }

    function loadStatus(nextPage, focusKey) {
        if (stopped) return Promise.resolve();
        if (statusController) statusController.abort();
        statusController = new AbortController();
        var controller = statusController;
        var sequence = ++statusSequence;
        return request(endpoint + '?page=' + encodeURIComponent(String(nextPage || page)), null, null, controller.signal).then(function (data) {
            if (sequence !== statusSequence || !validStatus(data)) return;
            render(data, focusKey);
            displayError('');
        }).catch(function (error) {
            if (error.name === 'AbortError') return;
            displayError(error.message || 'Online poster status could not be loaded.');
        });
    }

    function schedulePoll() {
        if (pollTimer !== null) window.clearTimeout(pollTimer);
        pollTimer = null;
        if (stopped) return;
        pollTimer = window.setTimeout(function () {
            pollTimer = null;
            if (document.hidden) return;
            if (!busy && activeSearches.size === 0 && !panel.contains(document.activeElement)) loadStatus(page);
            schedulePoll();
        }, 30000);
    }

    function setBusy(value) {
        busy = value;
        panel.querySelectorAll('button').forEach(function (control) { control.disabled = value; });
        find('enabled').disabled = value;
    }

    function mutate(action, payload, focusKey) {
        if (busy || stopped) return Promise.resolve();
        setBusy(true);
        cancelSearches();
        displayError('');
        return request(endpoint + '?page=' + encodeURIComponent(String(page)), action, payload).then(function (data) {
            if (!validStatus(data)) throw new Error('Online poster status could not be read.');
            if (action === 'select') {
                var selectedGroup = statusData && statusData.review.find(function (group) { return group.fingerprint === payload.fingerprint; });
                if (selectedGroup) clearGroupSearch(groupState(selectedGroup).state);
            }
            setBusy(false);
            render(data, focusKey);
        }).catch(function (error) {
            setBusy(false);
            if (error.status === 401 || error.status === 403 || error.status === 419) stopped = true;
            if (statusData) render(statusData, focusKey);
            displayError(error.message || 'The online poster request failed.');
        });
    }

    function selectedIds(group, state) {
        return (Array.isArray(group.ids) ? group.ids : []).filter(function (id) { return state.selectedIds.indexOf(String(id)) !== -1; });
    }

    function searchGroup(key, retry, useSuggestions) {
        if (busy || stopped || !statusData || !statusData.enabled || statusData.source.blocked) return;
        var group = statusData.review.find(function (item) { return String(item.key || item.fingerprint || '') === key; });
        var current = group && groupState(group).state;
        if (!group || !current) return;
        var ids = selectedIds(group, current);
        if (ids.length === 0) {
            current.searchError = 'Choose at least one History item before searching.';
            render(statusData, 'search-' + key);
            return;
        }
        if (!useSuggestions && !current.query.trim()) {
            current.searchError = 'Enter a title to search.';
            render(statusData, 'search-' + key);
            return;
        }
        clearGroupSearch(current);
        var controller = new AbortController();
        var sequence = ++searchSequence;
        current.controller = controller;
        current.sequence = sequence;
        activeSearches.set(key, controller);
        current.searchError = '';
        var payload = {
            ids: ids,
            fingerprint: group.fingerprint,
            page: page,
        };
        if (!useSuggestions) {
            payload.query = current.query.trim().slice(0, 160);
            payload.kind = group.kind;
        }
        request(endpoint, useSuggestions ? 'suggestions' : 'search', payload, controller.signal).then(function (response) {
            if (current.sequence !== sequence || !panel.isConnected) return;
            if (!response || response.fingerprint !== group.fingerprint || !Array.isArray(response.candidates)) {
                throw new Error('This History group changed. Refresh and open it again.');
            }
            current.candidates = response.candidates.filter(function (candidate) {
                return candidate && typeof candidate.token === 'string' && candidate.token !== '';
            });
            current.chosenToken = '';
            current.resultSource = response.origin || 'manual';
            current.resultQuery = response.queryTitle || current.query;
            current.searchError = current.candidates.length ? '' : 'No usable posters were found. Edit the title or try again.';
            render(statusData, 'search-' + key);
        }).catch(function (error) {
            if (error.name === 'AbortError' || current.sequence !== sequence) return;
            current.searchError = error.message || 'The online search failed. You can retry it.';
            render(statusData, 'search-' + key);
        }).finally(function () {
            if (current.sequence === sequence) current.controller = null;
            if (activeSearches.get(key) === controller) activeSearches.delete(key);
        });
    }

    function handleSubmit(event) {
        var form = event.target.closest && event.target.closest('form[data-external-search]');
        if (!form || !panel.contains(form)) return;
        event.preventDefault();
        if (busy) return;
        searchGroup(form.dataset.externalSearch, false);
    }

    function handleInput(event) {
        var target = event.target;
        if (!target || !target.dataset || !target.dataset.externalQuery) return;
        var group = statusData && statusData.review.find(function (item) { return String(item.key || item.fingerprint || '') === target.dataset.externalQuery; });
        if (!group) return;
        var state = groupState(group).state;
        if (state.query !== target.value) {
            state.query = target.value.slice(0, 160);
            clearGroupSearch(state);
            activeSearches.delete(target.dataset.externalQuery);
            var details = target.closest('details[data-external-group]');
            if (details) {
                var results = details.querySelector('[data-external-candidates]');
                if (results) results.replaceChildren();
                var oldError = details.querySelector('[data-external-search-error]');
                if (oldError) oldError.remove();
                var useButton = details.querySelector('[data-external-action="select"]');
                if (useButton) useButton.disabled = true;
            }
        }
    }

    function handleChange(event) {
        var target = event.target;
        if (!target || !target.dataset) return;
        if (target.matches && target.matches('[data-external-enabled]')) {
            if (!statusData || busy) {
                target.checked = statusData ? statusData.enabled : true;
                return;
            }
            mutate('configure', { enabled: target.checked, configRevision: statusData.configRevision }, 'enabled');
            return;
        }
        if (target.dataset.externalItemGroup) {
            var itemGroup = statusData.review.find(function (group) { return String(group.key || group.fingerprint || '') === target.dataset.externalItemGroup; });
            if (!itemGroup) return;
            var itemState = groupState(itemGroup).state;
            var itemId = String(target.value);
            if (target.checked && itemState.selectedIds.indexOf(itemId) === -1) itemState.selectedIds.push(itemId);
            if (!target.checked) itemState.selectedIds = itemState.selectedIds.filter(function (id) { return id !== itemId; });
            clearGroupSearch(itemState);
            activeSearches.delete(target.dataset.externalItemGroup);
            var groupDetails = target.closest('details[data-external-group]');
            if (groupDetails) {
                var candidateArea = groupDetails.querySelector('[data-external-candidates]');
                if (candidateArea) candidateArea.replaceChildren();
                var oldError = groupDetails.querySelector('[data-external-search-error]');
                if (oldError) oldError.remove();
                var chooseButton = groupDetails.querySelector('[data-external-action="select"]');
                if (chooseButton) chooseButton.disabled = true;
            }
            return;
        }
        if (target.dataset.externalCandidateGroup) {
            var selectedGroup = statusData.review.find(function (group) { return String(group.key || group.fingerprint || '') === target.dataset.externalCandidateGroup; });
            if (!selectedGroup) return;
            groupState(selectedGroup).state.chosenToken = String(target.value);
            var candidateDetails = target.closest('details[data-external-group]');
            var selectedButton = candidateDetails && candidateDetails.querySelector('[data-external-action="select"]');
            if (selectedButton) selectedButton.disabled = busy || !target.value;
            return;
        }
    }

    function handleClick(event) {
        var control = event.target.closest && event.target.closest('[data-external-action]');
        if (!control || !panel.contains(control) || control.disabled || busy) return;
        var action = control.dataset.externalAction;
        var focusKey = control.dataset.externalFocusKey || action;
        if (action === 'prev' || action === 'next') {
            var destination = action === 'prev' ? Math.max(1, page - 1) : Math.min(pages, page + 1);
            if (destination !== page) cancelSearches();
            loadStatus(destination, focusKey);
            return;
        }
        if (action === 'search' || action === 'retry-search') {
            searchGroup(control.dataset.externalKey, action === 'retry-search');
            return;
        }
        if (action === 'suggestions') {
            searchGroup(control.dataset.externalKey, false, true);
            return;
        }
        if (action === 'select') {
            var group = statusData.review.find(function (item) { return String(item.key || item.fingerprint || '') === control.dataset.externalKey; });
            if (!group) return;
            var state = groupState(group).state;
            var ids = selectedIds(group, state);
            if (!state.chosenToken || ids.length === 0) return;
            mutate('select', { candidateToken: state.chosenToken, ids: ids, fingerprint: group.fingerprint, page: page }, focusKey);
            return;
        }
        if (action === 'snooze' || action === 'suppress') {
            var reviewGroup = statusData.review.find(function (item) { return String(item.key || item.fingerprint || '') === control.dataset.externalKey; });
            if (!reviewGroup) return;
            var payload = { ids: reviewGroup.ids, fingerprint: reviewGroup.fingerprint, page: page };
            if (action === 'suppress') payload.suppressed = control.dataset.externalSuppressed === 'true';
            mutate(action, payload, focusKey);
            return;
        }
        if (action === 'clear-asset') {
            var asset = statusData.assets[Number(control.dataset.externalAsset)];
            if (!asset) return;
            var referenceCount = Math.max(0, Number(asset.refCount || 0));
            var scope = referenceCount + ' linked title' + (referenceCount === 1 ? '' : 's');
            if (!window.confirm('Clear this saved image for ' + scope + '? The shared bytes will be removed and external recovery will pause for every linked title.')) return;
            mutate('clearAsset', { assetKey: asset.key, fingerprint: asset.fingerprint }, focusKey);
            return;
        }
        if (action === 'retry-job' || action === 'unlink-job' || action === 'resume-job') {
            var job = statusData.jobs[Number(control.dataset.externalJob)];
            if (!job) return;
            var jobAction = action === 'retry-job' ? 'retry' : (action === 'unlink-job' ? 'unlink' : 'suppress');
            var jobPayload = { ids: job.ids, fingerprint: job.fingerprint, page: page };
            if (action === 'resume-job') jobPayload.suppressed = false;
            mutate(jobAction, jobPayload, focusKey);
            return;
        }
    }

    panel.addEventListener('click', handleClick);
    panel.addEventListener('submit', handleSubmit);
    panel.addEventListener('input', handleInput);
    panel.addEventListener('change', handleChange);
    panel.addEventListener('toggle', function (event) {
        var details = event.target;
        if (!details || !details.dataset || !details.dataset.externalGroup) return;
        if (details.open) openGroups.add(details.dataset.externalGroup);
        else openGroups.delete(details.dataset.externalGroup);
    }, true);
    document.addEventListener('visibilitychange', function () {
        if (pollTimer !== null) window.clearTimeout(pollTimer);
        pollTimer = null;
        if (!document.hidden) schedulePoll();
    });
    window.addEventListener('pagehide', function () {
        stopped = true;
        if (statusController) statusController.abort();
        cancelSearches();
        if (pollTimer !== null) window.clearTimeout(pollTimer);
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        stopped = false;
        loadStatus(page).finally(schedulePoll);
    });
    function openLinkedReview() {
        if (window.location.hash === '#external-poster-review') find('review-wrap').open = true;
    }
    window.addEventListener('hashchange', openLinkedReview);
    openLinkedReview();

    loadStatus(1).finally(schedulePoll);
})();
