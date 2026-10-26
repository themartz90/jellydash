<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestSyncService;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestSyncServiceTest extends TestCase
{
    private string $databasePath = '';
    private string $databaseName = '';
    private ?\Dibi\Connection $admin = null;
    private Database $database;
    private SeerrRequestRepository $repository;

    protected function setUp(): void
    {
        $this->database = $this->isolatedDatabase();
        $this->repository = new SeerrRequestRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^jellydash_phpunit_request_sync_[a-z0-9_]+$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
        if ($this->databasePath !== '') {
            foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    public function testFirstSyncBootstrapsOnlyNewestPageWithoutNotifications(): void
    {
        $client = new FakePagedJellyseerrClient([
            0 => $this->requests(80, 41),
            40 => $this->requests(40, 1),
        ]);

        $added = (new RequestSyncService($client, $this->repository))->sync();

        $this->assertSame(40, $added);
        $this->assertSame([0], $client->requestedSkips);
        $this->assertSame(40, $this->repository->count());
        $this->assertSame(40, (int) $this->database->getDibi()->select('COUNT(*)')->from('seerr_requests')->where('notified = 1')->fetchSingle());
    }

    public function testFailedDetailsRecoverAfterBackoffEvenOutsideTheCurrentPage(): void
    {
        $logger = \Mk\Framework\Container::logger();
        \Mk\Framework\Container::set('logger', new \Psr\Log\NullLogger());
        $now = 1_800_000_000;
        $client = new FakePagedJellyseerrClient([0 => $this->requests(901, 901)]);
        $client->failDetails = true;
        $sync = new RequestSyncService($client, $this->repository, static function () use (&$now): int {
            return $now;
        });
        try {
            $this->assertSame(1, $sync->sync());
            $this->assertSame('Unknown title', $this->repository->latest()[0]['title']);
            $client->pages = [];
            $client->failDetails = false;
            $this->assertSame(0, $sync->sync());
            $this->assertSame(1, $client->detailCalls);
            $now += 61;
            $this->assertSame(0, $sync->sync());
            $row = $this->repository->latest()[0];
            $this->assertSame('Movie 10901', $row['title']);
            $this->assertSame('2026', $row['year']);
            $this->assertSame('/10901.jpg', $row['poster_path']);
            $this->assertSame(1, (int) $row['notified']);
            $this->assertSame(2, $client->detailCalls);
        } finally {
            \Mk\Framework\Container::set('logger', $logger);
        }
    }

    public function testOldPlaceholdersAreRepairedInBoundedBatchesWithoutChangingNotifications(): void
    {
        for ($id = 1; $id <= 6; $id++) {
            $this->insertKnown($id, 2);
            $this->database->getDibi()->update('seerr_requests', ['title' => 'Unknown title', 'year' => null])
                ->where('request_id = %i', $id)->execute();
        }
        $client = new FakePagedJellyseerrClient([]);
        $sync = new RequestSyncService($client, $this->repository);
        $this->assertSame(0, $sync->sync());
        $this->assertSame(5, $client->detailCalls);
        $this->assertSame(1, (int) $this->database->getDibi()->select('COUNT(*)')->from('seerr_requests')->where('title = %s', 'Unknown title')->fetchSingle());
        $this->assertSame(0, $sync->sync());
        $this->assertSame(6, $client->detailCalls);
        $this->assertSame(6, (int) $this->database->getDibi()->select('COUNT(*)')->from('seerr_requests')->where('notified = 1')->fetchSingle());
        $this->assertSame(0, $sync->sync());
        $this->assertSame(6, $client->detailCalls);
    }

    public function testMetadataRetriesBackOffAndStaleFailuresCannotUndoRecovery(): void
    {
        $logger = \Mk\Framework\Container::logger();
        \Mk\Framework\Container::set('logger', new \Psr\Log\NullLogger());
        $now = 1_800_000_000;
        $client = new FakePagedJellyseerrClient([0 => $this->requests(901, 901)]);
        $client->failDetails = true;
        $sync = new RequestSyncService($client, $this->repository, static function () use (&$now): int {
            return $now;
        });
        try {
            $sync->sync();
            $this->assertSame($now + 60, (int) $this->repository->latest()[0]['metadata_retry_at_epoch']);
            $client->pages = [];
            $now += 61;
            $sync->sync();
            $this->assertSame($now + 120, (int) $this->repository->latest()[0]['metadata_retry_at_epoch']);
            $sync->sync();
            $this->assertSame(2, $client->detailCalls);
            $now += 121;
            $stale = $this->repository->pendingMetadata(5, $now)[0];
            $client->failDetails = false;
            $sync->sync();
            $this->repository->finishMetadataAttempt($stale, null, $now);
            $row = $this->repository->latest()[0];
            $this->assertSame('Movie 10901', $row['title']);
            $this->assertSame(0, (int) $row['metadata_pending']);
            $this->assertNull($row['metadata_retry_at_epoch']);
        } finally {
            \Mk\Framework\Container::set('logger', $logger);
        }
    }

    public function testSuccessfulUnknownTitleIsNotMistakenForALegacyPlaceholder(): void
    {
        $this->insertKnown(900, 2);
        $this->database->getDibi()->update('seerr_requests', [
            'title' => 'Unknown title', 'year' => null, 'poster_path' => null, 'metadata_pending' => 0,
        ])->where('request_id = 900')->execute();
        $client = new FakePagedJellyseerrClient([]);
        (new RequestSyncService($client, $this->repository))->sync();
        $this->assertSame(0, $client->detailCalls);
    }

    public function testSyncFetchesPastNewestFortyUntilKnownBoundary(): void
    {
        $this->insertKnown(100, 1);
        $client = new FakePagedJellyseerrClient([
            0 => $this->requests(160, 121),
            40 => $this->requests(120, 100),
        ]);

        $added = (new RequestSyncService($client, $this->repository))->sync();

        $this->assertSame(60, $added);
        $this->assertSame([0, 40], $client->requestedSkips);
        $this->assertSame(61, $this->repository->count());
        $this->assertSame(0, (int) $this->database->getDibi()->select('notified')->from('seerr_requests')->where('request_id = 101')->fetchSingle());
        $this->assertSame(1, (int) $this->database->getDibi()->select('request_status')->from('seerr_requests')->where('request_id = 100')->fetchSingle());

        $claims = $this->repository->claimUnnotified((new DateTimeImmutable('2026-09-17T12:00:00Z'))->getTimestamp());
        $this->assertSame([], $claims);
        $this->assertSame(60, (int) $this->database->getDibi()->select('COUNT(*)')
            ->from('seerr_requests')->where('notified = 1')->where('request_id > %i', 100)->fetchSingle());
    }

    #[DataProvider('disabledNotificationSwitches')]
    public function testDisabledNotificationsRetirePendingAndNewRequestsBeforeReenable(string $switch): void
    {
        $this->insertKnown(100, 1);
        $this->repository->insert([
            'request_id' => 99,
            'media_type' => 'movie',
            'tmdb_id' => 10099,
            'title' => 'Pending request',
            'requested_at' => '2026-09-17 12:00:00',
            'created_at' => '2026-09-17 12:00:00',
            'notified' => 0,
        ]);
        $client = new FakePagedJellyseerrClient([
            0 => $this->requests(101, 100, '2026-09-17T12:00:00Z'),
        ]);
        $previous = getenv($switch);

        try {
            putenv($switch . '=false');
            $this->assertSame(1, (new RequestSyncService($client, $this->repository))->sync());
        } finally {
            putenv($previous === false ? $switch : $switch . '=' . $previous);
        }

        $this->assertSame(1, (int) $this->database->getDibi()->select('notified')
            ->from('seerr_requests')->where('request_id = %i', 99)->fetchSingle());
        $this->assertSame(1, (int) $this->database->getDibi()->select('notified')
            ->from('seerr_requests')->where('request_id = %i', 101)->fetchSingle());
        $this->assertSame([], $this->repository->claimUnnotified((new DateTimeImmutable('2026-09-17T12:01:00Z'))->getTimestamp()));
    }

    /** @return array<string, array{string}> */
    public static function disabledNotificationSwitches(): array
    {
        return ['master' => ['PUSH_ENABLED'], 'requests' => ['SEERR_NOTIFY_ENABLED']];
    }

    public function testFailedLaterPageMakesNoPartialWrites(): void
    {
        $this->insertKnown(100, 7);
        $client = new FakePagedJellyseerrClient([0 => $this->requests(160, 121)], 40);

        try {
            (new RequestSyncService($client, $this->repository))->sync();
            $this->fail('Expected the second page to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated page failure.', $e->getMessage());
        }

        $this->assertSame(1, $this->repository->count());
        $this->assertSame(7, (int) $this->database->getDibi()->select('request_status')->from('seerr_requests')->where('request_id = 100')->fetchSingle());
    }

    public function testFailedBatchWriteRollsBackStatusesAndNewRequests(): void
    {
        $this->insertKnown(100, 7);
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->database->getDibi()->query(
                'CREATE TRIGGER fail_request_sync BEFORE INSERT ON seerr_requests WHEN NEW.request_id = 150 BEGIN SELECT RAISE(ABORT, "simulated write failure"); END'
            );
        } else {
            $this->database->getDibi()->query(
                "CREATE TRIGGER fail_request_sync BEFORE INSERT ON seerr_requests FOR EACH ROW BEGIN IF NEW.request_id = 150 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated write failure'; END IF; END"
            );
        }
        $client = new FakePagedJellyseerrClient([
            0 => $this->requests(160, 121),
            40 => $this->requests(120, 100),
        ]);

        try {
            (new RequestSyncService($client, $this->repository))->sync();
            $this->fail('Expected the batch write to fail.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('simulated write failure', $e->getMessage());
        }

        $this->assertSame(1, $this->repository->count());
        $this->assertSame(7, (int) $this->database->getDibi()->select('request_status')->from('seerr_requests')->where('request_id = 100')->fetchSingle());
    }

    public function testRepeatedFullPageAbortsBeforeWrites(): void
    {
        $this->insertKnown(100, 7);
        $page = $this->requests(160, 121);
        $client = new FakePagedJellyseerrClient([0 => $page, 40 => $page]);

        $this->expectExceptionMessage('repeated a request page');
        try {
            (new RequestSyncService($client, $this->repository))->sync();
        } finally {
            $this->assertSame(1, $this->repository->count());
        }
    }

    public function testRequestedAtUsesConfiguredAppTimezone(): void
    {
        $this->withTimezoneEnvironment('America/New_York', 'Europe/London', function (): void {
            date_default_timezone_set('UTC');

            $requestedAt = (new \ReflectionClass(RequestSyncService::class))
                ->getMethod('requestedAt')
                ->invoke(
                    new RequestSyncService(),
                    ['createdAt' => '2026-08-09T12:00:00Z'],
                    'fallback'
                );

            $this->assertSame('2026-08-09 08:00:00', $requestedAt);
        });
    }

    #[DataProvider('notificationTimingCases')]
    public function testFreshRequestClaimsUseElapsedTimeAndPollingCadence(string $createdAt, string $now, string $interval): void
    {
        $this->withTimezoneEnvironment('Europe/Prague', null, function () use ($createdAt, $now, $interval): void {
            $previous = getenv('SEERR_POLL_INTERVAL');
            putenv('SEERR_POLL_INTERVAL=' . $interval);
            try {
                $this->insertKnown(100, 1);
                $client = new FakePagedJellyseerrClient([0 => $this->requests(101, 100, $createdAt)]);
                self::assertSame(1, (new RequestSyncService($client, $this->repository))->sync());
                $claims = $this->repository->claimUnnotified((new DateTimeImmutable($now))->getTimestamp());
                self::assertCount(1, $claims);
                self::assertSame(101, (int) $claims[0]['request_id']);
            } finally {
                putenv($previous === false ? 'SEERR_POLL_INTERVAL' : 'SEERR_POLL_INTERVAL=' . $previous);
            }
        });
    }

    /** @return array<string, array{string, string, string}> */
    public static function notificationTimingCases(): array
    {
        return [
            'fall back' => ['2026-10-25T01:04:00Z', '2026-10-25T01:05:00Z', '120'],
            'spring forward' => ['2026-03-29T00:59:00Z', '2026-03-29T01:01:00Z', '120'],
            'fifteen minute poll' => ['2026-09-17T12:01:00Z', '2026-09-17T12:15:00Z', '900'],
            'daily poll with processing time' => ['2026-09-16T12:00:01Z', '2026-09-17T12:00:30Z', '86400'],
        ];
    }

    public function testRepeatedLocalHourDoesNotMakeAnOldRequestFresh(): void
    {
        $this->withTimezoneEnvironment('Europe/Prague', null, function (): void {
            $this->insertKnown(100, 1);
            $client = new FakePagedJellyseerrClient([0 => $this->requests(101, 100, '2026-10-25T00:20:00Z')]);
            (new RequestSyncService($client, $this->repository))->sync();
            self::assertSame([], $this->repository->claimUnnotified((new DateTimeImmutable('2026-10-25T01:21:00Z'))->getTimestamp()));
        });
    }

    public function testSyncRestoresTheExactInstantForPendingLegacyRows(): void
    {
        $this->withTimezoneEnvironment('Europe/Prague', null, function (): void {
            $this->insertKnown(100, 1);
            $this->database->getDibi()->update('seerr_requests', ['notified' => 0])->execute();
            $client = new FakePagedJellyseerrClient([0 => $this->requests(100, 100, '2026-10-25T01:04:00Z')]);
            self::assertSame(0, (new RequestSyncService($client, $this->repository))->sync());
            $claims = $this->repository->claimUnnotified((new DateTimeImmutable('2026-10-25T01:05:00Z'))->getTimestamp());
            self::assertCount(1, $claims);
            self::assertSame(100, (int) $claims[0]['request_id']);
            self::assertSame('Known request', (string) $claims[0]['title']);
        });
    }

    public function testLegacyRowsWithoutAnExactInstantDoNotReplay(): void
    {
        $this->insertKnown(100, 1);
        $this->database->getDibi()->update('seerr_requests', ['notified' => 0])->execute();
        self::assertSame([], $this->repository->claimUnnotified());
        self::assertSame(1, $this->repository->count());
        self::assertSame('Known request', (string) $this->repository->latest()[0]['title']);
    }

    public function testSlowPollingStillRetiresRequestsOlderThanItsFreshnessWindow(): void
    {
        $previous = getenv('SEERR_POLL_INTERVAL');
        putenv('SEERR_POLL_INTERVAL=900');
        try {
            $this->insertKnown(100, 1);
            $client = new FakePagedJellyseerrClient([0 => $this->requests(101, 100, '2026-09-17T11:00:00Z')]);
            (new RequestSyncService($client, $this->repository))->sync();
            self::assertSame([], $this->repository->claimUnnotified((new DateTimeImmutable('2026-09-17T12:15:00Z'))->getTimestamp()));
        } finally {
            putenv($previous === false ? 'SEERR_POLL_INTERVAL' : 'SEERR_POLL_INTERVAL=' . $previous);
        }
    }

    public function testRequestedAtUsesDockerTimezoneWhenAppTimezoneIsMissing(): void
    {
        $this->withTimezoneEnvironment(null, 'America/New_York', function (): void {
            date_default_timezone_set('UTC');

            $requestedAt = (new \ReflectionClass(RequestSyncService::class))
                ->getMethod('requestedAt')
                ->invoke(
                    new RequestSyncService(),
                    ['createdAt' => '2026-08-09T12:00:00Z'],
                    'fallback'
                );

            $this->assertSame('2026-08-09 08:00:00', $requestedAt);
        });
    }

    private function withTimezoneEnvironment(?string $appTimezone, ?string $dockerTimezone, callable $assertion): void
    {
        $originalTimezone = date_default_timezone_get();
        $snapshot = [];

        foreach (['APP_TIMEZONE', 'TZ'] as $key) {
            $snapshot[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            if ($appTimezone !== null) {
                putenv('APP_TIMEZONE=' . $appTimezone);
            }
            if ($dockerTimezone !== null) {
                putenv('TZ=' . $dockerTimezone);
            }

            $assertion();
        } finally {
            date_default_timezone_set($originalTimezone);

            foreach ($snapshot as $key => $values) {
                $process = $values['process'];
                putenv($process === false ? $key : $key . '=' . $process);

                if ($values['env_exists']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($values['server_exists']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function requests(int $newest, int $oldest, string $createdAt = '2026-09-08T12:00:00Z'): array
    {
        $requests = [];
        for ($id = $newest; $id >= $oldest; --$id) {
            $requests[] = [
                'id' => $id,
                'status' => 1,
                'media' => ['mediaType' => 'movie', 'tmdbId' => 10000 + $id, 'status' => 2],
                'createdAt' => $createdAt,
            ];
        }

        return $requests;
    }

    private function insertKnown(int $requestId, int $status): void
    {
        $this->repository->insert([
            'request_id' => $requestId,
            'media_type' => 'movie',
            'tmdb_id' => 10000 + $requestId,
            'title' => 'Known request',
            'year' => '2026',
            'poster_path' => null,
            'requested_by' => null,
            'request_status' => $status,
            'media_status' => 2,
            'is_4k' => 0,
            'season_count' => null,
            'requested_at' => '2026-09-01 12:00:00',
            'notified' => 1,
            'created_at' => '2026-09-01 12:00:00',
        ]);
    }

    private function isolatedDatabase(): Database
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-request-sync-' . bin2hex(random_bytes(8)) . '.sqlite';

            return Database::sqlite($this->databasePath);
        }

        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
        ];
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }

        $this->admin = new \Dibi\Connection($config);
        $this->databaseName = 'jellydash_phpunit_request_sync_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
        $config['database'] = $this->databaseName;

        return new Database(new \Dibi\Connection($config));
    }
}

final class FakePagedJellyseerrClient extends JellyseerrClient
{
    /** @var array<int, int> */
    public array $requestedSkips = [];
    public bool $failDetails = false;
    public int $detailCalls = 0;

    /** @param array<int, array<int, array<string, mixed>>> $pages */
    public function __construct(public array $pages, private ?int $failingSkip = null)
    {
        parent::__construct('http://jellyseerr.test', 'token');
    }

    public function requestPage(int $take, int $skip): array
    {
        $this->requestedSkips[] = $skip;
        if ($skip === $this->failingSkip) {
            throw new RuntimeException('Simulated page failure.');
        }

        return $this->pages[$skip] ?? [];
    }

    public function movie(int $tmdbId): array
    {
        $this->detailCalls++;
        if ($this->failDetails) {
            throw new RuntimeException('Synthetic detail lookup failure.');
        }
        return ['title' => 'Movie ' . $tmdbId, 'releaseDate' => '2026-01-01', 'posterPath' => '/' . $tmdbId . '.jpg'];
    }
}
