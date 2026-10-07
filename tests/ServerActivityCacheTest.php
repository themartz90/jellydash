<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\ServerActivityCache;
use Mk\Framework\Jellyfin\ServerActivityException;
use PHPUnit\Framework\TestCase;

final class ServerActivityCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jellydash-activity-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testStaleDataExpiresAndForbiddenNeverReturnsIt(): void
    {
        $now = 1000;
        $cache = new ServerActivityCache($this->directory, static function () use (&$now): int {
            return $now;
        });
        self::assertSame('ready', $cache->remember('server', 10, static fn (): array => ['name' => 'Studio'])['state']);
        $now = 1011;
        $stale = $cache->remember('server', 10, static function (): never {
            throw new ServerActivityException();
        });
        self::assertTrue($stale['stale']);
        self::assertSame(1000, $stale['updated_at']);
        $now = 1030;
        $forbidden = $cache->remember('server', 10, static function (): never {
            throw new ServerActivityException('forbidden');
        });
        self::assertNull($forbidden['data']);
        self::assertFalse($forbidden['stale']);
        $now = 1500;
        self::assertNull($cache->read('server'));
    }

    public function testFrozenSnapshotAvoidsNewReadsAndRequiresRefreshAfterExpiry(): void
    {
        $now = 1000;
        $cache = new ServerActivityCache($this->directory, static function () use (&$now): int {
            return $now;
        });
        $cache->remember('snapshot', 300, static fn (): array => ['items' => ['old']]);
        $now = 1150;
        $frozen = $cache->remember('snapshot', 300, static function (): never {
            self::fail('Frozen snapshots must not be rebuilt.');
        }, true);
        self::assertSame(['old'], $frozen['data']['items']);
        $now = 1301;
        self::assertSame('expired', $cache->remember('snapshot', 300, static fn (): array => ['items' => ['new']], true)['state']);
    }

    public function testCacheStorageIsBoundedAndSourceKeysAreIsolated(): void
    {
        $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
        for ($i = 0; $i < 70; ++$i) {
            $cache->remember('source-' . $i, 10, static fn (): array => ['value' => $i]);
        }
        self::assertCount(64, glob($this->directory . '/*.json') ?: []);
        self::assertNull($cache->read('different-source'));
    }

    public function testUnavailableCacheStorageDoesNotPreventHealthyReads(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/not-a-directory', 'fixture');
        $cache = new ServerActivityCache($this->directory . '/not-a-directory', static fn (): int => 1000);
        self::assertSame('ready', $cache->remember('key', 10, static fn (): array => ['name' => 'Studio'])['state']);
    }

    public function testWindowsFolderReadOnlyAttributeDoesNotExpireWritableSnapshots(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows folder attribute behavior.');
        }
        mkdir($this->directory);
        chmod($this->directory, 0555);
        try {
            $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
            $cache->remember('snapshot', 300, static fn (): array => ['items' => ['old']]);
            self::assertSame(['old'], $cache->remember('snapshot', 300, static fn (): array => [], true)['data']['items']);
        } finally {
            chmod($this->directory, 0777);
        }
    }

    public function testKnownDenialInvalidatesEveryOlderSnapshotOfTheSource(): void
    {
        $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
        $cache->remember('source:snapshot-a', 300, static fn (): array => ['items' => ['private']]);
        $cache->remember('source:snapshot-b', 300, static function (): never {
            throw new ServerActivityException('forbidden');
        });
        $old = $cache->remember('source:snapshot-a', 300, static fn (): array => [], true);
        self::assertNull($old['data']);
        self::assertSame('forbidden', $old['state']);
    }

    public function testDenialDuringAnEarlierReadCannotRepublishItsData(): void
    {
        $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
        $result = $cache->remember('source:early', 10, function () use ($cache): array {
            $cache->remember('source:denied', 10, static function (): never {
                throw new ServerActivityException('forbidden');
            });
            return ['items' => ['private']];
        });
        self::assertSame('forbidden', $result['state']);
        self::assertNull($result['data']);
    }

    public function testUnicodeSnapshotsRemainReadable(): void
    {
        $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
        $items = array_fill(0, 1000, ['name' => str_repeat('漢', 300), 'type' => 'TaskCompleted']);
        $cache->remember('snapshot', 300, static fn (): array => ['items' => $items]);
        self::assertCount(1000, $cache->remember('snapshot', 300, static fn (): array => [], true)['data']['items']);
    }

    public function testCorruptEnvelopeIsAMissAndConcurrentLoadsAreCoalesced(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/' . hash('sha256', 'source:key') . '.json', '{"written_at":1000,"payload":null}');
        $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
        $secondCalled = false;
        $result = $cache->remember('source:key', 10, function () use ($cache, &$secondCalled): array {
            $cache->remember('source:key', 10, static function () use (&$secondCalled): array {
                $secondCalled = true;
                return [];
            });
            return ['name' => 'Studio'];
        });
        self::assertSame('ready', $result['state']);
        self::assertFalse($secondCalled);
    }

    public function testHeldSourceLockCannotPublishProtectedData(): void
    {
        mkdir($this->directory);
        $stripe = hexdec(substr(hash('sha256', 'source'), 0, 2)) % 32;
        $handle = fopen($this->directory . '/source-' . $stripe . '.lock', 'c+b');
        flock($handle, LOCK_EX);
        try {
            $cache = new ServerActivityCache($this->directory, static fn (): int => 1000);
            $result = $cache->remember('source:key', 10, static fn (): array => ['private' => true]);
            self::assertNull($result['data']);
            self::assertSame('checking', $result['state']);
            self::assertNull($cache->read('source:key'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
