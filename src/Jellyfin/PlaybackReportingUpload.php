<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Validates the Settings-page Playback Reporting upload (TSV or SQLite).
 */
final class PlaybackReportingUpload
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public static function path(): string
    {
        $file = $_FILES['playback_reporting'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || !is_array($file) || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \RuntimeException('Drop a Playback Reporting TSV backup or playback_reporting.db first.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('The file is empty or larger than 20 MB.');
        }

        return (string) $file['tmp_name'];
    }
}
