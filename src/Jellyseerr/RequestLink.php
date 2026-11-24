<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

final class RequestLink
{
    public static function id(mixed $value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]{0,15}$/D', (string) $value) !== 1) {
            return null;
        }
        $id = (int) $value;

        return $id <= 9_007_199_254_740_991 ? $id : null;
    }

    public static function path(int $id): string
    {
        return '/jellyseerr?request=' . $id;
    }
}
