<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Persistent login tokens use a public selector and a secret validator. Only
 * the validator hash is stored, so a database leak does not expose a working
 * browser cookie. Successful use rotates the secret before returning it.
 */
final class RememberTokenRepository
{
    private const SELECTOR_BYTES = 12;
    private const VALIDATOR_BYTES = 32;
    private const CONCURRENT_RESTORE_SECONDS = 10;

    private Database $database;
    private \Dibi\Connection $dibi;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?? Container::db();
        $this->database->ensureAuthSchema();
        $this->dibi = $this->database->getDibi();
    }

    public function issue(int $userId, int $now, int $lifetime): string
    {
        $this->removeExpired($now);

        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        $timestamp = $this->timestamp($now);

        $this->dibi->insert('auth_remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => $this->timestamp($now + $lifetime),
            'created_at' => $timestamp,
            'last_used_at' => $timestamp,
        ])->execute();

        return $selector . '.' . $validator;
    }

    /**
     * @return array{user: array<string, mixed>, token: string}|null
     */
    public function consume(string $token, int $now, int $lifetime): ?array
    {
        $parts = $this->parts($token);
        if ($parts === null) {
            return null;
        }

        [$selector, $validator] = $parts;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $restored = $this->consumeAttempt($selector, $validator, $now, $lifetime);
            if ($restored !== false) {
                return $restored;
            }
        }

        return null;
    }

    /** @return array{user: array<string, mixed>, token: string}|false|null */
    private function consumeAttempt(string $selector, string $validator, int $now, int $lifetime): array|false|null
    {
        $row = $this->dibi->select('user_id, validator_hash, expires_at, previous_validator_hash, rotation_nonce, rotation_valid_until')
            ->from('auth_remember_tokens')
            ->where('selector = %s', $selector)
            ->limit(1)
            ->fetch();

        if (!$row) {
            return null;
        }

        $expiresAt = strtotime((string) $row['expires_at']);
        if ($expiresAt === false || $expiresAt <= $now) {
            $this->deleteSelector($selector);
            return null;
        }

        $hash = hash('sha256', $validator);
        $current = hash_equals((string) $row['validator_hash'], $hash);
        $withinRotation = (int) $row['rotation_valid_until'] > $now;
        $previous = $withinRotation && hash_equals((string) ($row['previous_validator_hash'] ?? ''), $hash);
        if (!$current && !$previous) {
            // A rotated token being replayed may mean the old cookie was
            // copied. Remove the selector so neither copy remains trusted.
            $this->dibi->delete('auth_remember_tokens')
                ->where('selector = %s', $selector)
                ->where('validator_hash = %s', (string) $row['validator_hash'])->execute();
            return null;
        }

        $user = $this->database->getUser((int) $row['user_id']);
        if ($user === null) {
            $this->deleteSelector($selector);
            return null;
        }

        // Concurrent requests reuse the same replacement. The stored nonce
        // cannot recover either cookie without knowing the previous secret.
        if ($withinRotation) {
            $replacement = $current ? $validator : hash_hmac('sha256', (string) $row['rotation_nonce'], $validator);
            if (!hash_equals((string) $row['validator_hash'], hash('sha256', $replacement))) {
                return null;
            }

            return ['user' => $user, 'token' => $selector . '.' . $replacement];
        }

        $nonce = bin2hex(random_bytes(16));
        $newValidator = hash_hmac('sha256', $nonce, $validator);
        $this->dibi->update('auth_remember_tokens', [
            'validator_hash' => hash('sha256', $newValidator),
            'previous_validator_hash' => $hash,
            'rotation_nonce' => $nonce,
            'rotation_valid_until' => $now + self::CONCURRENT_RESTORE_SECONDS,
            'expires_at' => $this->timestamp($now + $lifetime),
            'last_used_at' => $this->timestamp($now),
        ])->where('selector = %s', $selector)
            ->where('validator_hash = %s', $hash)->execute();
        if ($this->dibi->getAffectedRows() !== 1) {
            return false;
        }

        return [
            'user' => $user,
            'token' => $selector . '.' . $newValidator,
        ];
    }

    public function revoke(string $token): void
    {
        $parts = $this->parts($token);
        if ($parts !== null) {
            $this->deleteSelector($parts[0]);
        }
    }

    public function revokeUser(int $userId): void
    {
        $this->dibi->delete('auth_remember_tokens')->where('user_id = %i', $userId)->execute();
    }

    private function removeExpired(int $now): void
    {
        $this->dibi->delete('auth_remember_tokens')
            ->where('expires_at <= %s', $this->timestamp($now))
            ->execute();
    }

    private function deleteSelector(string $selector): void
    {
        $this->dibi->delete('auth_remember_tokens')->where('selector = %s', $selector)->execute();
    }

    /** @return array{string, string}|null */
    private function parts(string $token): ?array
    {
        if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/D', $token, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    private function timestamp(int $unixTime): string
    {
        return date('Y-m-d H:i:s', $unixTime);
    }
}
