<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Http\ImageContentType;
use Mk\Framework\Http\UserAvatarResponse;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

// Read-only: release the session lock so parallel poster loads don't serialize.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$itemId = (string) ($_GET['item'] ?? '');
$userId = (string) ($_GET['user'] ?? '');
$type = (string) ($_GET['type'] ?? 'Backdrop');
$maxWidth = (int) ($_GET['maxWidth'] ?? 1280);
// kind=series resolves an episode to its parent series so we can show the
// series poster instead of the per-episode still.
$kind = (string) ($_GET['kind'] ?? '');

$baseUrl = rtrim((string) Config::get('JELLYFIN_URL', ''), '/');
$token = (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));

if ($baseUrl === '' || $token === '') {
    http_response_code(404);
    exit;
}

$verifySsl = Config::bool('JELLYFIN_VERIFY_SSL', true);

if ($userId !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $userId)) {
        http_response_code(400);
        exit;
    }

    $url = $baseUrl . '/Users/' . rawurlencode($userId) . '/Images/Primary'
        . '?maxWidth=' . max(32, min(256, $maxWidth > 0 ? $maxWidth : 80));
    $tag = (string) ($_GET['tag'] ?? '');
    if ($tag !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
        $url .= '&tag=' . rawurlencode($tag);
    }

    $image = fetchJellyfinImage($url, $token, $verifySsl);
    $response = UserAvatarResponse::fromImage($image);
    header('Cache-Control: ' . $response['cacheControl']);
    http_response_code($response['status']);
    if ($response['body'] === null || $response['contentType'] === null) {
        exit;
    }

    header('Content-Type: ' . $response['contentType']);
    echo $response['body'];
    exit;
}

if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
    http_response_code(400);
    exit;
}

$allowedTypes = ['Backdrop', 'Thumb', 'Primary'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'Backdrop';
}

$fallbackTypes = array_values(array_unique([$type, 'Backdrop', 'Thumb', 'Primary']));

// Resolve episode -> series so TV history rows show the series poster.
// On any failure we keep the original item id (falls back to its own image).
if ($kind === 'series') {
    $lookupHandle = curl_init($baseUrl . '/Items?Ids=' . rawurlencode($itemId));
    if ($lookupHandle !== false) {
        curl_setopt_array($lookupHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: MediaBrowser Token="' . $token . '"',
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        $lookupBody = curl_exec($lookupHandle);
        $lookupStatus = (int) curl_getinfo($lookupHandle, CURLINFO_RESPONSE_CODE);
        curl_close($lookupHandle);

        if ($lookupBody !== false && $lookupStatus >= 200 && $lookupStatus < 300) {
            $decoded = json_decode((string) $lookupBody, true);
            $first = is_array($decoded) && is_array($decoded['Items'][0] ?? null) ? $decoded['Items'][0] : null;
            $seriesId = $first !== null ? (string) ($first['SeriesId'] ?? '') : '';

            if ($seriesId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $seriesId)) {
                $itemId = $seriesId;
                // For a series we only want the poster, not a landscape fallback.
                $fallbackTypes = ['Primary'];
            }
        }
    }
}

foreach ($fallbackTypes as $imageType) {
    $url = $baseUrl . '/Items/' . rawurlencode($itemId) . '/Images/' . $imageType
        . '?maxWidth=' . max(100, min(2000, $maxWidth));

    $image = fetchJellyfinImage($url, $token, $verifySsl);
    if ($image === null) {
        continue;
    }

    header('Content-Type: ' . $image['contentType']);
    header('Cache-Control: public, max-age=300');
    echo $image['body'];
    exit;
}

http_response_code(404);

/**
 * @return array{body: string, contentType: string}|null
 */
function fetchJellyfinImage(string $url, string $token, bool $verifySsl): ?array
{
    $handle = curl_init($url);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: MediaBrowser Token="' . $token . '"',
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($response === false) {
        Log::logErrorMessage('Jellyfin image request failed: ' . $error, 'image.php');

        return null;
    }

    if ($status < 200 || $status >= 300) {
        return null;
    }

    $body = substr((string) $response, $headerSize);
    $safeContentType = ImageContentType::normalize($contentType);
    if ($body === '' || $safeContentType === null) {
        return null;
    }

    return [
        'body' => $body,
        'contentType' => $safeContentType,
    ];
}
