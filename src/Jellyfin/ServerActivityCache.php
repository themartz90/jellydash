<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class ServerActivityCache
{
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private ?string $directory = null, ?callable $clock = null)
    {
        $this->clock = $clock !== null ? $clock(...) : static fn (): int => time();
    }

    /** @return array<string, mixed>|null */
    public function read(string $key): ?array
    {
        $path = $this->path($key);
        if ($path === null || !$this->writableDirectory(dirname($path)) || !is_file($path) || filesize($path) > 2097152) {
            return null;
        }
        try {
            $body = @file_get_contents($path);
            $record = is_string($body) ? json_decode($body, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return null;
        }
        $now = ($this->clock)();
        if (!is_array($record) || !is_int($record['written_at'] ?? null)
            || $record['written_at'] > $now || $record['written_at'] < $now - 300
            || !is_array($record['payload'] ?? null)
            || !in_array($record['payload']['state'] ?? null, ['ready', 'unavailable', 'forbidden', 'unconfigured', 'expired', 'checking'], true)
            || !is_bool($record['payload']['stale'] ?? null)
            || !array_key_exists('data', $record['payload'])
            || ($record['payload']['data'] !== null && !is_array($record['payload']['data']))
            || ($record['payload']['state'] === 'ready' && !is_array($record['payload']['data']))
            || !is_string($record['payload']['message'] ?? null)
            || !array_key_exists('updated_at', $record['payload'])
            || ($record['payload']['updated_at'] !== null && !is_int($record['payload']['updated_at']))
            || ($record['generation'] ?? null) !== ($this->marker($key)['generation'] ?? '')
            || ($record['checksum'] ?? null) !== hash('sha256', json_encode($record['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            return null;
        }
        return $record;
    }

    /** @param callable(): array<string, mixed> $load @return array<string, mixed> */
    public function remember(string $key, int $ttl, callable $load, bool $frozen = false): array
    {
        $now = ($this->clock)();
        $marker = $this->marker($key);
        $source = $this->source($key);
        if (($marker['blocked_source'] ?? null) === $source && ($frozen || ($marker['retry_at'] ?? 0) > $now)) {
            return $this->failure('forbidden');
        }
        $old = $this->read($key);
        if ($old !== null && ($frozen || $old['written_at'] > $now - $ttl || ($old['retry_at'] ?? 0) > $now)) {
            return $old['payload'];
        }
        if ($frozen) {
            return $this->failure('expired');
        }
        $lock = $this->lock('read-' . (hexdec(substr(hash('sha256', $key), 0, 2)) % 64));
        if ($lock === false) {
            return $this->failure('checking');
        }
        try {
            $old = $this->read($key);
            if ($old !== null && ($old['written_at'] > $now - $ttl || ($old['retry_at'] ?? 0) > $now)) {
                return $old['payload'];
            }
            return $this->load($key, $load, $old, $marker);
        } finally {
            $this->unlock($lock);
        }
    }

    /** @param callable(): array<string, mixed> $load @param array<string, mixed>|null $old @param array<string, mixed> $marker @return array<string, mixed> */
    private function load(string $key, callable $load, ?array $old, array $marker): array
    {
        $now = ($this->clock)();
        $state = 'ready';
        try {
            $payload = ['state' => 'ready', 'stale' => false, 'updated_at' => $now, 'data' => $load(), 'message' => ''];
            $record = ['written_at' => $now, 'payload' => $payload];
        } catch (\Throwable $error) {
            $state = $error instanceof ServerActivityException ? $error->state : 'unavailable';
            $payload = $this->failure($state);
            if ($state === 'unavailable' && $old !== null && is_array($old['payload']['data'] ?? null)) {
                $payload['data'] = $old['payload']['data'];
                $payload['updated_at'] = $old['payload']['updated_at'];
                $payload['stale'] = true;
            }
            $record = ['written_at' => $old['written_at'] ?? $now, 'retry_at' => $now + 15, 'payload' => $payload];
        }
        $publishLock = $this->lock('source-' . $this->stripe($key));
        if ($publishLock === false) {
            return $this->failure('checking');
        }
        try {
            $current = $this->marker($key);
            if ($state === 'forbidden') {
                $current = ['generation' => bin2hex(random_bytes(12)), 'blocked_source' => $this->source($key), 'retry_at' => $now + 15];
                $this->writeMarker($key, $current);
            } elseif (($current['generation'] ?? '') !== ($marker['generation'] ?? '')) {
                return $this->failure('forbidden');
            } elseif ($state === 'ready' && ($current['blocked_source'] ?? null) === $this->source($key)) {
                unset($current['blocked_source'], $current['retry_at']);
                $this->writeMarker($key, $current);
            }
            $record['generation'] = $current['generation'] ?? '';
            $record['checksum'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->write($key, $record);
            return $payload;
        } finally {
            $this->unlock($publishLock);
        }
    }

    /** @return array<string, mixed> */
    private function failure(string $state): array
    {
        return ['state' => $state, 'stale' => false, 'updated_at' => null, 'data' => null, 'message' => (new ServerActivityException($state))->getMessage()];
    }

    private function path(string $key): ?string
    {
        return $this->directory !== null ? $this->directory . '/' . hash('sha256', $key) . '.json' : null;
    }

    private function writableDirectory(string $directory): bool
    {
        if (is_writable($directory)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows' || !is_dir($directory)) {
            return false;
        }
        // Windows folder attributes can report read-only while file creation works.
        $probe = $directory . '/write-check-' . bin2hex(random_bytes(6));
        $handle = @fopen($probe, 'x+b');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        @unlink($probe);
        return true;
    }

    private function source(string $key): string
    {
        return explode(':', $key, 2)[0];
    }

    private function stripe(string $key): int
    {
        return (int) (hexdec(substr(hash('sha256', $this->source($key)), 0, 2)) % 32);
    }

    /** @return array<string, mixed> */
    private function marker(string $key): array
    {
        if ($this->directory === null) {
            return [];
        }
        $body = @file_get_contents($this->directory . '/generation-' . $this->stripe($key) . '.state');
        $marker = is_string($body) && strlen($body) < 1024 ? json_decode($body, true) : null;
        return is_array($marker) ? $marker : [];
    }

    /** @param array<string, mixed> $marker */
    private function writeMarker(string $key, array $marker): void
    {
        if ($this->directory !== null) {
            $this->publish($this->directory . '/generation-' . $this->stripe($key) . '.state', $marker);
        }
    }

    /** @return resource|false|null */
    private function lock(string $name): mixed
    {
        if ($this->directory === null || (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))) {
            return null;
        }
        $handle = @fopen($this->directory . '/' . $name . '.lock', 'c+b');
        if ($handle === false) {
            return null;
        }
        @chmod($this->directory . '/' . $name . '.lock', 0600);
        $deadline = microtime(true) + 0.2;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        return false;
    }

    /** @param resource|false|null $handle */
    private function unlock(mixed $handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $record */
    private function write(string $key, array $record): void
    {
        $path = $this->path($key);
        if ($path === null) {
            return;
        }
        $this->publish($path, $record);
        $files = glob(dirname($path) . '/*.json') ?: [];
        $others = array_values(array_filter($files, static fn (string $file): bool => $file !== $path));
        usort($others, static fn (string $a, string $b): int => (int) @filemtime($a) <=> (int) @filemtime($b));
        foreach (array_slice($others, 0, max(0, count($files) - 64)) as $file) {
            @unlink($file);
        }
    }

    /** @param array<string, mixed> $record */
    private function publish(string $path, array $record): void
    {
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        try {
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return;
            }
            $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($encoded) > 2097152 || @file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                return;
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $path)) {
                return;
            }
        } catch (\Throwable) {
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
