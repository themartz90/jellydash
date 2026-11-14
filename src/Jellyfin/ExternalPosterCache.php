<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Short-lived, private manager search tokens. Never a retained-image store. */
final class ExternalPosterCache
{
    private string $directory;

    public static function expire(?string $directory = null): void
    {
        $root = $directory ?? CACHE_DIR . '/external-posters';
        if (!is_dir($root)) {
            return;
        }
        $removed = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            if (!$entry->isDir() || $entry->isLink() || !preg_match('/^[a-f0-9]{64}$/D', $entry->getFilename())) {
                continue;
            }
            $path = $entry->getPathname() . '/tokens.json';
            $lock = fopen($entry->getPathname() . '/tokens.lock', 'c+b');
            if ($lock === false) {
                continue;
            }
            try {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    clearstatcache(true, $path);
                    if (is_file($path) && filemtime($path) <= time() - 600) {
                        @unlink($path);
                        ++$removed;
                    }
                    flock($lock, LOCK_UN);
                }
            } finally {
                fclose($lock);
            }
            if ($removed >= 100) {
                break;
            }
        }
    }

    public function __construct(string $scope, ?string $directory = null)
    {
        $this->directory = ($directory ?? CACHE_DIR . '/external-posters') . '/' . hash('sha256', $scope);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Poster search cache is unavailable.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function put(array $payload): string
    {
        $token = bin2hex(random_bytes(32));
        $this->change(function (array &$entries) use ($token, $payload): void {
            while (count($entries) >= 64) {
                array_shift($entries);
            }
            $entries[$token] = $payload + ['expires' => time() + 600];
        });
        return $token;
    }

    /** @return array<string, mixed> */
    public function get(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid poster choice.');
        }
        return $this->change(static function (array &$entries) use ($token): array {
            if (!isset($entries[$token])) {
                throw new \RuntimeException('The poster search expired. Search again.');
            }
            return $entries[$token];
        });
    }

    /** @param \Closure(): array{body: string, contentType: string} $fetch
     * @return array{body: string, contentType: string}
     */
    public function preview(string $token, \Closure $fetch): array
    {
        $cached = $this->get($token);
        if (isset($cached['body'], $cached['contentType'])) {
            return ['body' => (string) base64_decode($cached['body'], true), 'contentType' => $cached['contentType']];
        }
        $slot = null;
        for ($i = 0; $i < 2; ++$i) {
            $candidate = fopen($this->directory . '/preview-' . $i . '.lock', 'c+b');
            if ($candidate !== false && flock($candidate, LOCK_EX | LOCK_NB)) {
                $slot = $candidate;
                break;
            }
            if ($candidate !== false) {
                fclose($candidate);
            }
        }
        if ($slot === null) {
            throw new \RuntimeException('Poster previews are busy.');
        }
        try {
            $this->change(static function (array &$entries) use ($token): void {
                if (!isset($entries[$token]) || (int) ($entries[$token]['retryAt'] ?? 0) > time()) {
                    throw new \RuntimeException('Poster preview is unavailable.');
                }
                $entries[$token]['retryAt'] = time() + 30;
            });
            $image = $fetch();
            $this->change(static function (array &$entries) use ($token, $image): void {
                if (!isset($entries[$token])) {
                    return;
                }
                $entries[$token]['body'] = base64_encode($image['body']);
                $entries[$token]['contentType'] = $image['contentType'];
                $bytes = 0;
                foreach (array_reverse(array_keys($entries)) as $key) {
                    $bytes += strlen($entries[$key]['body'] ?? '');
                    if ($bytes > 11184832) {
                        unset($entries[$key]['body'], $entries[$key]['contentType']);
                    }
                }
            });
            return $image;
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    /**
     * @template T
     * @param \Closure(array<string, array<string, mixed>>&): T $operation
     * @return T
     */
    private function change(\Closure $operation): mixed
    {
        $path = $this->directory . '/tokens.json';
        $lock = fopen($this->directory . '/tokens.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Poster search cache is unavailable.');
        }
        try {
            $entries = [];
            if (is_file($path) && filesize($path) <= 12582912) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded) && count($decoded) <= 64) {
                    foreach ($decoded as $key => $value) {
                        if (is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key) && is_array($value) && (int) ($value['expires'] ?? 0) > time()) {
                            $entries[$key] = $value;
                        }
                    }
                }
            }
            $result = $operation($entries);
            $body = json_encode($entries, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            while (strlen($body) > 12582912 && $entries !== []) {
                $removed = false;
                foreach ($entries as $key => $entry) {
                    if (isset($entry['body'])) {
                        unset($entries[$key]['body'], $entries[$key]['contentType']);
                        $removed = true;
                        break;
                    }
                }
                if (!$removed) {
                    array_shift($entries);
                }
                $body = json_encode($entries, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            }
            if (file_put_contents($path, $body) !== strlen($body)) {
                throw new \RuntimeException('Poster search cache is unavailable.');
            }
            @chmod($path, 0600);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
