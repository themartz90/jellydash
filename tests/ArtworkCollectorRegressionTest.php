<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\ArtworkCollector;
use Mk\Framework\Jellyfin\ArtworkFetcher;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ArtworkCollectorRegressionTest extends TestCase
{
    private Database $database;
    private \Dibi\Connection $db;
    private ArtworkRepository $repository;
    private PlayHistoryRepository $history;
    private string $scope;
    private string $baseUrl;
    private string $sessionPrefix;
    /** @var array<string, array<string, mixed>> */
    private array $metadata = [];

    protected function setUp(): void
    {
        $this->database = Container::db();
        $this->db = $this->database->getDibi();
        $this->sessionPrefix = 'art-regression-' . bin2hex(random_bytes(8)) . '-';
        $this->baseUrl = 'https://' . bin2hex(random_bytes(8)) . '.invalid';
        $this->scope = ThemePlaybackExclusions::serverKey($this->baseUrl);
        $this->repository = new ArtworkRepository($this->database, $this->scope);
        $this->history = new PlayHistoryRepository($this->database, new MonitoringExclusions([]));
        $this->metadata = [];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->repository)) {
            return;
        }
        $this->db->delete('play_history')->where('session_key LIKE %s', $this->sessionPrefix . '%')->execute();
        foreach (['artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            $this->db->delete($table)->where('source_key = %s', $this->scope)->execute();
        }
    }

    public function testRememberWithPosterRollsBackItemAndJobWhenPosterInsertFails(): void
    {
        if (!$this->database->getPlatform()->isSqlite()) {
            self::markTestSkipped('The transactional trigger fixture is SQLite specific.');
        }

        $trigger = 'artwork_regression_fail_' . substr($this->scope, 0, 16);
        $this->db->query(
            "CREATE TRIGGER $trigger BEFORE INSERT ON artwork_posters WHEN NEW.source_key = %s BEGIN SELECT RAISE(ABORT, 'synthetic poster failure'); END",
            $this->scope,
        );
        $item = $this->item('retry-movie', 'Movie', 'retry-movie', 100);
        try {
            try {
                $this->repository->rememberWithPoster($item, 'tag-a');
                self::fail('The poster trigger should abort the complete observation write.');
            } catch (\Dibi\DriverException $error) {
                self::assertStringContainsString('synthetic poster failure', $error->getMessage());
            }
            self::assertNull($this->repository->item('retry-movie'));
            self::assertNull($this->repository->poster('retry-movie'));

            $this->db->query('DROP TRIGGER %n', $trigger);
            $this->repository->rememberWithPoster($item, 'tag-a');
            self::assertSame('ready', $this->repository->item('retry-movie')['status']);
            self::assertSame('tag-a', $this->repository->poster('retry-movie')['requested_tag']);
        } finally {
            $this->db->query('DROP TRIGGER IF EXISTS %n', $trigger);
        }
    }

    public function testJpegHeaderWithoutImageDataIsRejected(): void
    {
        try {
            ArtworkFetcher::validate("\xff\xd8\xff\xd9", 'image/jpeg');
            self::fail('A JPEG header without image data must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('invalid_image', $error->getMessage());
        }
    }

    public function testIgnoredRawHistoryRowsCannotStarveFreshEligibleArtwork(): void
    {
        $this->repository->anchor('server-art-regression');
        $this->repository->cursor(PHP_INT_MAX);
        for ($index = 0; $index < 50; ++$index) {
            $this->recordObservation(
                'ignored-' . sprintf('%02d', $index),
                'Movie',
                'Ignored',
                new DateTimeImmutable('@' . (1000 + $index)),
                'movie-ignored-' . sprintf('%02d', $index),
                'server-art-regression',
            );
        }
        $eligibleId = 'eligible-after-ignored-page';
        $this->recordObservation($eligibleId, 'Movie', 'Eligible', new DateTimeImmutable('@1100'), $eligibleId, 'server-art-regression');
        $this->metadata[$eligibleId] = ['Id' => $eligibleId, 'Type' => 'Movie', 'Name' => $eligibleId, 'ImageTags' => ['Primary' => 'eligible-tag']];

        $result = $this->collector(exclusions: new MonitoringExclusions(['Ignored']))->runBatch(1200);

        self::assertSame((int) (new DateTimeImmutable('@1100'))->format('Uu'), (int) $this->repository->item($eligibleId)['observed_at']);
        self::assertNull($this->repository->item('movie-ignored-00'));
        self::assertNull($result['error']);
    }

    public function testPreAnchorUuidlessObservationStaysInReviewUntilServerIdentityIsConfirmed(): void
    {
        $this->repository->cursor(PHP_INT_MAX);
        $itemId = 'pre-anchor-episode';
        $this->recordObservation($itemId, 'Episode', 'Eligible', new DateTimeImmutable('@1000'), 'series-pre-anchor', '');
        $this->metadata['series-pre-anchor'] = [
            'Id' => 'series-pre-anchor', 'Type' => 'Series', 'Name' => 'Pre-anchor Show', 'ImageTags' => ['Primary' => 'series-tag'],
        ];

        $this->collector()->runBatch(1200);

        $item = $this->repository->item($itemId);
        self::assertNotNull($item);
        self::assertSame('review', $item['status']);
        self::assertSame('provisional', $item['evidence']);
        self::assertNull($item['poster_id']);
        self::assertNull($this->repository->poster('series-pre-anchor'));
        self::assertGreaterThan(1_000_000_000, (int) ($this->repository->source()['anchored_at'] ?? 0));

        $confirmedAt = (new DateTimeImmutable('now'))->modify('+1 second');
        $this->recordObservation($itemId, 'Episode', 'Eligible', $confirmedAt, 'series-pre-anchor', 'server-art-regression');
        $futureNow = $confirmedAt->getTimestamp() + 2;
        $this->collector()->runBatch($futureNow);

        self::assertSame('ready', $this->repository->item($itemId)['status']);
        self::assertSame('series-pre-anchor', $this->repository->item($itemId)['poster_id']);
    }

    public function testFailedTagRefreshKeepsLastGoodImageAndRecordsRetryForCurrentRevision(): void
    {
        $this->repository->anchor('server-art-regression');
        $this->repository->cursor(PHP_INT_MAX);
        $this->repository->enqueue('series-refresh', 'Series', 'tag-a', 100);
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        $job = $this->repository->jobs(100)[0];
        self::assertTrue($this->repository->publish($job, $this->gif(), 'image/gif', $lease, 101));
        $this->repository->release($lease);

        $this->metadata['series-refresh'] = [
            'Id' => 'series-refresh', 'Type' => 'Series', 'Name' => 'Refresh Show', 'ImageTags' => ['Primary' => 'tag-b'],
        ];
        $this->repository->enqueue('series-refresh', 'Series', 'tag-b', 200);
        $revision = (int) $this->repository->poster('series-refresh')['revision'];
        $collector = $this->collector(fetcher: static function (string $id, ?string $tag): array {
            throw new RuntimeException('request_failed');
        });

        $result = $collector->runBatch(201);

        self::assertNull($result['error']);
        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['saved']);
        self::assertSame($this->gif(), $this->repository->image('series-refresh')['body']);
        self::assertSame('tag-a', $this->repository->image('series-refresh')['tag']);
        $poster = $this->repository->poster('series-refresh');
        self::assertSame($revision, (int) $poster['revision']);
        self::assertGreaterThan(0, (int) $poster['attempts']);
        self::assertGreaterThan(201, (int) $poster['next_attempt']);
        self::assertSame('request_failed', $poster['last_error']);
    }

    public function testSmallJobPageIncludesAnAlreadyCheckedPosterThatIsDueAgain(): void
    {
        $this->repository->anchor('server-art-regression');
        $this->repository->enqueue('refresh-due', 'Movie', 'old-tag', 100);
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        self::assertTrue($this->repository->publish($this->repository->jobs(100)[0], $this->gif(), 'image/gif', $lease, 100));
        $this->repository->release($lease);

        $now = 86_500;
        for ($index = 0; $index < 12; ++$index) {
            $id = 'new-poster-' . $index;
            $this->repository->enqueue($id, 'Movie', 'tag-' . $index, $now);
        }

        $jobs = $this->repository->jobs($now, 4);

        self::assertCount(4, $jobs);
        self::assertContains('refresh-due', array_column($jobs, 'poster_id'));
    }

    /** @param (\Closure(string, ?string): array{body: string, contentType: string})|null $fetcher */
    private function collector(?\Closure $fetcher = null, ?MonitoringExclusions $exclusions = null): ArtworkCollector
    {
        $client = new JellyfinClient(
            $this->baseUrl,
            'synthetic-token',
            true,
            function (string $path, string $method, ?array $payload, int $timeout): mixed {
                self::assertSame('GET', $method);
                self::assertSame(4, $timeout);
                if ($path === '/System/Info/Public') {
                    return ['Id' => 'server-art-regression'];
                }
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $item = $this->metadata[(string) ($query['Ids'] ?? '')] ?? null;
                return ['Items' => $item === null ? [] : [$item], 'TotalRecordCount' => $item === null ? 0 : 1];
            }
        );

        return new ArtworkCollector(
            $this->database,
            $client,
            $this->repository,
            $fetcher ?? static fn (string $id, ?string $tag): array => ['body' => self::gif(), 'contentType' => 'image/gif'],
            $exclusions ?? new MonitoringExclusions([])
        );
    }

    private function recordObservation(
        string $itemId,
        string $type,
        string $user,
        DateTimeImmutable $observedAt,
        string $posterId,
        string $serverId,
    ): void {
        $this->history->logActiveStreams([[
            'id' => $this->sessionPrefix . $itemId,
            'itemId' => $itemId,
            'itemType' => $type,
            'itemName' => $type === 'Episode' ? 'Pilot' : $itemId,
            'seriesName' => $type === 'Episode' ? 'Pre-anchor Show' : null,
            'userId' => 'regression-user',
            'user' => $user,
            'playMethod' => 'DirectPlay',
            'watchedSec' => 10,
            'runtimeSec' => 3600,
            'artworkSource' => $this->scope,
            'artworkServerId' => $serverId,
            'seriesId' => $type === 'Episode' ? $posterId : null,
            'posterTag' => 'observed-tag',
        ]], $observedAt);
    }

    /** @return array<string, mixed> */
    private function item(string $id, string $type, string $posterId, int $observedAt): array
    {
        return [
            'item_id' => $id,
            'item_type' => $type,
            'title' => 'Retry movie',
            'series_name' => '',
            'poster_id' => $posterId,
            'observed_at' => $observedAt,
            'evidence' => 'observed',
            'status' => 'ready',
            'next_attempt' => 0,
        ];
    }

    private static function gif(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    }
}
