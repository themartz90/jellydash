<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ExternalPosterManager;

define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'Method not allowed.']);
}
if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    $respond(403, ['error' => 'Only a manager can review online posters.']);
}
if ($method === 'POST' && !Csrf::validateHeader()) {
    $respond(419, ['error' => 'Refresh the page and try again.']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
try {
    if (isset($_GET['page']) && (!is_string($_GET['page']) || !preg_match('/^[0-9]{1,7}$/D', $_GET['page']))) {
        throw new InvalidArgumentException('Invalid page.');
    }
    $page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input', length: 16385);
        if ($raw === false || strlen($raw) > 16384) {
            $respond(413, ['error' => 'The request is too large.']);
        }
        $input = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['action'] ?? null)
            || !in_array($input['action'], ['configure', 'search', 'suggestions', 'select', 'snooze', 'suppress', 'unlink', 'retry', 'clearAsset'], true)) {
            throw new InvalidArgumentException('Invalid poster action.');
        }
        if (isset($input['page']) && (!is_int($input['page']) || $input['page'] < 1 || $input['page'] > 1000000)) {
            throw new InvalidArgumentException('Invalid page.');
        }
        $page = $input['page'] ?? $page;
        if ($input['action'] === 'configure') {
            if (!is_bool($input['enabled'] ?? null) || !is_int($input['configRevision'] ?? null) || $input['configRevision'] < 1) {
                throw new InvalidArgumentException('Invalid setting.');
            }
        } else {
            if (!is_string($input['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['fingerprint'])) {
                throw new InvalidArgumentException('Invalid poster selection.');
            }
            if ($input['action'] === 'clearAsset') {
                if (!is_string($input['assetKey'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['assetKey'])) {
                    throw new InvalidArgumentException('Invalid saved poster.');
                }
            } else {
                if (!is_array($input['ids'] ?? null) || !array_is_list($input['ids']) || $input['ids'] === [] || count($input['ids']) > 100) {
                    throw new InvalidArgumentException('Choose up to 100 historical items.');
                }
                foreach ($input['ids'] as $id) {
                    if (!is_string($id)) {
                        throw new InvalidArgumentException('Invalid historical item.');
                    }
                    ArtworkRepository::id($id);
                }
                if (count(array_unique($input['ids'])) !== count($input['ids'])) {
                    throw new InvalidArgumentException('Duplicate historical items.');
                }
            }
        }
        if ($input['action'] === 'search' && (!is_string($input['query'] ?? null) || !is_string($input['kind'] ?? null)
            || (isset($input['year']) && !is_int($input['year'])))) {
            throw new InvalidArgumentException('Invalid search.');
        }
        if ($input['action'] === 'select' && (!is_string($input['candidateToken'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['candidateToken']))) {
            throw new InvalidArgumentException('Invalid poster choice.');
        }
        if ($input['action'] === 'suppress' && !is_bool($input['suppressed'] ?? null)) {
            throw new InvalidArgumentException('Invalid poster action.');
        }
    }
    $manager = new ExternalPosterManager();
    if ($method === 'POST') {
        $repository = new ArtworkRepository();
        if ($input['action'] === 'search') {
            $respond(200, $manager->search($input['ids'], $input['fingerprint'], $input['query'], $input['kind'], $input['year'] ?? null));
        }
        if ($input['action'] === 'suggestions') {
            $respond(200, $manager->suggestions($input['ids'], $input['fingerprint']));
        }
        match ($input['action']) {
            'configure' => $repository->configureExternal($input['enabled'], $input['configRevision']),
            'select' => $manager->select($input['ids'], $input['fingerprint'], $input['candidateToken']),
            'clearAsset' => $repository->clearExternalAsset($input['assetKey'], $input['fingerprint']),
            default => $manager->act($input['ids'], $input['fingerprint'], $input['action'], $input['suppressed'] ?? true),
        };
    }
    $respond(200, $manager->status($page));
} catch (JsonException | InvalidArgumentException) {
    $respond(400, ['error' => 'The poster request could not be read. Refresh and try again.']);
} catch (Throwable) {
    $respond(409, ['error' => 'The poster request could not be completed. Recovery may be paused, Jellyfin may be unavailable, or the selection may have changed. Refresh and try again.']);
}
