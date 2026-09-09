<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Health\StatusService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    echo json_encode((new StatusService())->snapshot(), JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    try {
        Log::logException($error);
    } catch (\Throwable) {
    }
    http_response_code(503);
    echo json_encode(['error' => 'System status is unavailable.'], JSON_THROW_ON_ERROR);
}
