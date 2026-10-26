<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

(new \Mk\Framework\Jellyfin\PlayHistoryRepository())->logActiveStreams([]);
echo 'drained';
