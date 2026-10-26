<?php

declare(strict_types=1);

// Only the disposable parent fixture supplies this connection, through stdin.
$input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_array($input['config'] ?? null) || !is_string($input['marker'] ?? null)) {
    exit(2);
}
putenv('DB_DRIVER=' . $input['config']['driver']);
putenv('DB_NAME=' . $input['config']['database']);
require dirname(__DIR__) . '/bootstrap.php';
$database = new \Mk\Framework\Database(new \Dibi\Connection($input['config']));
$history = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
$history->prepareHistoryImport();
file_put_contents($input['marker'] . '.ready', 'ready');
$deadline = microtime(true) + 10;
while (!is_file($input['marker'] . '.go')) {
    if (microtime(true) >= $deadline) {
        exit(3);
    }
    usleep(10000);
    clearstatcache();
}
try {
    $history->logActiveStreams([[
    'id' => 'conflicting-new-play', 'itemId' => 'old', 'itemType' => 'Episode', 'itemName' => 'Episode',
    'library' => 'Movies', 'libraryResolved' => true, 'watchedSec' => 300, 'runtimeSec' => 3600,
    ]], new \DateTimeImmutable('2026-10-11 12:05:00'));
} catch (\Mk\Framework\Jellyfin\HistoryWriteBusyException) {
    file_put_contents($input['marker'] . '.queued', 'queued');
    exit;
}
file_put_contents($input['marker'] . '.done', 'done');
