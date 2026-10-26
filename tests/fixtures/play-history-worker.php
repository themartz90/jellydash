<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Jellyfin\PlayHistoryRepository;

require dirname(__DIR__) . '/bootstrap.php';

$repository = new PlayHistoryRepository();
$barrier = Config::get('TEST_PLAYBACK_BARRIER');
if ($barrier !== null) {
    $deadline = microtime(true) + 5;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "Timed out waiting for the playback barrier.\n");
            exit(1);
        }
        usleep(10_000);
    }
}

try {
    $repository->logActiveStreams([[
    'id' => 'shared-session',
    'itemId' => 'shared-item',
    'itemType' => 'Movie',
    'itemName' => 'Concurrent Playback',
    'playMethod' => 'DirectPlay',
    'watchedSec' => 30,
    'runtimeSec' => 3600,
    ]], new DateTimeImmutable('2026-08-11 12:00:00'));
} catch (\Mk\Framework\Jellyfin\HistoryWriteBusyException) {
    echo 'queued';
    exit;
}

echo 'ok';
