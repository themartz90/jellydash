<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Log;

final class ImportFailure
{
    public static function message(\Throwable $error): string
    {
        if ($error instanceof ImportValidationException) {
            return mb_substr($error->getMessage(), 0, 180);
        }

        Log::logException($error);

        return 'History import failed. Check the application log for details.';
    }
}
