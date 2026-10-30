<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\AppSettings;

final class MinimumViewingTime
{
    public const MAX_SECONDS = 2_147_483_647;

    public static function seconds(): int
    {
        return self::parse(AppSettings::get('minimum_viewing_seconds')) ?? 0;
    }

    public static function parse(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) !== 1) {
            return null;
        }

        return (int) $value <= self::MAX_SECONDS ? (int) $value : null;
    }

    public static function visibilitySql(): string
    {
        $seconds = self::seconds();

        // Older rows without measured viewing time remain visible.
        return $seconds === 0 ? '1 = 1'
            : '(play_history.watch_duration_sec IS NULL OR play_history.watch_duration_sec >= ' . $seconds . ')';
    }
}
