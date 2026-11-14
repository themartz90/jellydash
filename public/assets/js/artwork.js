(function () {
    'use strict';

    var panel = document.querySelector('[data-artwork-recovery]');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!panel || !csrf || !csrf.content) return;

    var page = 1;
    var pages = 1;
    var timer = null;
    var statusRequest = null;
    var statusSequence = 0;
    var busy = false;
    var stopped = false;
    var suspended = false;
    var activeSearches = new Map();
    var groupsByKey = new Map();

    function find(name) {
        return panel.querySelector('[data-artwork-' + name + ']');
    }

    function node(tag, text, className) {
        var element = document.createElement(tag);
        if (text !== undefined && text !== null) element.textContent = String(text);
        if (className) element.className = className;
        return element;
    }

    function button(label, action, focusKey, className) {
        var element = node('button', label, className || 'settings-secondary-btn');
        element.type = 'button';
        element.dataset.artworkAction = action;
        element.dataset.artworkFocusKey = focusKey;
        return element;
    }

    function request(url, action, payload, signal) {
        var timeout = null;
        var controller = null;
        if (!signal) {
            controller = new AbortController();
            signal = controller.signal;
            timeout = window.setTimeout(function () { controller.abort(); }, 25000);
        }
        var options = { credentials: 'same-origin', cache: 'no-store', signal: signal };
        if (action) {
            options.method = 'POST';
            options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content };
            options.body = JSON.stringify(Object.assign({ action: action }, payload || {}));
        }
        return fetch(url, options).then(function (response) {
            if (response.status === 401 || response.status === 403 || response.status === 419) stopped = true;
            return response.json().catch(function () { throw new Error('Saved poster status could not be read.'); }).then(function (body) {
                if (!response.ok) {
                    var error = new Error(typeof body.error === 'string' ? body.error : 'Saved poster request failed.');
                    error.status = response.status;
                    throw error;
                }
                return body;
            });
        }).finally(function () {
            if (timeout !== null) window.clearTimeout(timeout);
        });
    }

    function validStatus(data) {
        return data && typeof data === 'object'
            && data.source && typeof data.source === 'object'
            && data.summary && typeof data.summary === 'object'
            && Array.isArray(data.groups) && Array.isArray(data.posters)
            && Number.isInteger(data.page) && Number.isInteger(data.pages);
    }

    function formatBytes(value) {
        var bytes = Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
        if (bytes < 1024) return Math.round(bytes) + ' bytes';
        var units = ['KiB', 'MiB', 'GiB'];
        var amount = bytes / 1024;
        var unit = 0;
        while (amount >= 1024 && unit < units.length - 1) {
            amount /= 1024;
            unit += 1;
        }
        return amount.toFixed(amount >= 10 ? 0 : 1) + ' ' + units[unit];
    }

    function cancelSearches() {
        activeSearches.forEach(function (entry) { entry.controller.abort(); });
        activeSearches.clear();
    }

    function renderNotices(source) {
        var notices = find('notices');
        notices.replaceChildren();
        if (source.blocked) {
            notices.appendChild(node('p', 'Jellyfin identity changed. Saved posters are protected and will not be served or updated until you review the connection.', 'artwork-warning is-blocked'));
        } else if (!source.configured) {
            notices.appendChild(node('p', 'Jellyfin is not configured. Saved posters stay on this installation until a Jellyfin server is configured.', 'artwork-warning'));
        }
        if (Number(source.otherSources) > 0) {
            var count = Number(source.otherSources) || 0;
            notices.appendChild(node('p', 'Saved posters from ' + count + ' other Jellyfin ' + (count === 1 ? 'address stay' : 'addresses stay') + ' separate. Jellydash will not link them automatically.', 'artwork-warning'));
        }
        if (source.error && source.error !== 'server_changed') {
            var errors = {
                configuration: 'Jellyfin is not configured. Check the server settings before retrying.',
                decoder_missing: 'Poster saving needs PHP GD enabled on this installation.',
                authentication: 'Jellyfin rejected the request. Check its API credentials before retrying.',
                throttled: 'Jellyfin is limiting requests. Checks will retry later.',
                request_failed: 'The last poster check failed. Checks will retry later.',
                capacity: 'The storage limit prevented new saves. Existing saved posters are kept.',
            };
            notices.appendChild(node('p', errors[source.error] || 'The last poster check failed. You can retry it here.', 'artwork-warning'));
        }
        if (source.legacyConfirmed === false) {
            find('legacy').hidden = false;
        } else {
            find('legacy').hidden = true;
        }
        var retry = find('retry');
        retry.hidden = !source.configured;
        retry.disabled = Boolean(source.blocked || !source.configured || busy);
        find('backfill').disabled = Boolean(source.blocked || !source.configured || busy);
    }

    function setError(error) {
        var message = find('error');
        message.textContent = error && error.name === 'AbortError'
            ? 'The request took too long. Try again.'
            : (error && error.message ? error.message : 'Saved posters could not be loaded. Try again.');
        message.hidden = false;
    }

    function restoreFocus(key) {
        var controls = panel.querySelectorAll('[data-artwork-focus-key]');
        for (var index = 0; index < controls.length; index += 1) {
            if (controls[index].dataset.artworkFocusKey === key && !controls[index].disabled) {
                controls[index].focus({ preventScroll: true });
                return;
            }
        }
        document.getElementById('artwork-recovery-title').focus({ preventScroll: true });
    }

    function renderSummary(data) {
        var summary = data.summary;
        find('saved').textContent = String(Number(summary.saved) || 0);
        find('pending').textContent = String(Number(summary.pending) || 0);
        find('review').textContent = String(Number(summary.review) || 0);
        find('storage').textContent = formatBytes(summary.bytes) + ' of ' + formatBytes(summary.limitBytes);
        find('summary').hidden = false;
        var pending = Number(summary.pending) || 0;
        find('status').textContent = pending > 0
            ? pending + (pending === 1 ? ' poster check is pending.' : ' poster checks are pending.')
            : 'Poster status updated.';
    }

    function renderGroups(groups) {
        var container = find('groups');
        groupsByKey.clear();
        container.replaceChildren();
        find('groups-count').textContent = '(' + groups.length + ')';
        find('groups-wrap').hidden = groups.length === 0;
        groups.forEach(function (group, index) {
            var key = 'group-' + index;
            groupsByKey.set(key, group);
            var card = node('article', '', 'artwork-group');
            var heading = node('h3', group.title || 'Untitled group');
            card.appendChild(heading);
            var isEpisode = group.kind === 'Episode';
            var mediaPlural = isEpisode ? 'episodes' : 'movies';
            var mediaSingular = isEpisode ? 'episode' : 'movie';
            var mediaKind = isEpisode ? 'TV show' : 'Movie';
            card.appendChild(node('p', (group.library || 'Unknown library') + ' · ' + mediaKind + ' · ' + group.count + ' ' + (group.count === 1 ? mediaSingular : mediaPlural)));
            if (group.count > group.ids.length) {
                card.appendChild(node('p', 'This links the first ' + group.ids.length + ' of ' + group.count + ' ' + mediaPlural + '. Search again later to review the next batch.'));
            }
            card.appendChild(node('p', 'Linking changes artwork matching only. The original plays stay in History.'));

            var form = node('form', '', 'artwork-search');
            form.dataset.artworkSearch = 'true';
            form.dataset.artworkGroupKey = key;
            var label = node('label', 'Search Jellyfin for a poster');
            var input = node('input');
            input.type = 'search';
            input.name = 'search';
            input.maxLength = 160;
            input.autocomplete = 'off';
            input.required = true;
            input.value = truncateSearchTitle(group.title);
            label.appendChild(input);
            var searchButton = node('button', 'Search', 'settings-secondary-btn');
            searchButton.type = 'submit';
            form.appendChild(label);
            form.appendChild(searchButton);
            card.appendChild(form);
            var results = node('div', '', 'artwork-results');
            results.setAttribute('aria-live', 'polite');
            results.dataset.artworkResults = key;
            card.appendChild(results);
            container.appendChild(card);
        });
    }

    function truncateSearchTitle(value) {
        var title = String(value || '');
        if (title.length > 160 && title.charCodeAt(159) >= 0xD800 && title.charCodeAt(159) <= 0xDBFF) {
            return title.slice(0, 159);
        }
        return title.slice(0, 160);
    }

    function renderCandidates(results, candidates, group, key) {
        results.replaceChildren();
        if (candidates.length === 0) {
            results.appendChild(node('p', 'No matching posters found. Try another search.'));
            return;
        }
        candidates.slice(0, 20).forEach(function (candidate) {
            if (!candidate || typeof candidate.id !== 'string' || candidate.id === '') return;
            var row = node('div', '', 'artwork-candidate');
            var image = node('img');
            image.alt = '';
            image.loading = 'lazy';
            image.src = '/api/image.php?item=' + encodeURIComponent(candidate.id) + '&type=Primary&maxWidth=120';
            row.appendChild(image);
            var copy = node('div', '', 'artwork-candidate-copy');
            copy.appendChild(node('strong', candidate.title || candidate.id));
            if (candidate.year) copy.appendChild(node('small', String(candidate.year)));
            var use = button('Use this poster', 'link', 'group:' + key);
            use.dataset.artworkGroupKey = key;
            use.dataset.artworkTargetId = candidate.id;
            use.setAttribute('aria-label', 'Use ' + (candidate.title || candidate.id) + ' for ' + (group.title || 'this group'));
            copy.appendChild(use);
            row.appendChild(copy);
            results.appendChild(row);
        });
    }

    function renderPosters(posters) {
        var container = find('posters');
        container.replaceChildren();
        find('posters-count').textContent = '(' + posters.length + ')';
        if (posters.length === 0) {
            container.appendChild(node('p', 'No posters are listed on this page.', 'artwork-status'));
            return;
        }
        posters.forEach(function (poster) {
            if (!poster || typeof poster.id !== 'string') return;
            var card = node('article', '', 'artwork-poster');
            var row = node('div', '', 'artwork-poster-row');
            if (poster.hasImage) {
                var image = node('img');
                image.alt = '';
                image.loading = 'lazy';
                image.src = '/api/image.php?item=' + encodeURIComponent(poster.id) + '&type=Primary&maxWidth=120&retained=1&direct=1';
                row.appendChild(image);
            }
            var copy = node('div', '', 'artwork-poster-copy');
            copy.appendChild(node('strong', poster.title || poster.id));
            copy.appendChild(node('small', poster.kind === 'Series' ? 'Series poster' : 'Movie poster'));
            copy.appendChild(node('small', poster.hasImage ? 'Saved poster' : 'No saved image yet'));
            if (Array.isArray(poster.manualIds) && poster.manualIds.length > 0) {
                copy.appendChild(node('small', poster.manualIds.length + (poster.manualIds.length === 1 ? ' History match' : ' History matches')));
            }
            row.appendChild(copy);
            card.appendChild(row);
            var actions = node('div', '', 'artwork-row-actions');
            if (poster.suppressed) {
                actions.appendChild(button('Resume saving', 'resume', 'poster:' + poster.id));
            } else {
                actions.appendChild(button('Clear saved poster', 'clear', 'poster:' + poster.id));
            }
            actions.lastChild.dataset.artworkPosterId = poster.id;
            actions.lastChild.dataset.artworkRevision = String(poster.revision);
            if (Array.isArray(poster.manualIds) && poster.manualIds.length > 0) {
                var unlink = button('Remove match', 'unlink', 'poster:' + poster.id + ':match');
                unlink.dataset.artworkPosterId = poster.id;
                unlink.dataset.artworkFingerprint = String(poster.manualFingerprint || '');
                unlink.dataset.artworkIds = JSON.stringify(poster.manualIds);
                actions.appendChild(unlink);
            }
            card.appendChild(actions);
            container.appendChild(card);
        });
    }

    function render(data, force) {
        if (!force && panel.contains(document.activeElement)) return;
        cancelSearches();
        renderSummary(data);
        renderNotices(data.source);
        renderGroups(data.groups);
        renderPosters(data.posters);
        page = data.page;
        pages = Math.max(1, data.pages);
        var pager = find('pager');
        pager.hidden = pages <= 1;
        find('page').textContent = 'Page ' + page + ' of ' + pages;
        find('prev').disabled = page <= 1;
        find('next').disabled = page >= pages;
        find('prev').dataset.artworkPage = String(Math.max(1, page - 1));
        find('next').dataset.artworkPage = String(Math.min(pages, page + 1));
        find('error').hidden = true;
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        timer = null;
        if (stopped || suspended || document.hidden) return;
        timer = window.setTimeout(poll, delay);
    }

    function loadStatus(options) {
        options = options || {};
        if (statusRequest) statusRequest.abort();
        statusRequest = new AbortController();
        var controller = statusRequest;
        var sequence = ++statusSequence;
        var url = '/api/artwork.php?page=' + encodeURIComponent(options.page || page);
        return request(url, null, null, controller.signal).then(function (data) {
            if (sequence !== statusSequence) return null;
            if (!validStatus(data)) throw new Error('Saved poster status returned an incomplete response.');
            page = data.page;
            if (options.focusKey) {
                render(data, true);
                restoreFocus(options.focusKey);
            } else {
                render(data, Boolean(options.force));
            }
            return data;
        }).finally(function () {
            if (statusRequest === controller) statusRequest = null;
        });
    }

    function poll() {
        timer = null;
        if (document.hidden || busy || activeSearches.size > 0 || panel.contains(document.activeElement)) {
            schedule(30000);
            return;
        }
        loadStatus().catch(function (error) {
            if (error.name !== 'AbortError') setError(error);
        }).finally(function () { schedule(30000); });
    }

    function mutate(action, payload, focusKey, trigger) {
        if (busy) return Promise.resolve();
        busy = true;
        statusSequence += 1;
        if (statusRequest) statusRequest.abort();
        statusRequest = null;
        window.clearTimeout(timer);
        cancelSearches();
        find('error').hidden = true;
        if (trigger) trigger.disabled = true;
        return request('/api/artwork.php?page=' + encodeURIComponent(page), action, payload).then(function (data) {
            if (!validStatus(data)) throw new Error('The saved poster action returned incomplete status.');
            render(data, true);
            restoreFocus(focusKey);
        }).catch(function (error) {
            setError(error);
        }).finally(function () {
            busy = false;
            renderNotices(findCurrentSource());
            if (trigger && trigger.isConnected) trigger.disabled = false;
            schedule(30000);
        });
    }

    function findCurrentSource() {
        return currentStatus ? currentStatus.source : { configured: true, blocked: false, legacyConfirmed: true };
    }

    var currentStatus = null;
    var renderOriginal = render;
    render = function (data, force) {
        currentStatus = data;
        return renderOriginal(data, force);
    };

    function search(group, key, query, results) {
        var clean = query.trim();
        if (!clean) {
            results.replaceChildren(node('p', 'Enter a title to search Jellyfin.'));
            return;
        }
        statusSequence += 1;
        if (statusRequest) statusRequest.abort();
        statusRequest = null;
        var previous = activeSearches.get(key);
        if (previous) previous.controller.abort();
        var entry = { controller: new AbortController(), sequence: previous ? previous.sequence + 1 : 1 };
        activeSearches.set(key, entry);
        results.replaceChildren(node('p', 'Searching Jellyfin…', 'artwork-status'));
        var kind = group.kind === 'Episode' ? 'Series' : 'Movie';
        var url = '/api/artwork.php?search=' + encodeURIComponent(clean) + '&kind=' + encodeURIComponent(kind);
        request(url, null, null, entry.controller.signal).then(function (data) {
            if (activeSearches.get(key) !== entry) return;
            if (!data || !Array.isArray(data.candidates)) throw new Error('Jellyfin search returned an incomplete response.');
            renderCandidates(results, data.candidates, group, key);
        }).catch(function (error) {
            if (error.name === 'AbortError' || activeSearches.get(key) !== entry) return;
            results.replaceChildren(node('p', error.message || 'Jellyfin search failed.', 'artwork-error'));
        }).finally(function () {
            if (activeSearches.get(key) === entry) activeSearches.delete(key);
        });
    }

    panel.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-artwork-search]');
        if (!form || !panel.contains(form)) return;
        event.preventDefault();
        if (busy) return;
        var key = form.dataset.artworkGroupKey;
        var group = groupsByKey.get(key);
        var input = form.querySelector('input[name="search"]');
        var results = panel.querySelector('[data-artwork-results="' + key + '"]');
        if (group && input && results) search(group, key, input.value, results);
    });

    panel.addEventListener('click', function (event) {
        var target = event.target;
        var control = target.closest('button');
        if (!control || !panel.contains(control) || control.disabled || busy) return;
        var action = control.dataset.artworkAction;
        var focusKey = control.dataset.artworkFocusKey || 'title';
        if (action === 'clear' && !window.confirm('Clear this saved poster? Jellydash will stop saving it until you choose Resume saving.')) return;
        if (action === 'link') {
            var groupKey = control.dataset.artworkGroupKey;
            var group = groupsByKey.get(groupKey);
            if (!group) return;
            mutate('link', { ids: group.ids.slice(0, 100), fingerprint: group.fingerprint, targetId: control.dataset.artworkTargetId }, focusKey, control);
        } else if (action === 'clear' || action === 'resume') {
            mutate(action, { id: control.dataset.artworkPosterId, revision: Number(control.dataset.artworkRevision) }, focusKey, control);
        } else if (action === 'unlink') {
            var ids;
            try { ids = JSON.parse(control.dataset.artworkIds || '[]'); } catch (error) { ids = []; }
            mutate('unlink', { ids: ids, fingerprint: control.dataset.artworkFingerprint }, focusKey, control);
        } else if (action === 'backfill' || action === 'retry') {
            mutate(action, {}, focusKey, control);
        } else if (control.dataset.artworkPage) {
            var requestedPage = Number(control.dataset.artworkPage);
            if (!Number.isInteger(requestedPage) || requestedPage < 1 || requestedPage > pages) return;
            loadStatus({ page: requestedPage, focusKey: focusKey }).catch(function (error) {
                if (error.name !== 'AbortError') setError(error);
            });
        } else if (action === 'refresh') {
            loadStatus({ focusKey: focusKey, force: true }).catch(function (error) {
                if (error.name !== 'AbortError') setError(error);
            });
        }
    });

    find('backfill').dataset.artworkAction = 'backfill';
    find('backfill').dataset.artworkFocusKey = 'backfill';
    find('retry').dataset.artworkAction = 'retry';
    find('retry').dataset.artworkFocusKey = 'retry';
    find('refresh').dataset.artworkAction = 'refresh';
    find('refresh').dataset.artworkFocusKey = 'refresh';
    find('prev').dataset.artworkPage = String(Math.max(1, page - 1));
    find('prev').dataset.artworkFocusKey = 'previous';
    find('next').dataset.artworkPage = String(page + 1);
    find('next').dataset.artworkFocusKey = 'next';

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearTimeout(timer);
            timer = null;
        } else {
            schedule(1000);
        }
    });
    window.addEventListener('pagehide', function () {
        suspended = true;
        window.clearTimeout(timer);
        timer = null;
        if (statusRequest) statusRequest.abort();
        cancelSearches();
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        suspended = false;
        schedule(1000);
    });

    loadStatus().catch(function (error) {
        if (error.name !== 'AbortError') setError(error);
    }).finally(function () { schedule(30000); });
}());
