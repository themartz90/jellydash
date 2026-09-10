<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

final class PushSubscriptionValidator
{
    private const MAX_ENDPOINT_LENGTH = 4096;
    private const PUBLIC_KEY_BYTES = 65;
    private const AUTH_SECRET_BYTES = 16;

    /** @var list<string> */
    private const EXACT_PROVIDER_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
    ];

    /** @var list<string> */
    private const PROVIDER_HOST_SUFFIXES = [
        '.push.apple.com',
        '.notify.windows.com',
    ];

    public static function isValid(string $endpoint, string $p256dh, string $auth): bool
    {
        return self::isValidEndpoint($endpoint)
            && self::hasValidKeys($p256dh, $auth);
    }

    public static function isValidEndpoint(string $endpoint): bool
    {
        return self::hasValidEndpointSyntax($endpoint)
            && self::isAllowedDestination($endpoint);
    }

    public static function hasValidEndpointSyntax(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            return false;
        }

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($endpoint);

        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public static function isAllowedDestination(string $endpoint): bool
    {
        if (!self::hasValidEndpointSyntax($endpoint)) {
            return false;
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || !isset($parts['host'])
            || (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if (in_array($host, self::EXACT_PROVIDER_HOSTS, true)) {
            return true;
        }

        foreach (self::PROVIDER_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function hasValidKeys(string $p256dh, string $auth): bool
    {
        return self::hasDecodedLength($p256dh, self::PUBLIC_KEY_BYTES)
            && self::hasDecodedLength($auth, self::AUTH_SECRET_BYTES);
    }

    private static function hasDecodedLength(string $value, int $expectedBytes): bool
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return false;
        }

        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === $expectedBytes;
    }
}
