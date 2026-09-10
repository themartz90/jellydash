<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Logging facade.
 *
 * Thin wrapper over the PSR-3 logger from the container (Monolog). Keeps the
 * historical static API so existing call sites don't change, but routing,
 * formatting, and persistence are now Monolog's job; logging can never crash
 * the request.
 */
class Log
{
    public static function logDibi(\Dibi\Exception $e): void
    {
        self::error($e->getMessage(), $e);
    }

    public static function logException(\Throwable $e): void
    {
        self::error($e->getMessage(), $e);
    }

    public static function logErrorMessage(string $msg, $class): void
    {
        Container::logger()->error($msg, ['class' => self::className($class)]);
    }

    public static function logDebugMessage(string $msg, $class): void
    {
        Container::logger()->debug($msg, ['class' => self::className($class)]);
    }

    public static function logInfoMessage(string $msg, $class): void
    {
        Container::logger()->info($msg, ['class' => self::className($class)]);
    }

    public static function userIP(): string
    {
        try {
            return RequestContext::fromConfig()->clientIp($_SERVER);
        } catch (\InvalidArgumentException) {
            $remote = $_SERVER['REMOTE_ADDR'] ?? null;

            return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
        }
    }

    private static function error(string $msg, \Throwable $e): void
    {
        Container::logger()->error($msg, [
            'class' => $e::class,
            'line' => $e->getLine(),
            'exception' => $e,
        ]);
    }

    private static function className($class): string
    {
        return is_object($class) ? $class::class : (string) $class;
    }
}
