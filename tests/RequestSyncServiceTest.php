<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestSyncService;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
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
    private function requests(int $newest, int $oldest): array
    {
        $requests = [];
        for ($id = $newest; $id >= $oldest; --$id) {
            $requests[] = [
                'id' => $id,
                'status' => 1,
                'media' => ['mediaType' => 'movie', 'tmdbId' => 10000 + $id, 'status' => 2],
                'createdAt' => '2026-09-08T12:00:00Z',
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

    /** @param array<int, array<int, array<string, mixed>>> $pages */
    public function __construct(private array $pages, private ?int $failingSkip = null)
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
        return ['title' => 'Movie ' . $tmdbId, 'releaseDate' => '2026-01-01', 'posterPath' => null];
    }
}
