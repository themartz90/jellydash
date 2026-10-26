<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Global exception handler.
 *
 * Logs any uncaught throwable and renders a friendly 500 page. Full details are
 * only shown when APP_DEBUG is on.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(\Throwable $e): void
    {
        Log::logException($e);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        if (Config::isDebug()) {
            echo '<h1>500 Internal Server Error</h1><pre>'
                . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
        } else {
            echo '<h1>500 Internal Server Error</h1>';
        }
    }
}
