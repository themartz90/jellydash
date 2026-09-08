<?php

declare(strict_types=1);

use Mk\Framework\Cache\AtomicJsonFile;
use PHPUnit\Framework\TestCase;

final class AtomicJsonFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-atomic-json-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testPublishedPayloadReplacesTheWholePreviousFile(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'cache.json';
        file_put_contents($path, json_encode(['version' => 'old'], JSON_THROW_ON_ERROR));
        $cache = new AtomicJsonFile($path);

        $cache->write(['version' => 'new', 'items' => range(1, 1000)]);

        $this->assertSame('new', $cache->read()['version'] ?? null);
        $this->assertSame([], glob($path . '.tmp.*') ?: []);
    }

    public function testPublicationFailurePreservesOldCacheAndCleansTemporaryFile(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'cache.json';
        $old = json_encode(['version' => 'old'], JSON_THROW_ON_ERROR);
        file_put_contents($path, $old);
        $cache = new AtomicJsonFile($path, static fn (string $temporary, string $target): bool => false);

        try {
            $cache->write(['version' => 'new']);
            $this->fail('Expected cache publication to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not publish the cache file.', $e->getMessage());
        }

        $this->assertSame($old, file_get_contents($path));
        $this->assertSame([], glob($path . '.tmp.*') ?: []);
    }
}
