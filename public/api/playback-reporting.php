<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\PlaybackReportingClient;
use Mk\Framework\Jellyfin\PlaybackReportingImporter;
use Mk\Framework\Jellyfin\PlaybackReportingUpload;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST[Csrf::fieldName()] ?? null;
    if (!Csrf::validateHeader() && !Csrf::validate(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(419);
        exit('Invalid or missing CSRF token.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $commit = isset($_POST['commit']) && (string) $_POST['commit'] === '1';
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsStream = str_contains($accept, 'ndjson');

    try {
        if ($commit) {
            if ($wantsStream) {
                streamImport();
            } else {
                redirectImport(runImport());
            }
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(previewImport(), JSON_THROW_ON_ERROR);
        }
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

    return;
}

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$probePlugin = isset($_GET['probe']);
$historyEmpty = true;

try {
    $historyEmpty = (new PlayHistoryRepository())->totalRows() === 0;
} catch (\Throwable $e) {
    Log::logException($e);
}

$available = false;
$importable = false;
$broken = false;
$helpUrl = null;
if ($probePlugin || $historyEmpty) {
    try {
        $status = (new PlaybackReportingClient())->status($probePlugin);
        $available = $status['available'];
        $importable = $status['importable'];
        $broken = $status['broken'];
        $helpUrl = $status['help_url'];
    } catch (\Throwable $e) {
        Log::logException($e);
    }
}

try {
    echo json_encode([
        'available' => $available,
        'importable' => $importable,
        'broken' => $broken,
        'help_url' => $helpUrl,
        'history_empty' => $historyEmpty,
    ], JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Could not encode playback reporting status.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ]);
}

/**
 * @return array{parsed: int, kind: 'tsv'|'sqlite'|'plugin'}
 */
function previewImport(): array
{
    $importer = new PlaybackReportingImporter();
    $source = (string) ($_POST['import_source'] ?? 'file');

    if ($source === 'plugin') {
        return $importer->previewPlugin();
    }

    return $importer->previewFile(PlaybackReportingUpload::path());
}

/**
 * @return array{parsed: int, inserted: int, skipped: int, unresolved: int}
 */
function runImport(): array
{
    set_time_limit(300);
    $importer = new PlaybackReportingImporter();
    $source = (string) ($_POST['import_source'] ?? 'file');

    if ($source === 'plugin') {
        return $importer->importFromPlugin();
    }

    $tmp = PlaybackReportingUpload::path();

    return $importer->importFile($tmp, false, $importer->detectKind($tmp));
}

function streamImport(): void
{
    set_time_limit(300);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $importer = new PlaybackReportingImporter();
    $source = (string) ($_POST['import_source'] ?? 'file');
    $emit = static function (array $payload): void {
        echo json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
        flush();
    };

    try {
        if ($source === 'plugin') {
            $result = $importer->importFromPlugin(false, $emit);
        } else {
            $tmp = PlaybackReportingUpload::path();
            $result = $importer->importFile($tmp, false, $importer->detectKind($tmp), $emit);
        }
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

    if ($result['parsed'] === 0) {
        $emit([
            'phase' => 'error',
            'error' => 'No playback rows found. Use a Playback Reporting TSV backup or playback_reporting.db.',
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
        'unresolved' => $result['unresolved'],
    ]);
}

/**
 * @param array{parsed: int, inserted: int, skipped: int, unresolved?: int} $result
 */
function redirectImport(array $result): never
{
    if ($result['parsed'] === 0) {
        redirectImportError('No playback rows found. Use a Playback Reporting TSV backup or playback_reporting.db.');
    }

    $query = [
        'imported' => $result['inserted'],
        'skipped' => $result['skipped'],
    ];
    if (($result['unresolved'] ?? 0) > 0) {
        $query['unresolved'] = $result['unresolved'];
    }

    header('Location: /settings?' . http_build_query($query));
    exit;
}

function redirectImportError(string $message): never
{
    header('Location: /settings?' . http_build_query([
        'import_error' => mb_substr($message, 0, 180),
    ]));
    exit;
}
