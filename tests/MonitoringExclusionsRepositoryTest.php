<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class MonitoringExclusionsRepositoryTest extends TestCase
{
    private Database $database;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';

    protected function setUp(): void
    {
        $this->database = $this->isolatedDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
    }

    public function testExcludedUsersAreHiddenFromEveryUserFacingReadButRowsRemainStored(): void
    {
        $repo = new PlayHistoryRepository($this->database, new MonitoringExclusions(['Admin', 'ÄITI']));
        $this->insert('excluded', 'ADMIN', 'Secret', 600);
        $this->insert('unicode', 'äiti', 'Family', 300);
        $this->insert('accent', 'Ádmin', 'Accent', 120);
        $this->insert('allowed', 'Viewer', 'Movies', 180);
        $this->insert('anonymous', null, 'Other', 60);
        $filters = new HistoryFilters(range: 'all');

        $this->assertSame(5, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->fetchSingle());
        $this->assertSame(3, $repo->totalRows());
        $this->assertSame(3, $repo->historyTotal($filters));
        $this->assertCount(3, $repo->historyRows($filters));
        $this->assertCount(3, iterator_to_array($repo->historyExportRows($filters)));
        $this->assertSame(360, $repo->historyAggregate($filters)['watch_sec']);
        $this->assertCount(3, $repo->statisticsRowsForPeriod(null, null));
        $this->assertCount(3, $repo->itemPlaySummaries());
        $this->assertEqualsCanonicalizing(['Ádmin', 'Viewer'], $repo->users());
        $this->assertEqualsCanonicalizing(['ADMIN', 'Viewer', 'Ádmin', 'äiti'], $repo->users(true));
        $this->assertEqualsCanonicalizing(['Accent', 'Movies', 'Other'], $repo->libraries());
        $this->assertSame(360, $repo->watchTimeToday(new DateTimeImmutable('2099-06-19 12:00:00')));
        $this->assertTrue($repo->watchTimeTodayIsEstimated(new DateTimeImmutable('2099-06-19 12:00:00')));

        $cleared = new PlayHistoryRepository($this->database, new MonitoringExclusions([]));
        $this->assertSame(5, $cleared->totalRows());
        $this->assertCount(5, $cleared->historyRows($filters));
    }

    public function testStoredCaseVariantsAllRemainExcludedAcrossDatabaseCollations(): void
    {
        $repo = new PlayHistoryRepository($this->database, new MonitoringExclusions(['admin']));
        $this->insert('upper', 'ADMIN', 'Movies', 60);
        $this->insert('lower', 'admin', 'Movies', 60);
        $this->insert('title', 'Admin', 'Movies', 60);
        $this->insert('accent-case', 'Ádmin', 'Movies', 60);

        $this->assertSame(1, $repo->totalRows());
        $this->assertCount(1, $repo->itemPlaySummaries());
        $this->assertSame(['Ádmin'], $repo->users());
        $this->assertCount(4, $repo->users(true));
    }

    public function testExcludedIncomingRowsAreSkippedWithoutInsertingOrRepairing(): void
    {
        $repo = new PlayHistoryRepository($this->database, new MonitoringExclusions(['admin']));
        $this->insert('existing', 'Admin', 'Movies', 60, 0);
        $duplicate = $this->historical('existing', 'ADMIN');
        $duplicate['runtime_sec'] = 3600;
        $duplicate['watched_sec'] = 1800;
        $new = $this->historical('new', 'Admin');

        $this->assertSame(['inserted' => 0, 'skipped' => 2, 'repaired' => 0], $repo->importHistoricalPlays([$duplicate, $new]));
        $this->assertSame(['inserted' => 0, 'skipped' => 1], $repo->importNativeHistoricalPlays([$new]));
        $stored = $this->database->getDibi()->select('watched_sec, runtime_sec')->from('play_history')->fetch();
        $this->assertNotNull($stored);
        $this->assertSame(60, (int) $stored['watched_sec']);
        $this->assertSame(0, (int) $stored['runtime_sec']);

        $repo->logActiveStreams([$this->stream('Admin'), $this->stream('Viewer')], new DateTimeImmutable('2099-06-19 12:00:00'));
        $this->assertSame(2, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->fetchSingle());
    }

    public function testExistingExcludedNotificationIsRetiredWithoutDelivery(): void
    {
        $repo = new PlayHistoryRepository($this->database, new MonitoringExclusions(['admin']));
        $this->insert('notify', 'ADMIN', 'Movies', 0, 0, 0);

        $this->assertSame([], $repo->claimUnnotifiedPlays([], 600, new DateTimeImmutable('2099-06-19 12:00:30')));
        $this->assertSame(1, (int) $this->database->getDibi()->select('notified')->from('play_history')->fetchSingle());
    }

    private function insert(string $key, ?string $user, string $library, int $watched, int $runtime = 3600, int $notified = 1): void
    {
        $this->database->getDibi()->insert('play_history', [
            'session_key' => 'exclude-' . $key, 'user_name' => $user, 'item_id' => 'item-' . $key,
            'item_type' => 'Movie', 'item_name' => $key, 'library' => $library,
            'play_method' => 'DirectPlay', 'watched_sec' => $watched, 'runtime_sec' => $runtime,
            'started_at' => '2099-06-19 12:00:00', 'updated_at' => '2099-06-19 12:00:00',
            'is_finished' => 0, 'notified' => $notified,
        ])->execute();
    }

    /** @return array<string, mixed> */
    private function historical(string $key, string $user): array
    {
        return [
            'session_key' => 'exclude-' . $key, 'user_name' => $user, 'item_id' => 'item-' . $key,
            'item_type' => 'Movie', 'item_name' => $key, 'play_method' => 'DirectPlay',
            'watched_sec' => 60, 'runtime_sec' => 0, 'started_at' => '2099-06-19 12:00:00',
            'updated_at' => '2099-06-19 12:01:00', 'is_finished' => 0, 'notified' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function stream(string $user): array
    {
        return [
            'id' => 'exclude-stream-' . $user, 'itemId' => 'stream-item-' . $user,
            'itemType' => 'Movie', 'itemName' => 'Stream', 'user' => $user,
            'playMethod' => 'DirectPlay', 'watchedSec' => 60, 'runtimeSec' => 3600,
        ];
    }

    private function isolatedDatabase(): Database
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            return Database::sqlite(':memory:');
        }
        $config = ['driver' => DATABASE_DRIVER_DIBI, 'host' => DATABASE_HOST, 'username' => DATABASE_USERNAME, 'password' => DATABASE_PASSWORD];
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }
        $this->admin = new \Dibi\Connection($config);
        $this->databaseName = 'jellydash_phpunit_exclusions_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
        $config['database'] = $this->databaseName;

        return new Database(new \Dibi\Connection($config));
    }
}
