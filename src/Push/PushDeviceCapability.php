<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

final class PushDeviceCapability
{
    public const COOKIE_NAME = 'jellydash_push_device';
    private const TOKEN_BYTES = 32;
    private const LIFETIME_SECONDS = 365 * 24 * 60 * 60;

    /** @var \Closure(string, string, array<string, mixed>): bool */
    private \Closure $cookieWriter;
    /** @var \Closure(int): string */
    private \Closure $randomBytes;
    /** @var \Closure(): int */
    private \Closure $clock;
    private bool $secure;

    public function __construct(
        ?callable $cookieWriter = null,
        ?callable $randomBytes = null,
        ?callable $clock = null,
        ?bool $secure = null,
    ) {
        $this->cookieWriter = $cookieWriter !== null
            ? $cookieWriter(...)
            : static fn (string $name, string $value, array $options): bool => setcookie($name, $value, $options);
        $this->randomBytes = $randomBytes !== null ? $randomBytes(...) : random_bytes(...);
        $this->clock = $clock !== null ? $clock(...) : time(...);
        $this->secure = $secure ?? (bool) session_get_cookie_params()['secure'];
    }

    public function hashForEnrollment(): string
    {
        $token = $this->token();
        if (!$this->isValidToken($token)) {
            $token = rtrim(strtr(base64_encode(($this->randomBytes)(self::TOKEN_BYTES)), '+/', '-_'), '=');
            if (!(($this->cookieWriter)(self::COOKIE_NAME, $token, $this->cookieOptions(
                ($this->clock)() + self::LIFETIME_SECONDS,
            )))) {
                throw new \RuntimeException('Could not persist the push device capability.');
            }
            $_COOKIE[self::COOKIE_NAME] = $token;
        }

        return hash('sha256', $token);
    }

    public function existingHash(): ?string
    {
        $token = $this->token();

        return $this->isValidToken($token) ? hash('sha256', $token) : null;
    }

    public function clear(): void
    {
        if (!(($this->cookieWriter)(self::COOKIE_NAME, '', $this->cookieOptions(($this->clock)() - 3600)))) {
            throw new \RuntimeException('Could not clear the push device capability.');
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private function token(): string
    {
        return is_string($_COOKIE[self::COOKIE_NAME] ?? null)
            ? trim($_COOKIE[self::COOKIE_NAME])
            : '';
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: true, samesite: string} */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function isValidToken(string $token): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return false;
        }

        $decoded = base64_decode(strtr($token . '=', '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === self::TOKEN_BYTES;
    }
}
