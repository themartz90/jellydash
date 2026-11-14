<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\ArtworkManager;
use Mk\Framework\Jellyfin\ArtworkRepository;

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
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
};
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'Method not allowed.']);
}
if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    $respond(403, ['error' => 'Only a manager can review saved posters.']);
}
if ($method === 'POST' && !Csrf::validateHeader()) {
    $respond(419, ['error' => 'Refresh the page and try again.']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
try {
    foreach (['page', 'search', 'kind'] as $key) {
        if (isset($_GET[$key]) && !is_string($_GET[$key])) {
            throw new InvalidArgumentException('Invalid query.');
        }
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
            || !in_array($input['action'], ['backfill', 'retry', 'link', 'unlink', 'clear', 'resume'], true)) {
            throw new InvalidArgumentException('Choose a poster action.');
        }
        if (in_array($input['action'], ['link', 'unlink'], true)) {
            if (!is_array($input['ids'] ?? null) || !array_is_list($input['ids']) || $input['ids'] === [] || count($input['ids']) > 100
                || !is_string($input['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['fingerprint'])) {
                throw new InvalidArgumentException('Choose a current History group.');
            }
            foreach ($input['ids'] as $id) {
                if (!is_string($id)) {
                    throw new InvalidArgumentException('Invalid History item.');
                }
                ArtworkRepository::id($id);
            }
        }
        foreach ($input['action'] === 'link' ? ['targetId'] : (in_array($input['action'], ['clear', 'resume'], true) ? ['id'] : []) as $key) {
            if (!is_string($input[$key] ?? null)) {
                throw new InvalidArgumentException('Invalid poster.');
            }
            ArtworkRepository::id($input[$key]);
        }
        if (in_array($input['action'], ['clear', 'resume'], true) && (!is_int($input['revision'] ?? null) || $input['revision'] < 1)) {
            throw new InvalidArgumentException('Refresh the poster selection.');
        }
    }
    $manager = new ArtworkManager();
    if ($method === 'GET' && isset($_GET['search'])) {
        $respond(200, ['candidates' => $manager->search($_GET['search'], $_GET['kind'] ?? 'Series')]);
    }
    if ($method === 'POST') {
        $repository = new ArtworkRepository();
        match ($input['action']) {
            'backfill' => $manager->confirmHistory(),
            'retry' => $repository->retryAll(),
            'link' => $manager->link($input['ids'], $input['fingerprint'], $input['targetId']),
            'unlink' => $repository->matchItems($input['ids'], null, $input['fingerprint']),
            'clear' => $repository->changePoster($input['id'], $input['revision'], true),
            'resume' => $repository->changePoster($input['id'], $input['revision'], false),
        };
    }
    $respond(200, $manager->status($page));
} catch (JsonException | InvalidArgumentException) {
    $respond(400, ['error' => 'The poster selection could not be read. Refresh and try again.']);
} catch (Throwable) {
    $respond(409, ['error' => 'The poster could not be updated. Jellyfin may be unavailable or the selection may have changed. Refresh and try again.']);
}
