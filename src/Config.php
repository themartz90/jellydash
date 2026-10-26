<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Environment-backed configuration.
 *
 * Values come from the process environment / `.env` (loaded via phpdotenv in
 * index.php). Everything has a sensible default so the app still boots if a key
 * is missing.
 */
class Config
{
    /** @var array<string, true>|null */
    private static ?array $validTimezones = null;

    // Raw string lookup with fallback.
    public static function get(string $key, ?string $default = null): ?string
    {
        // Real process variables must override values loaded from .env. Some
        // SAPIs expose them only through getenv(), not through the PHP arrays.
        $processValue = getenv($key);
        $value = $processValue !== false ? $processValue : ($_ENV[$key] ?? $_SERVER[$key] ?? false);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    // Boolean lookup (accepts true/false/1/0/yes/no/on/off).
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null && in_array($key, [
            'AUTH_ENABLED', 'JELLYFIN_VERIFY_SSL', 'JELLYSEER_VERIFY_SSL', 'SAB_VERIFY_SSL',
        ], true)) {
            throw new \InvalidArgumentException($key . ' must be a valid boolean.');
        }

        return $parsed ?? false;
    }

    public static function interval(string $key, int $default): int
    {
        $value = filter_var(self::get($key, (string) $default), FILTER_VALIDATE_INT);

        return $value !== false && $value >= 1 && $value <= 86400 ? $value : $default;
    }

    // Current application environment, e.g. "local" or "production".
    public static function env(): string
    {
        return self::get('APP_ENV', 'production');
    }

    /**
     * Resolve the timezone used for stored timestamps and date boundaries.
     * APP_TIMEZONE remains the explicit Jellydash setting, while TZ supports
     * conventional Docker installations that pass only the standard variable.
     */
    public static function timezone(): string
    {
        self::$validTimezones ??= array_fill_keys(
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
            true,
        );

        foreach (['APP_TIMEZONE', 'TZ'] as $key) {
            $timezone = self::get($key);
            if ($timezone === null) {
                continue;
            }

            if (isset(self::$validTimezones[$timezone])) {
                return $timezone;
            }
        }

        return 'UTC';
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }
}
