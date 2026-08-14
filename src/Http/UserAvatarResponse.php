<?php

declare(strict_types=1);

namespace Mk\Framework\Http;

/**
 * Status and Cache-Control for proxied Jellyfin profile pictures.
 *
 * Avatars are personal and the proxy can sit behind auth, so browsers
 * must cache them privately (including the short-lived 404).
 */
final class UserAvatarResponse
{
    /**
     * @param array{body: string, contentType: string}|null $image
     * @return array{status: int, cacheControl: string, contentType: ?string, body: ?string}
     */
    public static function fromImage(?array $image): array
    {
        if ($image === null) {
            return [
                'status' => 404,
                'cacheControl' => 'private, max-age=300',
                'contentType' => null,
                'body' => null,
            ];
        }

        return [
            'status' => 200,
            'cacheControl' => 'private, max-age=3600',
            'contentType' => $image['contentType'],
            'body' => $image['body'],
        ];
    }
}
