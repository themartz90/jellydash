<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyfin\HistoryLibraryBackfillService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class HistoryLibraryBackfillServiceTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_library_backfill_';

    private Database $database;
    private \Dibi\Connection $connection;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';
    private string $sqlitePath = '';
    private string $lockPath = '';

    protected function setUp(): void
    {
        $this->lockPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'jellydash-library-backfill-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';

        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->sqlitePath = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'jellydash-library-backfill-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
            $this->database = Database::sqlite($this->sqlitePath);
            $this->connection = $this->database->getDibi();

            return;
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
        $this->databaseName = self::DATABASE_PREFIX . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query(
            'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->databaseName,
        );
        $config['database'] = $this->databaseName;
        $this->connection = new \Dibi\Connection($config);
        $this->database = new Database($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }

        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/', $this->databaseName) !== 1) {
                throw new \RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }

        foreach ([$this->sqlitePath, $this->sqlitePath . '-shm', $this->sqlitePath . '-wal', $this->lockPath] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testBackfillsExistingRowsInResumableBatches(): void
    {
        $this->seedPlay('backfill-session-1', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Movies');
        $this->seedPlay('backfill-session-2', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'TV Shows');

        $requested = [];
        $service = $this->service(static function (array $ids) use (&$requested): array {
            $requested[] = $ids;
            $meta = [];
            foreach ($ids as $id) {
                $meta[$id] = [
                    'runtime_sec' => 3600,
                    'library' => str_starts_with($id, 'a') ? 'Anime Movies' : 'Comedy',
                ];
            }

            return $meta;
        });

        $this->assertSame([
            'state' => 'pending',
            'required' => true,
            'total' => 2,
            'processed' => 0,
            'percent' => 0,
            'busy' => false,
        ], $service->status());

        $first = $service->runBatch(1);
        $this->assertSame('running', $first['state']);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(50, $first['percent']);
        $this->assertSame([['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']], $requested);

        $second = $service->runBatch(1);
        $this->assertSame('complete', $second['state']);
        $this->assertFalse($second['required']);
        $this->assertSame(2, $second['processed']);
        $this->assertSame(100, $second['percent']);

        $rows = $this->connection->select('item_id, library, library_resolved_at, library_resolved_at_epoch')
            ->from('play_history')->orderBy('id')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame('Anime Movies', (string) $rows[0]['library']);
        $this->assertNotSame('', (string) $rows[0]['library_resolved_at']);
        $this->assertSame('Comedy', (string) $rows[1]['library']);
        $this->assertNotSame('', (string) $rows[1]['library_resolved_at']);
        foreach ($rows as $row) {
            $this->assertSame(
                (new DateTimeImmutable((string) $row['library_resolved_at']))->format('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', (int) $row['library_resolved_at_epoch']),
            );
        }
        $this->assertSame($second, $service->status());
    }

    public function testMissingJellyfinItemsAreSkippedWithoutBlockingCompletion(): void
    {
        $this->seedPlay('backfill-missing', 'cccccccccccccccccccccccccccccccc', 'Movies');
        $service = $this->service(static fn (array $ids): array => []);

        $result = $service->runBatch();

        $this->assertSame('complete', $result['state']);
        $row = $this->connection->select('library, library_resolved_at')->from('play_history')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('Movies', (string) $row['library']);
        $this->assertNull($row['library_resolved_at']);
    }

    public function testFailedLookupKeepsProgressResumable(): void
    {
        $this->seedPlay('backfill-retry', 'dddddddddddddddddddddddddddddddd', 'TV Shows');
        $attempts = 0;
        $service = $this->service(static function (array $ids) use (&$attempts): array {
            ++$attempts;
            if ($attempts === 1) {
                throw new \RuntimeException('Jellyfin is temporarily unavailable');
            }

            return [
                $ids[0] => ['runtime_sec' => 1800, 'library' => 'Documentaries'],
            ];
        });

        try {
            $service->runBatch();
            $this->fail('The failed lookup should be visible to the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Jellyfin is temporarily unavailable', $e->getMessage());
        }

        $this->assertSame(0, $service->status()['processed']);
        $result = $service->runBatch();
        $this->assertSame('complete', $result['state']);
        $this->assertSame('Documentaries', (string) $this->connection
            ->select('library')->from('play_history')->fetchSingle());
    }

    public function testRowsAddedAfterTheUpgradeStartsStayOutsideItsFixedTotal(): void
    {
        $this->seedPlay('backfill-existing-1', '11111111111111111111111111111111', 'Movies');
        $this->seedPlay('backfill-existing-2', '22222222222222222222222222222222', 'Movies');
        $service = $this->service(static function (array $ids): array {
            $meta = [];
            foreach ($ids as $id) {
                $meta[$id] = ['runtime_sec' => 3600, 'library' => 'Archive'];
            }

            return $meta;
        });

        $first = $service->runBatch(1);
        $this->assertSame(2, $first['total']);
        $this->seedPlay('backfill-future', '33333333333333333333333333333333', 'Movies');

        $complete = $service->runBatch(10);

        $this->assertSame('complete', $complete['state']);
        $this->assertSame(2, $complete['total']);
        $future = $this->connection->select('library, library_resolved_at')
            ->from('play_history')->where('session_key = %s', 'backfill-future')->fetch();
        $this->assertNotFalse($future);
        $this->assertSame('Movies', (string) $future['library']);
        $this->assertNull($future['library_resolved_at']);
    }

    public function testBackgroundContinuationDoesNotStartBeforeAUserSeesTheUpgrade(): void
    {
        $this->seedPlay('backfill-waiting', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'Movies');
        $calls = 0;
        $service = $this->service(static function (array $ids) use (&$calls): array {
            ++$calls;

            return [];
        });

        $status = $service->continueStartedBatch();

        $this->assertNull($status);
        $this->assertSame(0, $calls);
    }

    /** @param callable(array<int, string>): array<string, array{runtime_sec: int, library: string}> $loader */
    private function service(callable $loader): HistoryLibraryBackfillService
    {
        return new HistoryLibraryBackfillService($this->database, $loader, $this->lockPath);
    }

    private function seedPlay(string $session, string $itemId, string $library): void
    {
        (new PlayHistoryRepository($this->database))->logActiveStreams([[
            'id' => $session,
            'itemId' => $itemId,
            'itemType' => 'Movie',
            'itemName' => 'Backfill test item',
            'library' => $library,
            'libraryResolved' => false,
            'playMethod' => 'DirectPlay',
            'watchedSec' => 300,
            'runtimeSec' => 3600,
        ]], new \DateTimeImmutable('2026-08-20 12:00:00'));
    }
}
