<?php

declare(strict_types=1);

namespace Mk\Framework\Cache;

final class AtomicJsonFile
{
    /** @var (\Closure(string, string): bool)|null */
    private ?\Closure $publisher;

    /** @param (\Closure(string, string): bool)|null $publisher */
    public function __construct(private string $path, ?\Closure $publisher = null)
    {
        $this->publisher = $publisher;
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        try {
            $encoded = @file_get_contents($this->path);
            if (!is_string($encoded)) {
                return null;
            }
            $payload = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    public function write(array $payload): void
    {
        $this->ensureDirectory();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temporary = $this->path . '.tmp.' . bin2hex(random_bytes(12));
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not create the cache temporary file.');
        }

        try {
            $remaining = $encoded;
            while ($remaining !== '') {
                $written = @fwrite($handle, $remaining);
                if (!is_int($written) || $written <= 0) {
                    throw new \RuntimeException('Could not write the cache temporary file.');
                }
                $remaining = substr($remaining, $written);
            }
            if (!@fflush($handle)) {
                throw new \RuntimeException('Could not flush the cache temporary file.');
            }
            @fclose($handle);
            $handle = null;

            // Web requests and CLI warmers may run as different users. Make
            // both the current target and replacement writable before publish.
            @chmod($temporary, 0666);
            if (is_file($this->path)) {
                @chmod($this->path, 0666);
            }

            $published = $this->publisher !== null
                ? ($this->publisher)($temporary, $this->path)
                : @rename($temporary, $this->path);
            if (!$published) {
                throw new \RuntimeException('Could not publish the cache file.');
            }
            @chmod($this->path, 0666);
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withExclusiveLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $lock = @fopen($this->path . '.lock', 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('Could not open the cache lock.');
        }

        try {
            @chmod($this->path . '.lock', 0666);
            if (!@flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not acquire the cache lock.');
            }

            return $callback();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the cache directory.');
        }
    }
}
