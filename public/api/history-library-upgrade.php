<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\HistoryLibraryBackfillService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'POST' && !Csrf::validateHeader()) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid or missing CSRF token.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'POST' && !(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden.'], JSON_THROW_ON_ERROR);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new HistoryLibraryBackfillService();
    $status = $method === 'POST' ? $service->runBatch() : $service->status();
    echo json_encode($status, JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    Log::logException($e);
    http_response_code(502);
    echo json_encode([
        'error' => 'Jellydash could not continue the History update. Your data is safe, and the update can resume later.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ], JSON_THROW_ON_ERROR);
}
