<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Small per-range cache for completed Statistics page payloads.
 *
 * The current request supplies the clock so a cache entry cannot survive a
 * calendar boundary merely because its TTL has not elapsed yet.
 */
final class StatisticsPayloadCache
{
    private const CACHE_SCHEMA_VERSION = 2;
    private const DEFAULT_TTL = 120;

    private string $cacheDirectory;

    public function __construct(?string $cacheDirectory = null, private int $ttl = self::DEFAULT_TTL)
    {
        $this->cacheDirectory = rtrim($cacheDirectory ?? CACHE_DIR . '/statistics', '/\\');
        $this->ttl = max(1, $this->ttl);
    }

    /**
     * @param callable(): mixed $builder
     * @return array<string, mixed>
     */
    public function remember(
        string $range,
        \DateTimeImmutable $now,
        string $contextFingerprint,
        callable $builder,
    ): array {
        $range = StatisticsPeriod::normalizeRange($range);
        $cached = $this->freshStats($range, $now, $contextFingerprint);
        if ($cached !== null) {
            return $cached;
        }

        $lock = $this->openLock($range);
        if ($lock === null) {
            return $this->rebuild($range, $now, $contextFingerprint, $builder);
        }

        try {
            if (!@flock($lock, LOCK_EX)) {
                return $this->rebuild($range, $now, $contextFingerprint, $builder);
            }

            // Another request may have finished the payload while this request
            // was waiting for the per-range lock.
            $cached = $this->freshStats($range, $now, $contextFingerprint);
            if ($cached !== null) {
                return $cached;
            }

            return $this->rebuild($range, $now, $contextFingerprint, $builder);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * @param callable(): mixed $builder
     * @return array<string, mixed>
     */
    private function rebuild(
        string $range,
        \DateTimeImmutable $now,
        string $contextFingerprint,
        callable $builder,
    ): array {
        $stats = $builder();
        if (!is_array($stats)) {
            throw new \UnexpectedValueException('Statistics payload builders must return an array.');
        }

        $this->write($range, [
            'schema_version' => self::CACHE_SCHEMA_VERSION,
            'range' => $range,
            'context_fingerprint' => $contextFingerprint,
            'generated_at' => $now->getTimestamp(),
            'local_date' => $now->format('Y-m-d'),
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function freshStats(string $range, \DateTimeImmutable $now, string $contextFingerprint): ?array
    {
        $payload = $this->read($range);
        if ($payload === null
            || ($payload['schema_version'] ?? null) !== self::CACHE_SCHEMA_VERSION
            || ($payload['range'] ?? null) !== $range
            || !is_string($payload['context_fingerprint'] ?? null)
            || !hash_equals($contextFingerprint, $payload['context_fingerprint'])
            || !is_int($payload['generated_at'] ?? null)
            || !is_string($payload['local_date'] ?? null)
            || !is_array($payload['stats'] ?? null)) {
            return null;
        }

        $generatedAt = $payload['generated_at'];
        if ($generatedAt > $now->getTimestamp()
            || ($now->getTimestamp() - $generatedAt) >= $this->ttl
            || $payload['local_date'] !== $now->format('Y-m-d')) {
            return null;
        }

        /** @var array<string, mixed> $stats */
        $stats = $payload['stats'];

        return $stats;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $range): ?array
    {
        $path = $this->cacheFile($range);
        if (!is_file($path)) {
            return null;
        }

        try {
            $encoded = @file_get_contents($path);
            if (!is_string($encoded)) {
                return null;
            }

            $payload = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $range, array $payload): void
    {
        if (!$this->ensureDirectory()) {
            return;
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $path = $this->cacheFile($range);
            if (@file_put_contents($path, $encoded, LOCK_EX) !== false) {
                @chmod($path, 0666);
            }
        } catch (\Throwable) {
            // The calculation is still useful if the optional cache cannot be written.
        }
    }

    /** @return resource|null */
    private function openLock(string $range)
    {
        if (!$this->ensureDirectory()) {
            return null;
        }

        $lock = @fopen($this->cacheFile($range) . '.lock', 'c+');

        return is_resource($lock) ? $lock : null;
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->cacheDirectory)
            || @mkdir($this->cacheDirectory, 0775, true)
            || is_dir($this->cacheDirectory);
    }

    private function cacheFile(string $range): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'statistics-' . $range . '.json';
    }
}
