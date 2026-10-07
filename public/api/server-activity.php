<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Jellyfin\ServerActivityFilters;
use Mk\Framework\Jellyfin\ServerActivityService;

define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    http_response_code(403);
    echo json_encode(['error' => 'Server Activity is available to owners and admins.'], JSON_THROW_ON_ERROR);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Use GET to read Server Activity.'], JSON_THROW_ON_ERROR);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $section = $_GET['section'] ?? 'overview';
    if (!is_string($section) || !in_array($section, ['overview', 'activity'], true)) {
        throw new InvalidArgumentException('Choose a valid Server Activity section.');
    }
    $service = new ServerActivityService();
    $payload = $section === 'overview' ? $service->overview() : $service->activity(ServerActivityFilters::fromQuery($_GET));
    if (($payload['state'] ?? null) === 'expired') {
        http_response_code(409);
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['error' => 'Server Activity could not be loaded. Try refreshing shortly.'], JSON_THROW_ON_ERROR);
}
