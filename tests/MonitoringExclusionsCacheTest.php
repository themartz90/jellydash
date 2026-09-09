<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Jellyfin\LibraryHistorySource;
use Mk\Framework\Jellyfin\LibraryOverviewClient;
use Mk\Framework\Jellyfin\LibraryOverviewService;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use PHPUnit\Framework\TestCase;

final class MonitoringExclusionsCacheTest extends TestCase
{
    public function testDifferentExclusionContextInvalidatesFreshLibraryCache(): void
    {
        $cachePath = $this->temporaryCachePath();

        try {
            $first = new MonitoringExclusionsCacheClient(12);
            (new LibraryOverviewService(
                $first,
                new MonitoringExclusionsCacheHistory(),
                $cachePath,
                new MonitoringExclusions(['Admin']),
            ))->refreshCache();

            $second = new MonitoringExclusionsCacheClient(27);
            $payload = (new LibraryOverviewService(
                $second,
                new MonitoringExclusionsCacheHistory(),
                $cachePath,
                new MonitoringExclusions(['Viewer']),
            ))->cachedPayload();

            self::assertSame(1, $second->folderCalls);
            self::assertSame(27, $payload['libraries'][0]['totalFilesRaw']);
            self::assertFalse($payload['cached']);
            self::assertSame((new MonitoringExclusions(['Viewer']))->fingerprint(), $payload['monitoring_context']);
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.lock');
        }
    }

    public function testSameExclusionContextMayUseStaleCacheDuringOutage(): void
    {
        $cachePath = $this->temporaryCachePath();
        $policy = new MonitoringExclusions(['Admin']);

        try {
            $this->writeStaleCache($cachePath, $policy, 12);
            $outage = new MonitoringExclusionsCacheClient(99, true);

            $payload = (new LibraryOverviewService(
                $outage,
                new MonitoringExclusionsCacheHistory(),
                $cachePath,
                $policy,
            ))->cachedPayload();

            self::assertSame(1, $outage->folderCalls);
            self::assertSame(12, $payload['libraries'][0]['totalFilesRaw']);
            self::assertTrue($payload['cached']);
            self::assertTrue($payload['stale']);
            self::assertSame('Showing cached library stats', $payload['refreshedLabel']);
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.lock');
        }
    }

    public function testDifferentExclusionContextNeverUsesStaleCacheDuringOutage(): void
    {
        $cachePath = $this->temporaryCachePath();

        try {
            $this->writeStaleCache($cachePath, new MonitoringExclusions(['Admin']), 12);
            $before = file_get_contents($cachePath);
            $outage = new MonitoringExclusionsCacheClient(99, true);

            try {
                (new LibraryOverviewService(
                    $outage,
                    new MonitoringExclusionsCacheHistory(),
                    $cachePath,
                    new MonitoringExclusions(['Viewer']),
                ))->cachedPayload();
                self::fail('A stale cache from a different exclusion context was exposed.');
            } catch (RuntimeException $e) {
                self::assertSame('Jellyfin unavailable; no library cache is available.', $e->getMessage());
            }

            self::assertSame(1, $outage->folderCalls);
            self::assertSame($before, file_get_contents($cachePath));
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.lock');
        }
    }

    public function testDefaultPolicyFailsWhenApplicationSettingsAreUnavailable(): void
    {
        $cache = new ReflectionProperty(AppSettings::class, 'cache');
        $available = new ReflectionProperty(AppSettings::class, 'available');
        $previousCache = $cache->getValue();
        $previousAvailable = $available->getValue();

        try {
            $cache->setValue(null, []);
            $available->setValue(null, false);

            try {
                new MonitoringExclusions();
                self::fail('The default policy silently used fallback settings.');
            } catch (RuntimeException $e) {
                self::assertSame('Application settings are unavailable.', $e->getMessage());
            }
        } finally {
            $cache->setValue(null, $previousCache);
            $available->setValue(null, $previousAvailable);
        }
    }

    private function temporaryCachePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-monitoring-cache-' . bin2hex(random_bytes(8)) . '.json';
    }

    private function writeStaleCache(string $cachePath, MonitoringExclusions $policy, int $count): void
    {
        (new LibraryOverviewService(
            new MonitoringExclusionsCacheClient($count),
            new MonitoringExclusionsCacheHistory(),
            $cachePath,
            $policy,
        ))->refreshCache();

        $payload = json_decode((string) file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['generated_at'] = 1;
        file_put_contents($cachePath, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}

final class MonitoringExclusionsCacheClient implements LibraryOverviewClient
{
    public int $folderCalls = 0;

    public function __construct(private int $count, private bool $unavailable = false)
    {
    }

    public function mediaFolders(): array
    {
        ++$this->folderCalls;
        if ($this->unavailable) {
            throw new RuntimeException('Unavailable');
        }

        return [['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies']];
    }

    public function itemCount(array $query): int
    {
        return (string) ($query['IncludeItemTypes'] ?? '') === 'Movie' ? $this->count : 0;
    }
}

final class MonitoringExclusionsCacheHistory implements LibraryHistorySource
{
    public function itemPlaySummaries(): array
    {
        return [];
    }
}
