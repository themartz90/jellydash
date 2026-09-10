<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\LibraryHistorySource;
use Mk\Framework\Jellyfin\LibraryOverviewClient;
use Mk\Framework\Jellyfin\LibraryOverviewService;
use PHPUnit\Framework\TestCase;

final class LibraryOverviewServiceTest extends TestCase
{
    public function testLegacyContributionsLabelLibraryAndSummaryTotalsAsEstimated(): void
    {
        $client = new FakeLibraryOverviewClient(
            [['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies']],
            ['movies|Movie' => 1],
        );
        foreach ([0, 1] as $estimatedPlays) {
            $history = new class ($estimatedPlays) implements LibraryHistorySource {
                public function __construct(private int $estimatedPlays)
                {
                }

                public function itemPlaySummaries(): array
                {
                    return [new \Dibi\Row([
                        'library' => 'Movies', 'plays' => 2, 'watch_sec' => 1800,
                        'estimated_plays' => $this->estimatedPlays,
                        'started_at' => '2026-09-08 12:00:00', 'series_name' => '',
                        'item_name' => 'Movie', 'user_name' => 'Viewer',
                    ])];
                }
            };
            $data = (new LibraryOverviewService($client, $history))->data();
            $this->assertSame($estimatedPlays > 0, $data['libraries'][0]['playbackEstimated']);
            $this->assertSame(1800, $data['libraries'][0]['playbackRaw']);
            $this->assertSame($estimatedPlays > 0 ? 'Estimated Playback' : 'Total Playback', $data['summary'][2]['label']);
        }
    }

    public function testRelativeTimeUsesSingularForOneMinute(): void
    {
        $service = new LibraryOverviewService();
        $relativeTime = new \ReflectionMethod($service, 'relativeTime');

        $this->assertSame('1 minute ago', $relativeTime->invoke($service, new \DateTimeImmutable('@' . (time() - 60))));
        $this->assertSame('2 minutes ago', $relativeTime->invoke($service, new \DateTimeImmutable('@' . (time() - 120))));
    }

    public function testLargeLibrariesUseCountsWithoutEnumeratingEveryItem(): void
    {
        $client = new FakeLibraryOverviewClient(
            [
                ['Id' => 'tv', 'Name' => 'TV Shows', 'CollectionType' => 'tvshows'],
                ['Id' => 'music', 'Name' => 'Music', 'CollectionType' => 'music'],
            ],
            [
                'tv|Series' => 1200,
                'tv|Season' => 6200,
                'tv|Episode' => 55738,
                'music|MusicArtist' => 8200,
                'music|MusicAlbum' => 18900,
                'music|Audio' => 193414,
            ],
        );

        $data = (new LibraryOverviewService($client, new FakeLibraryHistorySource()))->data();

        $this->assertCount(2, $data['libraries']);
        $this->assertSame(0, $client->itemCalls);
        $this->assertSame(6, $client->countCalls);
        $this->assertSame(55738, $data['libraries'][0]['totalFilesRaw']);
        $this->assertSame(193414, $data['libraries'][1]['totalFilesRaw']);
    }

