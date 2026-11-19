<?php

declare(strict_types=1);

namespace Mk\Framework;

/** Portable, source-aware brute-force protection for the login endpoint. */
final class LoginThrottle
{
    private const PAIR_MAX_ATTEMPTS = 5;
    private const SOURCE_MAX_ATTEMPTS = 30;
    private const WINDOW_SECONDS = 900;
    private const RETENTION_SECONDS = 86400;

    private Database $database;
    private \Dibi\Connection $dibi;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(?Database $database = null, ?callable $clock = null)
    {
        $this->database = $database ?? Container::db();
        $this->database->ensureAuthSchema();
        $this->dibi = $this->database->getDibi();
        $this->clock = $clock !== null ? $clock(...) : static fn (): int => time();
    }

    public static function isLocked(string $username, string $ip): bool
    {
        return (new self())->blocked($username, $ip);
    }

    public static function recordFailure(string $username, string $ip): void
    {
        (new self())->failure($username, $ip);
    }

    public static function clear(string $username, string $ip): void
    {
        (new self())->clearPair($username, $ip);
    }

    public function blocked(string $username, string $ip): bool
    {
        foreach ([self::pairIdentifier($username, $ip), self::sourceIdentifier($ip), self::legacyIdentifier($username, $ip)] as $identifier) {
            $row = $this->row($identifier);
            if ($row !== null && $this->activeLock($row)) {
                return true;
            }
        }

        return false;
    }

    public function failure(string $username, string $ip): void
    {
        $source = self::sourceIdentifier($ip);
        $this->withSourceLock($source, function () use ($username, $ip, $source): void {
            $this->increment(self::pairIdentifier($username, $ip), self::PAIR_MAX_ATTEMPTS);
            $this->increment($source, self::SOURCE_MAX_ATTEMPTS);
        });
    }

    public function clearPair(string $username, string $ip): void
    {
        $this->withSourceLock(self::sourceIdentifier($ip), fn () => $this->deletePair($username, $ip));
    }

    public function admit(string $username, string $ip): ?string
    {
        if (random_int(1, 100) === 1) {
            $this->pruneExpired();
            $expired = $this->dibi->select('token')->from('login_admissions')
                ->where('expires_at <= %i', $this->now())->orderBy('expires_at')->limit(50)->fetchPairs(null, 'token');
            if ($expired !== []) {
                $this->dibi->delete('login_admissions')->where('token IN %in', array_values($expired))->execute();
            }
        }

        $pair = self::pairIdentifier($username, $ip);
        $source = self::sourceIdentifier($ip);

        return $this->withSourceLock($source, function () use ($username, $ip, $pair, $source): ?string {
            if ($this->blocked($username, $ip)) {
                return null;
            }

            $now = $this->now();
            $pending = $this->dibi->select('pair_key')->from('login_admissions')
                ->where('source_key = %s AND expires_at > %i', $source, $now)->fetchPairs(null, 'pair_key');
            $pairPending = count(array_keys($pending, $pair, true));
            if ($this->recentFailures($pair, $now) + $pairPending >= self::PAIR_MAX_ATTEMPTS
                || $this->recentFailures($source, $now) + count($pending) >= self::SOURCE_MAX_ATTEMPTS) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $this->dibi->insert('login_admissions', [
                'token' => $token, 'source_key' => $source, 'pair_key' => $pair, 'expires_at' => $now + self::WINDOW_SECONDS,
            ])->execute();

            return $token;
        });
    }

    /** A neutral outcome releases admission without changing either failure count. */
    public function finish(string $username, string $ip, string $token, ?bool $success): bool
    {
        $pair = self::pairIdentifier($username, $ip);
        $source = self::sourceIdentifier($ip);

        return $this->withSourceLock($source, function () use ($username, $ip, $token, $success, $pair, $source): bool {
            $this->dibi->delete('login_admissions')
                ->where('token = %s AND source_key = %s AND pair_key = %s AND expires_at > %i',
                    $token, $source, $pair, $this->now())->execute();
            if ($this->dibi->getAffectedRows() !== 1) {
                return false;
            }
            if ($success === true) {
                $this->deletePair($username, $ip);
            } elseif ($success === false) {
                $this->increment($pair, self::PAIR_MAX_ATTEMPTS);
                $this->increment($source, self::SOURCE_MAX_ATTEMPTS);
            }

            return true;
        });
    }

    private function deletePair(string $username, string $ip): void
    {
        $this->dibi->delete('login_attempts')->where('identifier IN %in', [
            self::pairIdentifier($username, $ip),
            self::legacyIdentifier($username, $ip),
        ])->execute();
    }

