'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function node() {
    return { textContent: '', hidden: false, disabled: false, style: {}, attributes: {},
        addEventListener() {}, setAttribute(key, value) { this.attributes[key] = value; } };
}

const title = node();
const summary = node();
const confirm = node();
const dismiss = node();
const progress = node();
const progressLabel = node();
const bar = node();
const track = node();
const dialogNodes = {
    '[data-import-history-title]': title,
    '[data-import-history-summary]': summary,
    '[data-import-history-confirm]': confirm,
    '[data-import-history-progress]': progress,
    '.release-dialog-dismiss': dismiss,
};
const dialog = { ...node(), open: false,
    querySelector(selector) { return dialogNodes[selector] || null; },
    querySelectorAll() { return []; },
    showModal() { this.open = true; }, close() { this.open = false; },
};
const progressNodes = {
    '[data-import-history-progress]': [progress],
    '[data-import-history-progress-label]': [progressLabel],
    '[data-import-history-progress-bar]': [bar],
    '[data-import-history-progress-track]': [track],
};
const hooks = {};
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/history-import.js'), 'utf8'), {
    window: { JellydashFrontendTestHooks: hooks },
    TextDecoder,
    document: {
        querySelector(selector) { return selector === '[data-import-history-dialog]' ? dialog : null; },
        querySelectorAll(selector) { return progressNodes[selector] || []; },
    },
});
const api = hooks.importHistoryDialog();

for (const options of [{ source: 'plugin', kind: 'plugin' }, { source: 'file', kind: 'tsv' }, { source: 'file', kind: 'sqlite' }]) {
    api.openConfirm(options);
    api.setState('confirm', { ...options, count: 9, importable: 3, skipped: 4, conflicts: 2 });
    assert.equal(title.textContent, 'Import 3 plays?');
    assert.equal(confirm.textContent, 'Import 3 plays');
    assert.equal(confirm.disabled, false);
    assert.match(summary.textContent, /9 plays/);
    assert.match(summary.textContent, /4 will be skipped \(already present or excluded\)/);
    assert.match(summary.textContent, /2 need review and will not be imported/);
}

api.setState('confirm', { source: 'plugin', count: 2, importable: 0, skipped: 0, conflicts: 2 });
assert.equal(title.textContent, 'No new plays to import');
assert.equal(confirm.hidden, true);
assert.equal(confirm.disabled, true);
assert.match(summary.textContent, /2 need review and will not be imported/);
assert.doesNotMatch(summary.textContent, /already up to date|all.*already present/i);

api.setState('confirm', { source: 'file', count: 4, importable: 0, skipped: 4, conflicts: 0 });
assert.match(summary.textContent, /already present or excluded/);
assert.equal(confirm.disabled, true);

hooks.applyImportProgress({ phase: 'importing', processed: 6, total: 9, inserted: 2, skipped: 2, conflicts: 2 });
assert.equal(progressLabel.textContent, '6 of 9 plays checked, 2 need review');
assert.equal(bar.style.width, '67%');
assert.equal(track.attributes['aria-valuenow'], '67');

const result = { phase: 'done', processed: 9, total: 9, inserted: 3, skipped: 4, conflicts: 2, unresolved: 1 };
hooks.applyImportProgress(result);
api.setState('done', result);
assert.equal(progressLabel.textContent, 'Imported 3 plays, skipped 4, 2 need review and were not imported, 1 without Jellyfin runtime');
assert.equal(summary.textContent, progressLabel.textContent + '.');
assert.equal(bar.style.width, '100%');
assert.doesNotMatch(summary.textContent, /skipped 4 already present/);

api.setState('done', { inserted: 0, skipped: 0, conflicts: 2 });
assert.equal(summary.textContent, 'Imported 0 plays, 2 need review and were not imported.');

api.setState('confirm', { source: 'jellydash', count: 6, importable: 0, skipped: 6 });
assert.equal(title.textContent, 'History is already up to date');
assert.equal(summary.textContent, 'All 6 plays in this backup are already present.');
assert.equal(confirm.hidden, true);
api.setState('confirm', { source: 'jellydash', count: 6, importable: 2, skipped: 4 });
assert.equal(title.textContent, 'Import 2 plays?');
assert.match(summary.textContent, /4 already present will be skipped/);
assert.equal(confirm.disabled, false);

async function testImportStream() {
    const encoder = new TextEncoder();
    const stream = (...chunks) => new ReadableStream({
        async start(controller) {
            for (const chunk of chunks) {
                await new Promise((resolve) => setImmediate(resolve));
                controller.enqueue(encoder.encode(chunk));
            }
            controller.close();
        },
    });
    const progress = [];
    const importing = { phase: 'importing', processed: 6, total: 9, inserted: 2, skipped: 2 };
    const completed = await hooks.readImportNdjson(
        stream(JSON.stringify(importing) + '\n', JSON.stringify(result).slice(0, 8), JSON.stringify(result).slice(8)),
        (payload) => progress.push(payload.phase),
    );
    assert.equal(completed.inserted, 3);
    assert.deepEqual(progress, ['importing', 'done']);
    for (const broken of ['{"phase":"done"', 'null', '{"phase":"done"}', JSON.stringify(importing)]) {
        await assert.rejects(hooks.readImportNdjson(stream(broken)), /Import progress was interrupted. Check History/);
    }
    await assert.rejects(hooks.readImportNdjson(stream('{"phase":"error","error":"Choose a backup first."}')), /Choose a backup first/);
}

testImportStream().then(() => console.log('History import presentation tests passed.')).catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
