<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * CSRF protection.
 *
 * A single per-session token is generated on demand, exposed to templates as a
 * Twig global (`csrf_token`), and verified on every state-changing POST.
 * Requires an active session (started in utils/@settings.php).
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_token';
    private const FIELD = 'csrf_token';

    // Return the current token, generating one if needed.
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    // Constant-time comparison of a submitted token against the session token.
    public static function validate(?string $token): bool
    {
        return !empty($_SESSION[self::SESSION_KEY])
            && is_string($token)
            && hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function validateHeader(): bool
    {
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN'])
            ? (string) $_SERVER['HTTP_X_CSRF_TOKEN']
            : null;

        return self::validate($token);
    }

    public static function checkHeader(): void
    {
        if (!self::validateHeader()) {
            http_response_code(419);
            exit('Invalid or missing CSRF token.');
        }
    }

    // Guard a POST handler: aborts the request if the token is missing/invalid.
    public static function check(): void
    {
        $token = $_POST[self::FIELD] ?? null;
        if (!is_string($token) || !self::validate($token)) {
            http_response_code(419);
            exit('Invalid or missing CSRF token.');
        }
    }

    public static function fieldName(): string
    {
        return self::FIELD;
    }
}