    public function pruneExpired(int $limit = 50): int
    {
        $cutoff = $this->date($this->now() - self::RETENTION_SECONDS);
        $now = $this->date($this->now());
        $identifiers = $this->dibi->select('identifier')->from('login_attempts')
            ->where('updated_at < %s', $cutoff)
            ->where('(locked_until IS NULL OR locked_until <= %s)', $now)
            ->orderBy('updated_at ASC')->limit(max(1, min(500, $limit)))
            ->fetchPairs(null, 'identifier');
        if ($identifiers === []) {
            return 0;
        }

        $this->dibi->delete('login_attempts')->where('identifier IN %in', array_values($identifiers))
            ->where('updated_at < %s', $cutoff)
            ->where('(locked_until IS NULL OR locked_until <= %s)', $now)
            ->execute();

        return $this->dibi->getAffectedRows();
    }

    public static function pairIdentifier(string $username, string $ip): string
    {
        return 'pair:' . hash('sha256', strtolower(trim($username)) . "\0" . trim($ip));
    }

    public static function sourceIdentifier(string $ip): string
    {
        return 'source:' . hash('sha256', trim($ip));
    }

    private static function legacyIdentifier(string $username, string $ip): string
    {
        return substr(strtolower(trim($username)) . '|' . trim($ip), 0, 190);
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function withSourceLock(string $source, \Closure $operation): mixed
    {
        $sqlite = $this->database->getPlatform()->isSqlite();
        if ($sqlite) {
            $this->dibi->query('BEGIN IMMEDIATE');
        } else {
            $this->dibi->begin();
        }
        try {
            // Serialize reservations and outcomes for this source. The password
            // check takes place between these short transactions.
            $this->dibi->query(
                'INSERT INTO `login_attempts` (`identifier`, `updated_at`) VALUES (%s, %s) '
                . ($sqlite ? 'ON CONFLICT(identifier) DO NOTHING' : 'ON DUPLICATE KEY UPDATE identifier = VALUES(identifier)'),
                $source,
                $this->date($this->now()),
            );
            $result = $operation();
            $this->dibi->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->dibi->rollback();
            throw $e;
        }
    }

    private function recentFailures(string $identifier, int $now): int
    {
        return (int) $this->dibi->select('attempts')->from('login_attempts')
            ->where('identifier = %s AND window_started_at > %s', $identifier, $this->date($now - self::WINDOW_SECONDS))
            ->fetchSingle();
    }

    private function increment(string $identifier, int $limit): void
    {
        $now = $this->now();
        $nowText = $this->date($now);
        $cutoff = $this->date($now - self::WINDOW_SECONDS);
        $lockedUntil = $this->date($now + self::WINDOW_SECONDS);

        $this->dibi->query(
            'UPDATE `login_attempts` SET '
            . '`locked_until` = CASE WHEN `window_started_at` IS NULL OR `window_started_at` <= %s THEN NULL WHEN `attempts` + 1 >= %i THEN %s ELSE `locked_until` END, '
            . '`attempts` = CASE WHEN `window_started_at` IS NULL OR `window_started_at` <= %s THEN 1 ELSE `attempts` + 1 END, '
            . '`window_started_at` = CASE WHEN `window_started_at` IS NULL OR `window_started_at` <= %s THEN %s ELSE `window_started_at` END, '
            . '`updated_at` = %s WHERE `identifier` = %s',
            $cutoff,
            $limit,
            $lockedUntil,
            $cutoff,
            $cutoff,
            $nowText,
            $nowText,
            $identifier,
        );
        if ($this->dibi->getAffectedRows() > 0) {
            return;
        }

        try {
            $this->dibi->insert('login_attempts', [
                'identifier' => $identifier,
                'attempts' => 1,
                'locked_until' => null,
                'window_started_at' => $nowText,
                'updated_at' => $nowText,
            ])->execute();
        } catch (\Dibi\Exception $e) {
            if ($this->row($identifier) === null) {
                throw $e;
            }
            $this->increment($identifier, $limit);
        }
    }

    /** @return array<string, mixed>|null */
    private function row(string $identifier): ?array
    {
        $row = $this->dibi->select('*')->from('login_attempts')
            ->where('identifier = %s', $identifier)->limit(1)->fetch();

        return $row ? $row->toArray() : null;
    }

    /** @param array<string, mixed> $row */
    private function activeLock(array $row): bool
    {
        $lockedUntil = trim((string) ($row['locked_until'] ?? ''));

        if ($lockedUntil === '') {
            return false;
        }

        try {
            return (new \DateTimeImmutable($lockedUntil, new \DateTimeZone(Config::timezone())))->getTimestamp() > $this->now();
        } catch (\Throwable) {
            return false;
        }
    }

    private function date(int $epoch): string
    {
        return (new \DateTimeImmutable('@' . $epoch))
            ->setTimezone(new \DateTimeZone(Config::timezone()))
            ->format('Y-m-d H:i:s');
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
