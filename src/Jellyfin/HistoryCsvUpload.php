<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Validates a native Jellydash History CSV uploaded from Settings. */
final class HistoryCsvUpload
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public static function path(): string
    {
        $file = $_FILES['jellydash_history'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || !is_array($file) || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ImportValidationException('Choose a Jellydash History CSV first.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new ImportValidationException('The CSV is empty or larger than 20 MB.');
        }

        return (string) $file['tmp_name'];
    }
}
