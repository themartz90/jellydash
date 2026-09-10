<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$filters = HistoryFilters::fromQuery($_GET);
if (isset($_GET['preview']) && (string) $_GET['preview'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        echo json_encode([
            'plays' => (new PlayHistoryRepository())->historyTotal($filters),
        ], JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        Log::logException($e);
        http_response_code(500);
        echo json_encode(['error' => 'Could not count matching History plays.'], JSON_THROW_ON_ERROR);
    }
    exit;
}

$output = fopen('php://temp/maxmemory:5242880', 'w+b');
if ($output === false) {
    http_response_code(500);
    exit('Could not prepare the History export.');
}

try {
    (new HistoryCsvExporter())->write($filters, $output);
    rewind($output);

    $size = fstat($output);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jellydash-history-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if (is_array($size) && isset($size['size'])) {
        header('Content-Length: ' . (int) $size['size']);
    }

    if (fpassthru($output) === false) {
        Log::logErrorMessage('History export output stopped before the response completed.', HistoryCsvExporter::class);
    }
} catch (\Throwable $e) {
    Log::logException($e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not export History.';
} finally {
    fclose($output);
}
