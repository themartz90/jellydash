<?php

declare(strict_types=1);

namespace Mk\Framework;

/** Resolves external request details without trusting arbitrary forwarding headers. */
final class RequestContext
{
    /** @var list<array{network: string, prefix: int}> */
    private array $trustedNetworks = [];
    private ?string $httpsOrigin = null;

    /** @param list<string> $trustedProxies */
    public function __construct(
        array $trustedProxies = [],
        private bool $forceHttps = false,
        ?string $appUrl = null,
    ) {
        foreach ($trustedProxies as $entry) {
            $this->trustedNetworks[] = $this->parseNetwork($entry);
        }

        if ($this->forceHttps) {
            $this->httpsOrigin = $this->canonicalHttpsOrigin($appUrl ?? '');
        }
    }

    public static function fromConfig(): self
    {
        $trusted = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) Config::get('TRUSTED_PROXIES', '')),
        ), static fn (string $entry): bool => $entry !== ''));

        return new self($trusted, Config::bool('FORCE_HTTPS', false), Config::get('APP_URL'));
    }

    /** @param array<string, mixed> $server */
    public function clientIp(array $server): string
    {
        $remote = $this->validIp($server['REMOTE_ADDR'] ?? null) ?? 'unknown';
        if ($remote === 'unknown' || !$this->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded === '') {
            return $remote;
        }

        $chain = [];
        foreach (explode(',', $forwarded) as $candidate) {
            $ip = $this->validIp(trim($candidate));
            if ($ip === null) {
                return $remote;
            }
            $chain[] = $ip;
        }
        $chain[] = $remote;

        $index = count($chain) - 1;
        while ($index > 0 && $this->isTrusted($chain[$index])) {
            --$index;
        }

        return $chain[$index];
    }

    /** @param array<string, mixed> $server */
    public function isHttps(array $server): bool
    {
        if ($this->directHttps($server)) {
            return true;
        }

        $remote = $this->validIp($server['REMOTE_ADDR'] ?? null);
        if ($remote === null || !$this->isTrusted($remote)) {
            return false;
        }

        return $this->forwardedScheme($server) === 'https';
    }

    /**
     * Keeps the legacy secure-cookie signal during proxy configuration
     * migration. It does not establish the client address or external scheme.
     *
     * @param array<string, mixed> $server
     */
    public function secureCookies(array $server): bool
    {
        return $this->isHttps($server) || $this->forwardedScheme($server) === 'https';
    }

    /** @param array<string, mixed> $server */
    public function httpsRedirectUrl(array $server): ?string
    {
        if (!$this->forceHttps || $this->isHttps($server) || $this->httpsOrigin === null) {
            return null;
        }

        $request = parse_url((string) ($server['REQUEST_URI'] ?? '/'));
        $path = is_array($request) ? (string) ($request['path'] ?? '/') : '/';
        $path = '/' . ltrim($path, '/');
        $query = is_array($request) && isset($request['query']) ? '?' . $request['query'] : '';

        return $this->httpsOrigin . ($path === '/' ? '/' : $path) . $query;
    }

    /** @param array<string, mixed> $server */
    private function directHttps(array $server): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));

        return ($https !== '' && $https !== 'off') || (string) ($server['SERVER_PORT'] ?? '') === '443';
    }

    /** @param array<string, mixed> $server */
    private function forwardedScheme(array $server): ?string
    {
        $values = array_map('trim', explode(',', strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))));
        $scheme = $values[array_key_last($values)] ?? '';

        return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
    }

    private function isTrusted(string $ip): bool
    {
        $address = @inet_pton($ip);
        if (!is_string($address)) {
            return false;
        }

        foreach ($this->trustedNetworks as $network) {
            if (strlen($address) !== strlen($network['network'])) {
                continue;
            }
            if ($this->prefixMatches($address, $network['network'], $network['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{network: string, prefix: int} */
    private function parseNetwork(string $entry): array
    {
        [$ip, $prefixText] = array_pad(explode('/', trim($entry), 2), 2, null);
        $network = @inet_pton($ip);
        if (!is_string($network)) {
            throw new \InvalidArgumentException('Trusted proxy entries must be valid IP addresses or CIDR ranges.');
        }

        $bits = strlen($network) * 8;
        $prefix = $prefixText === null ? $bits : filter_var($prefixText, FILTER_VALIDATE_INT);
        if (!is_int($prefix) || $prefix < 0 || $prefix > $bits) {
            throw new \InvalidArgumentException('Trusted proxy CIDR prefixes are invalid.');
        }

        return ['network' => $network, 'prefix' => $prefix];
    }

    private function prefixMatches(string $address, string $network, int $prefix): bool
    {
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        $remaining = $prefix % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }

    private function validIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private function canonicalHttpsOrigin(string $appUrl): string
    {
        $parts = parse_url(trim($appUrl));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw new \InvalidArgumentException('FORCE_HTTPS requires APP_URL with a canonical HTTPS address.');
        }

        $host = (string) $parts['host'];
        $origin = 'https://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return rtrim($origin . (string) ($parts['path'] ?? ''), '/');
    }
}
