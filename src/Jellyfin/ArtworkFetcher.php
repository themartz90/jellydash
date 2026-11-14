<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Http\ImageContentType;

/** Downloads a bounded Primary rendition from the configured Jellyfin only. */
final class ArtworkFetcher
{
    public function __construct(private ?string $baseUrl = null, private ?string $token = null)
    {
    }

    /** @return array{body: string, contentType: string} */
    public function fetch(string $id, ?string $tag = null): array
    {
        ArtworkRepository::id($id);
        $base = rtrim($this->baseUrl ?? (string) Config::get('JELLYFIN_URL', ''), '/');
        $token = $this->token ?? (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));
        if ($base === '' || $token === '') {
            throw new \RuntimeException('configuration');
        }
        $query = 'maxWidth=480&maxHeight=720&quality=85';
        if ($tag !== null && preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $tag)) {
            $query .= '&tag=' . rawurlencode($tag);
        }
        $handle = curl_init($base . '/Items/' . rawurlencode($id) . '/Images/Primary?' . $query);
        if ($handle === false) {
            throw new \RuntimeException('request_failed');
        }
        $body = '';
        $tooLarge = false;
        $verify = Config::bool('JELLYFIN_VERIFY_SSL', true);
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => ['Authorization: MediaBrowser Token="' . $token . '"'],
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > ArtworkRepository::MAX_IMAGE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);
        if ($tooLarge) {
            throw new \RuntimeException('oversized');
        }
        if (in_array($status, [401, 403], true)) {
            throw new \RuntimeException('authentication');
        }
        if ($status === 404) {
            throw new \RuntimeException('missing_image');
        }
        if ($status === 429) {
            throw new \RuntimeException('throttled');
        }
        if ($success === false || $status !== 200) {
            throw new \RuntimeException('request_failed');
        }
        return self::validate($body, $type);
    }

    /** @return array{body: string, contentType: string} */
    public static function validate(string $body, string $contentType): array
    {
        $mime = ImageContentType::normalize($contentType);
        $size = $body !== '' && strlen($body) <= ArtworkRepository::MAX_IMAGE_BYTES ? @getimagesizefromstring($body) : false;
        if ($size === false || $mime === null || $size['mime'] !== $mime
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 2048 || $size[1] > 2048 || $size[0] * $size[1] > 2097152) {
            throw new \RuntimeException('invalid_image');
        }
        $gifFrames = $mime === 'image/gif' ? self::gifFrames($body) : 0;
        $animatedPng = false;
        $complete = match ($mime) {
            'image/jpeg' => str_starts_with($body, "\xff\xd8") && str_ends_with($body, "\xff\xd9"),
            'image/gif' => $gifFrames > 0,
            'image/png' => self::completePng($body, $animatedPng),
            'image/webp' => strlen($body) >= 20 && substr($body, 0, 4) === 'RIFF'
                && unpack('Vlength', substr($body, 4, 4))['length'] === strlen($body) - 8,
            default => false,
        };
        if (!$complete) {
            throw new \RuntimeException('invalid_image');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('decoder_missing');
        }
        $decoded = @imagecreatefromstring($body);
        if ($decoded === false) {
            throw new \RuntimeException('invalid_image');
        }
        $animatedWebp = $mime === 'image/webp' && (str_contains($body, 'ANIM') || str_contains($body, 'ANMF'));
        if ($gifFrames > 1 || $animatedPng || $animatedWebp) {
            // Retain one decoded still, never unvalidated later animation frames.
            ob_start();
            imagepng($decoded);
            $still = ob_get_clean();
            if ($still === false || strlen($still) > ArtworkRepository::MAX_IMAGE_BYTES) {
                imagedestroy($decoded);
                throw new \RuntimeException('oversized');
            }
            $body = $still;
            $mime = 'image/png';
        }
        imagedestroy($decoded);
        return ['body' => $body, 'contentType' => $mime];
    }

    private static function completePng(string $body, bool &$animated): bool
    {
        $offset = 8;
        $hasData = false;
        while ($offset + 12 <= strlen($body)) {
            $length = unpack('Nlength', substr($body, $offset, 4))['length'];
            if ($length > strlen($body) - $offset - 12) {
                return false;
            }
            $chunk = substr($body, $offset + 4, $length + 4);
            if (hash('crc32b', $chunk, true) !== substr($body, $offset + 8 + $length, 4)) {
                return false;
            }
            $type = substr($chunk, 0, 4);
            $animated = $animated || in_array($type, ['acTL', 'fcTL', 'fdAT'], true);
            $hasData = $hasData || $type === 'IDAT';
            $offset += $length + 12;
            if ($type === 'IEND') {
                return $length === 0 && $hasData && $offset === strlen($body);
            }
        }
        return false;
    }

    private static function gifFrames(string $body): int
    {
        if (strlen($body) < 14) {
            return 0;
        }
        $packed = ord($body[10]);
        $offset = 13 + (($packed & 128) !== 0 ? 3 * (2 ** (($packed & 7) + 1)) : 0);
        $screen = unpack('vwidth/vheight', substr($body, 6, 4));
        $frames = 0;
        while ($offset < strlen($body)) {
            $marker = ord($body[$offset++]);
            if ($marker === 59) {
                return $offset === strlen($body) ? $frames : 0;
            }
            if ($marker === 33) {
                ++$offset;
            } elseif ($marker === 44 && $offset + 9 < strlen($body)) {
                $frame = unpack('vleft/vtop/vwidth/vheight', substr($body, $offset, 8));
                if ($frame['width'] < 1 || $frame['height'] < 1 || $frame['width'] > 2048 || $frame['height'] > 2048
                    || $frame['width'] * $frame['height'] > 2097152
                    || $frame['left'] + $frame['width'] > $screen['width']
                    || $frame['top'] + $frame['height'] > $screen['height']) {
                    return 0;
                }
                $packed = ord($body[$offset + 8]);
                $offset += 9 + (($packed & 128) !== 0 ? 3 * (2 ** (($packed & 7) + 1)) : 0);
                if ($offset >= strlen($body) || ord($body[$offset]) < 2 || ord($body[$offset]) > 8) {
                    return 0;
                }
                ++$offset;
                ++$frames;
            } else {
                return 0;
            }
            do {
                if ($offset >= strlen($body)) {
                    return 0;
                }
                $length = ord($body[$offset++]);
                $offset += $length;
                if ($offset > strlen($body)) {
                    return 0;
                }
            } while ($length !== 0);
        }
        return 0;
    }
}
