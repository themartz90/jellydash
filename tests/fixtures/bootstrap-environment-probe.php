<?php

declare(strict_types=1);

use Mk\Framework\Container;

require dirname(__DIR__) . '/bootstrap.php';

if (getenv('JELLYDASH_TEST_PROBE_CREATE_DB') === '1') {
    Container::db()->getDibi()->query('CREATE TABLE bootstrap_probe (id INTEGER NOT NULL)');
}

echo json_encode([
    'driver' => DATABASE_DRIVER_DIBI,
    'name' => DATABASE_NAME,
    'jellyfin' => getenv('JELLYFIN_URL'),
    'jellyseerr' => getenv('JELLYSEER_URL'),
], JSON_THROW_ON_ERROR);