    public function testIncompleteRefreshKeepsACompleteStaleCache(): void
    {
        $cachePath = tempnam(sys_get_temp_dir(), 'jellydash-libraries-');
        self::assertIsString($cachePath);
        $cached = [
            'summary' => [['label' => 'Libraries', 'color' => '#7c5cff', 'value' => '2', 'sub' => 'selected media libraries']],
            'libraries' => [
                ['name' => 'Movies'],
                ['name' => 'TV Shows'],
            ],
            'refreshedLabel' => 'Live from Jellyfin',
            'generated_at' => 1,
            'cached' => false,
        ];
        file_put_contents($cachePath, json_encode($cached, JSON_THROW_ON_ERROR));

        $client = new FakeLibraryOverviewClient(
            [
                ['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies'],
                ['Id' => 'tv', 'Name' => 'TV Shows', 'CollectionType' => 'tvshows'],
            ],
            [
                'movies|Movie' => 3349,
                'movies|Video' => 0,
            ],
            ['tv'],
        );

        try {
            $payload = (new LibraryOverviewService($client, new FakeLibraryHistorySource(), $cachePath))->cachedPayload();

            $this->assertTrue($payload['cached']);
            $this->assertTrue($payload['stale']);
            $this->assertSame('Showing cached stats after an incomplete refresh', $payload['refreshedLabel']);
            $this->assertSame(['Movies', 'TV Shows'], array_column($payload['libraries'], 'name'));
            $this->assertSame($cached, json_decode((string) file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.retry');
        }
    }

    public function testLibraryHistoryUsesStoredRealLibraryNames(): void
    {
        $service = new LibraryOverviewService();
        $rows = [
            $this->play('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'Anime', 300, '2024-01-02 12:00:00', 'Naruto', 'Pilot'),
            $this->play('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'TV Shows', 120, '2024-01-01 12:00:00', 'The Expanse', 'Dulcinea'),
        ];

        $anime = $this->libraryHistory($service, $rows, 'Anime', 'Anime');
        $tv = $this->libraryHistory($service, $rows, 'TV Shows', 'TV Shows');

        $this->assertSame(1, $anime['plays']);
        $this->assertSame(300, $anime['watch_sec']);
        $this->assertSame('Naruto - S1 E1 - Pilot', $anime['last_played']);
        $this->assertSame(1, $tv['plays']);
        $this->assertSame(120, $tv['watch_sec']);
        $this->assertSame('The Expanse - S1 E1 - Dulcinea', $tv['last_played']);
    }

    public function testLibraryHistoryFallsBackToStoredNameWhenItemIsGone(): void
    {
        $service = new LibraryOverviewService();
        $rows = [
            $this->play('cccccccccccccccccccccccccccccccc', 'Movies', 90, '2024-01-03 12:00:00', null, 'Deleted Film'),
        ];

        $movies = $this->libraryHistory($service, $rows, 'Movies', 'Movies');
        $this->assertSame(1, $movies['plays']);
        $this->assertSame('Deleted Film', $movies['last_played']);
    }

    public function testLibraryHistoryCountsSummaryPlaysPerItem(): void
    {
        $service = new LibraryOverviewService();
        $rows = [[
            'item_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'library' => 'Anime',
            'plays' => 4,
            'watch_sec' => 800,
            'started_at' => '2024-01-02 12:00:00',
            'series_name' => 'Naruto',
            'item_name' => 'Pilot',
            'season_ep' => 'S1 E1',
            'user_name' => 'Maya',
        ]];
        $anime = $this->libraryHistory($service, $rows, 'Anime', 'Anime');
        $this->assertSame(4, $anime['plays']);
        $this->assertSame(800, $anime['watch_sec']);
    }

    public function testPartialFirstLoadShowsUnavailableLibraryWithoutWritingCache(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-libraries-' . bin2hex(random_bytes(8)) . '.json';
        $client = new FakeLibraryOverviewClient(
            [
                ['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies'],
                ['Id' => 'tv', 'Name' => 'TV Shows', 'CollectionType' => 'tvshows'],
            ],
            [
                'movies|Movie' => 3349,
                'movies|Video' => 0,
            ],
            ['tv'],
        );

        try {
            $payload = (new LibraryOverviewService($client, new FakeLibraryHistorySource(), $cachePath))->cachedPayload();

            $this->assertTrue($payload['partial']);
            $this->assertFalse($payload['cached']);
            $this->assertSame('Some library details unavailable', $payload['refreshedLabel']);
            $this->assertSame(['Movies', 'TV Shows'], array_column($payload['libraries'], 'name'));
            $this->assertTrue($payload['libraries'][0]['available']);
            $this->assertFalse($payload['libraries'][1]['available']);
            $this->assertSame('Unavailable', $payload['libraries'][1]['totalFiles']);
            $this->assertSame('Unavailable', $payload['summary'][1]['value']);
            $this->assertFileDoesNotExist($cachePath);
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.retry');
        }
    }

    public function testPartialFirstLoadUsesFailureBackoffWithoutReplacingTheGoodCache(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-libraries-' . bin2hex(random_bytes(8)) . '.json';
        $client = new FakeLibraryOverviewClient(
            [['Id' => 'tv', 'Name' => 'TV Shows', 'CollectionType' => 'tvshows']],
            [],
            ['tv'],
        );
        $now = 1_800_000_000;
        $service = new LibraryOverviewService(
            $client,
            new FakeLibraryHistorySource(),
            $cachePath,
            null,
            static fn (): int => $now,
            30,
        );

        try {
            $first = $service->cachedPayload();
            $second = $service->cachedPayload();

            $this->assertTrue($first['partial']);
            $this->assertFalse($first['cached']);
            $this->assertTrue($second['partial']);
            $this->assertTrue($second['cached']);
            $this->assertTrue($second['retrying']);
            $this->assertSame(1, $client->folderCalls);
            $this->assertFileDoesNotExist($cachePath);
            $this->assertFileExists($cachePath . '.retry');
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.retry');
            @unlink($cachePath . '.lock');
        }
    }

    public function testHistoryFailureIsShownAsUnavailableInsteadOfZero(): void
    {
        $client = new FakeLibraryOverviewClient(
            [['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies']],
            ['movies|Movie' => 4, 'movies|Video' => 0],
        );
        $history = new class () implements LibraryHistorySource {
            public function itemPlaySummaries(): array
            {
                throw new RuntimeException('History database is unavailable.');
            }
        };

        $data = (new LibraryOverviewService($client, $history))->data();

        $this->assertFalse($data['complete']);
        $this->assertFalse($data['historyAvailable']);
        $this->assertSame('Playback history unavailable', $data['refreshedLabel']);
        $this->assertFalse($data['libraries'][0]['playbackAvailable']);
        $this->assertSame('Unavailable', $data['libraries'][0]['totalPlays']);
        $this->assertSame('Unavailable', $data['libraries'][0]['playback']);
        $this->assertSame('Unavailable', $data['summary'][2]['value']);
        $this->assertSame('Unavailable', $data['summary'][3]['value']);
    }

    public function testLegacyPartialCacheDoesNotKeepDiscoveredLibrariesHidden(): void
    {
        $cachePath = tempnam(sys_get_temp_dir(), 'jellydash-libraries-');
        self::assertIsString($cachePath);
        $cached = [
            'summary' => [],
            'libraries' => [['name' => 'Movies']],
            'refreshedLabel' => 'Live from Jellyfin',
            'generated_at' => 1,
            'cached' => false,
        ];
        file_put_contents($cachePath, json_encode($cached, JSON_THROW_ON_ERROR));

        $client = new FakeLibraryOverviewClient(
            [
                ['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies'],
                ['Id' => 'music', 'Name' => 'Music', 'CollectionType' => 'music'],
            ],
            [
                'movies|Movie' => 3349,
                'movies|Video' => 0,
            ],
            ['music'],
        );

        try {
            $payload = (new LibraryOverviewService($client, new FakeLibraryHistorySource(), $cachePath))->cachedPayload();

            $this->assertTrue($payload['partial']);
            $this->assertFalse($payload['cached']);
            $this->assertSame(['Movies', 'Music'], array_column($payload['libraries'], 'name'));
            $this->assertFalse($payload['libraries'][1]['available']);
            $this->assertSame($cached, json_decode((string) file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.retry');
        }
    }

    public function testCompleteRefreshWritesReusableCache(): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-libraries-' . bin2hex(random_bytes(8)) . '.json';
        $client = new FakeLibraryOverviewClient(
            [['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies']],
            [
                'movies|Movie' => 3349,
                'movies|Video' => 7,
            ],
        );

        try {
            $payload = (new LibraryOverviewService($client, new FakeLibraryHistorySource(), $cachePath))->refreshCache();

            $this->assertFalse($payload['partial']);
            $this->assertFalse($payload['cached']);
            $this->assertSame(3356, $payload['libraries'][0]['totalFilesRaw']);
            $this->assertFileExists($cachePath);
            $this->assertSame($payload, json_decode((string) file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            @unlink($cachePath);
            @unlink($cachePath . '.retry');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{plays: int, watch_sec: int, last_activity: string, last_played: string, last_user: string}
     */
    private function libraryHistory(
        LibraryOverviewService $service,
        array $rows,
        string $displayName,
        string $actualName,
    ): array {
        $method = new \ReflectionMethod($service, 'libraryHistory');

        /** @var array{plays: int, watch_sec: int, last_activity: string, last_played: string, last_user: string} $result */
        $result = $method->invoke($service, $rows, $displayName, $actualName);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function play(string $itemId, string $library, int $watchedSec, string $startedAt, ?string $series, string $itemName): array
    {
        return [
            'item_id' => $itemId,
            'library' => $library,
            'watched_sec' => $watchedSec,
            'started_at' => $startedAt,
            'series_name' => $series,
            'item_name' => $itemName,
            'season_ep' => $series !== null ? 'S1 E1' : null,
            'user_name' => 'Maya',
        ];
    }
}

final class FakeLibraryOverviewClient implements LibraryOverviewClient
{
    public int $itemCalls = 0;
    public int $countCalls = 0;
    public int $folderCalls = 0;

    /**
     * @param array<int, array<string, mixed>> $folders
     * @param array<string, int> $counts
     * @param array<int, string> $failingParents
     */
    public function __construct(
        private array $folders,
        private array $counts,
        private array $failingParents = [],
    ) {
    }

    public function mediaFolders(): array
    {
        ++$this->folderCalls;

        return $this->folders;
    }

    public function items(array $query): array
    {
        ++$this->itemCalls;
        $parentId = (string) ($query['ParentId'] ?? '');
        if (in_array($parentId, $this->failingParents, true)) {
            throw new \RuntimeException('Timed out while enumerating ' . $parentId);
        }

        return [[
            'Id' => $parentId . '-item',
            'RunTimeTicks' => 600000000,
            'MediaSources' => [['Size' => 1048576]],
        ]];
    }

    public function itemCount(array $query): int
    {
        ++$this->countCalls;
        $parentId = (string) ($query['ParentId'] ?? '');
        if (in_array($parentId, $this->failingParents, true)) {
            throw new \RuntimeException('Timed out while counting ' . $parentId);
        }

        return $this->counts[$parentId . '|' . (string) ($query['IncludeItemTypes'] ?? '')] ?? 0;
    }
}

final class FakeLibraryHistorySource implements LibraryHistorySource
{
    public function itemPlaySummaries(): array
    {
        return [];
    }
}
