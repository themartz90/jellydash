<?php

declare(strict_types=1);

namespace Mk\Framework\Http;

/** Retains a server's bounded retry delay without exposing response payloads. */
final class RemoteRequestException extends \RuntimeException
{
    public function __construct(string $message, int $status, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message, $status);
    }

    public static function parseRetryAfter(string $value, int $now): int
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 128) {
            return 0;
        }
        if (preg_match('/^[0-9]+$/D', $value)) {
            $value = ltrim($value, '0');
            return strlen($value) > 5 ? 86400 : min(86400, (int) $value);
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new \DateTimeZone('GMT'));
        if ($date === false || $date->format(DATE_RFC7231) !== $value) {
            return 0;
        }
        return max(0, min(86400, $date->getTimestamp() - $now));
    }
}
