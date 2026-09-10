<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Framework\Push\PlaybackNotifier;
use Mk\Framework\Push\PushDeviceCapability;
use Mk\Framework\Push\PushSubscriptionRepository;

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
$body = json_decode((string) file_get_contents('php://input'), true);
$scope = is_array($body) ? (string) ($body['scope'] ?? 'current') : 'current';
if (!in_array($scope, ['current', 'all'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid notification test scope.']);

    return;
}
$requiredCapability = $scope === 'all'
    ? Authorization::CAPABILITY_MANAGE_GLOBAL
    : Authorization::CAPABILITY_ENROLL_PUSH;
if (!$authorization->can($requiredCapability)) {
    http_response_code(403);
    echo json_encode(['error' => 'This account cannot send that notification test.']);

    return;
}
$verifiedUser = $authEnabled ? $authorization->verifiedUser() : null;
$userId = $verifiedUser !== null ? $verifiedUser['id'] : null;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if ($scope === 'all') {
        echo json_encode((new PlaybackNotifier())->sendTest());

        return;
    }

    $capabilityHash = (new PushDeviceCapability())->existingHash();
    $subscription = $capabilityHash !== null
        ? (new PushSubscriptionRepository())->currentSubscription($capabilityHash, $userId, $authEnabled)
        : null;
    if ($subscription === null) {
        http_response_code(404);
        echo json_encode(['error' => 'No current notification device was found.']);

        return;
    }
    echo json_encode((new PlaybackNotifier())->sendCurrentDeviceTest($subscription));
} catch (\Throwable $e) {
    http_response_code(500);
    Log::logException($e);

    echo json_encode([
        'error' => 'Could not send test notification.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ]);
}
