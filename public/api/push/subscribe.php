<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Framework\Push\PushDeviceCapability;
use Mk\Framework\Push\PushSubscriptionLimitExceeded;
use Mk\Framework\Push\PushSubscriptionOwnershipException;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Push\PushSubscriptionValidator;

define('ROOT_DIR', dirname(__DIR__, 3));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

    return;
}

Csrf::checkHeader();

$authEnabled = Config::bool('AUTH_ENABLED', false);
$authorization = new Authorization();
if (!$authorization->can(Authorization::CAPABILITY_ENROLL_PUSH)) {
    http_response_code(403);
    echo json_encode(['error' => 'This account cannot register notification devices.']);

    return;
}
$verifiedUser = $authEnabled ? $authorization->verifiedUser() : null;
$userId = $verifiedUser !== null ? $verifiedUser['id'] : null;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $endpoint = is_array($body) ? (string) ($body['endpoint'] ?? '') : '';
    $keys = is_array($body) && isset($body['keys']) && is_array($body['keys']) ? $body['keys'] : [];
    $p256dh = (string) ($keys['p256dh'] ?? '');
    $auth = (string) ($keys['auth'] ?? '');

    if (!PushSubscriptionValidator::isValid($endpoint, $p256dh, $auth)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid subscription']);

        return;
    }

    $deviceCapabilityHash = (new PushDeviceCapability())->hashForEnrollment();
    (new PushSubscriptionRepository())->save(
        $endpoint,
        $p256dh,
        $auth,
        isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
        $deviceCapabilityHash,
        $userId,
        $authEnabled,
    );

    echo json_encode(['ok' => true]);
} catch (PushSubscriptionLimitExceeded $e) {
    http_response_code(409);
    echo json_encode(['error' => str_contains($e->getMessage(), 'account limit')
        ? 'This account has reached its notification device limit.'
        : 'This Jellydash install has reached its notification device limit.']);
} catch (PushSubscriptionOwnershipException) {
    http_response_code(409);
    echo json_encode(['error' => 'This notification device could not be associated with the current account.']);
} catch (\Throwable $e) {
    http_response_code(500);
    Log::logException($e);

    echo json_encode([
        'error' => 'Could not store subscription.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ]);
}
