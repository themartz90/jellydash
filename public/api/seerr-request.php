<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestManager;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$respond = static function (int $status, string $message, bool $refresh = false): never {
    http_response_code($status);
    echo json_encode(['message' => $message, 'refresh' => $refresh], JSON_THROW_ON_ERROR);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, 'Use POST to manage requests.');
}
if (!Csrf::validateHeader()) {
    $respond(419, 'Refresh the page and try again.');
}

$client = new JellyseerrClient(readTimeoutMilliseconds: 2_500);
try {
    $authorization = new Authorization();
    $allowed = static fn (): bool => $authorization->canManageSeerrRequests($client);
    if (!$allowed()) {
        $respond(403, 'Request management is unavailable for this dashboard or account.', true);
    }
} catch (Throwable $error) {
    Log::logException($error);
    $respond(403, 'Request management is unavailable for this dashboard or account.', true);
}

$raw = file_get_contents('php://input', length: 4097);
if ($raw === false || strlen($raw) > 4096) {
    $respond(413, 'The action is too large.');
}
try {
    $input = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_int($input['id'] ?? null) || !is_string($input['action'] ?? null)
        || !is_string($input['version'] ?? null)
        || (in_array($input['action'], ['delete', 'remove-files'], true) && ($input['confirmed'] ?? null) !== true)) {
        throw new InvalidArgumentException('Invalid request action.');
    }
    $changes = $input['edit'] ?? null;
    if ($input['action'] === 'remove-files') {
        $allowed = static fn (): bool => $authorization->canRemoveSeerrFiles($client);
        $changes = $input['fileToken'] ?? null;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    $result = (new RequestManager($client))->execute($input['action'], $input['id'], $input['version'], $allowed, $changes);
} catch (JsonException | InvalidArgumentException) {
    $respond(400, 'Choose valid request options. Confirm deletion before continuing.');
} catch (Throwable $error) {
    Log::logException($error);
    $respond(503, 'Could not complete the action. Check the current request before trying again.', true);
}

[$status, $message, $refresh] = match ($result) {
    'approved' => [200, 'Request approved.', true],
    'declined' => [200, 'Request declined.', true],
    'retried' => [200, 'Seerr accepted the retry.', true],
    'deleted' => [200, 'Request deleted. Library files were kept.', true],
    'files-removed' => [200, 'The library service removed the entry. The Seerr request was kept.', true],
    'files-removed-legacy' => [200, 'The library service removed the entry. The request was kept, and Jellyseerr may still show it as Available.', true],
    'file-target-changed' => [409, 'The library target could not be verified. Review the request again.', true],
    'edited' => [200, 'Request updated. It is still pending approval.', true],
    'observed-edited' => [200, 'Seerr now shows the saved changes. The request is still pending approval.', true],
    'unchanged' => [200, 'No changes to save.', false],
    'stale-options' => [409, 'The request or its available options changed. Open the editor again.', true],
    'invalid-edit' => [400, 'Choose one or more available seasons and valid destination settings.', false],
    'observed-approved' => [200, 'Seerr now shows an approved or completed request.', true],
    'observed-declined' => [200, 'Seerr now shows a declined request.', true],
    'observed-deleted' => [200, 'The request is now gone.', true],
    'missing' => [404, 'This request is no longer in Seerr.', true],
    'stale' => [409, 'This request changed. Review its current details before trying again.', true],
    'denied' => [403, 'Request management is unavailable for this dashboard or account.', true],
    'refused' => [409, 'Seerr refused the action. Check the current request.', true],
    'unavailable' => [503, 'Seerr did not provide current request details. Try again when it is available.', true],
    default => [503, 'The outcome is unknown. Check the current request before trying again.', true],
};
$respond($status, $message, $refresh);
