<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\ExternalPosterCache;
use PHPUnit\Framework\TestCase;

final class ExternalPosterCacheTest extends TestCase
{
    private string $scope;
    private string $baseDirectory;
    private ExternalPosterCache $cache;

    protected function setUp(): void
    {
        $this->scope = hash('sha256', 'external-cache-test-' . bin2hex(random_bytes(16)));
        $this->baseDirectory = ROOT_DIR . '/internal/artwork-external-execution/cache-tests/' . bin2hex(random_bytes(12));
        $this->cache = new ExternalPosterCache($this->scope, $this->baseDirectory);
    }

    protected function tearDown(): void
    {
        $directory = $this->directory();
        if (is_dir($directory)) {
            $this->removeOwnedDirectory($directory);
        }
        if (isset($this->baseDirectory) && is_dir($this->baseDirectory)) {
            @rmdir($this->baseDirectory);
        }
    }

    public function testSixtyFourUnicodeTokensAndThirtyTwoPreviewsStayWithinSerializedLimit(): void
    {
        $tokens = [];
        for ($index = 0; $index < 64; ++$index) {
            $tokens[] = $this->cache->put(['title' => str_repeat('é', 30000), 'index' => $index]);
        }

        $previewBody = str_repeat("\x00\x9f\xffA", 43750);
        for ($index = 0; $index < 32; ++$index) {
            $result = $this->cache->preview($tokens[$index], static fn (): array => [
                'body' => $previewBody,
                'contentType' => 'image/jpeg',
            ]);
            self::assertSame(hash('sha256', $previewBody), hash('sha256', $result['body']));
        }

        $path = $this->directory() . '/tokens.json';
        self::assertLessThanOrEqual(12 * 1024 * 1024, filesize($path));
        self::assertSame(63, $this->cache->get($tokens[63])['index']);
        self::assertSame(64, count(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)));
    }

    public function testExpiredTenMinuteSearchTokenCannotBeRead(): void
    {
        $token = $this->cache->put(['title' => 'Synthetic candidate']);
        $path = $this->directory() . '/tokens.json';
        $entries = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(time() + 599, (int) $entries[$token]['expires']);
        self::assertLessThanOrEqual(time() + 600, (int) $entries[$token]['expires']);

        $entries[$token]['expires'] = time() - 1;
        file_put_contents($path, json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $this->cache->get($token);
    }

    private function directory(): string
    {
        return $this->baseDirectory . '/' . hash('sha256', $this->scope);
    }

    private function removeOwnedDirectory(string $path): void
    {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeOwnedDirectory($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
