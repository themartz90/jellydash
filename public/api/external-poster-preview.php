<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ExternalPosterManager;

define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';
header('Cache-Control: private, no-store');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    http_response_code(403);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
try {
    $asset = $_GET['asset'] ?? null;
    $token = $_GET['token'] ?? null;
    if (($asset === null) === ($token === null) || !is_string($asset ?? $token) || !preg_match('/^[a-f0-9]{64}$/D', $asset ?? $token)) {
        http_response_code(400);
        exit;
    }
    if ($asset !== null) {
        $saved = (new ArtworkRepository())->externalAsset($asset);
        if ($saved === null) {
            http_response_code(404);
            exit;
        }
        $image = ['body' => (string) $saved['body'], 'contentType' => (string) $saved['content_type']];
    } else {
        $image = (new ExternalPosterManager())->preview($token);
    }
    header('Content-Type: ' . $image['contentType']);
    echo $image['body'];
} catch (Throwable) {
    http_response_code(409);
}
