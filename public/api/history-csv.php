<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\HistoryCsvImporter;
use Mk\Framework\Jellyfin\HistoryCsvUpload;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    http_response_code(403);
    exit('Forbidden');
}

$csrfToken = $_POST[Csrf::fieldName()] ?? null;
if (!Csrf::validateHeader() && !Csrf::validate(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(419);
    exit('Invalid or missing CSRF token.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$commit = isset($_POST['commit']) && (string) $_POST['commit'] === '1';
$wantsStream = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'ndjson');

try {
    if (!$commit) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            (new HistoryCsvImporter())->previewFile(HistoryCsvUpload::path()),
            JSON_THROW_ON_ERROR,
        );
        return;
    }

    if ($wantsStream) {
        streamImport();
        return;
    }

    redirectImport((new HistoryCsvImporter())->importFile(HistoryCsvUpload::path()));
} catch (\JsonException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'error' => 'Could not encode the import preview.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ]);
} catch (\Throwable $e) {
    Log::logException($e);
    if ($commit && !$wantsStream) {
        redirectImportError($e->getMessage());
    }
    if (!$commit) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'error' => mb_substr($e->getMessage(), 0, 180),
            'detail' => Config::isDebug() ? $e->getMessage() : null,
        ]);
    }
}

function streamImport(): void
{
    set_time_limit(300);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $emit = static function (array $payload): void {
        echo json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
        flush();
    };

    try {
        $result = (new HistoryCsvImporter())->importFile(HistoryCsvUpload::path(), $emit);
    } catch (\Throwable $e) {
        Log::logException($e);
        $emit([
            'phase' => 'error',
            'error' => mb_substr($e->getMessage(), 0, 180),
            'processed' => 0,
            'total' => 0,
            'inserted' => 0,
            'skipped' => 0,
        ]);
        return;
    }

    $emit([
        'phase' => 'done',
        'processed' => $result['parsed'],
        'total' => $result['parsed'],
        'inserted' => $result['inserted'],
        'skipped' => $result['skipped'],
    ]);
}

/** @param array{parsed: int, inserted: int, skipped: int} $result */
function redirectImport(array $result): never
{
    header('Location: /settings?' . http_build_query([
        'imported' => $result['inserted'],
        'skipped' => $result['skipped'],
    ]));
    exit;
}

function redirectImportError(string $message): never
{
    header('Location: /settings?' . http_build_query([
        'import_error' => mb_substr($message, 0, 180),
    ]));
    exit;
}
