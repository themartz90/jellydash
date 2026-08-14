<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Builds /api/image.php URLs for Jellyfin profile pictures.
 *
 * A stored user id is enough: the browser hits the proxy, which 404s when
 * there is no photo (initials stay). Rows without an id keep initials so
 * History never waits on Jellyfin.
 */
final class JellyfinUserAvatars
{
    public function url(?string $userId, int $maxWidth = 80): ?string
    {
        return self::proxyUrl((string) $userId, '', $maxWidth);
    }

    public static function proxyUrl(string $userId, string $imageTag = '', int $maxWidth = 80): ?string
    {
        $userId = trim($userId);
        if ($userId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $userId)) {
            return null;
        }

        $url = '/api/image.php?user=' . rawurlencode($userId)
            . '&maxWidth=' . max(32, min(256, $maxWidth));

        $imageTag = trim($imageTag);
        if ($imageTag !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $imageTag)) {
            $url .= '&tag=' . rawurlencode($imageTag);
        }

        return $url;
    }
}
