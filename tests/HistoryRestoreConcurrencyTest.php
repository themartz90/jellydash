<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryCsvImporter;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class HistoryRestoreConcurrencyTest extends TestCase
{
    private Database $database;
    private Database $other;
    private PlayHistoryRepository $repository;
    private PlayHistoryRepository $poller;
    private string $csv;

    protected function setUp(): void
    {
        $this->database = Container::db();
        $connection = $this->database->getDibi();
        $config = $connection->getConfig();
        $this->other = new Database(new \Dibi\Connection($config));
        $this->repository = new PlayHistoryRepository($this->database);
        $this->poller = new PlayHistoryRepository($this->other);
        $this->repository->prepareHistoryImport();
        $this->poller->prepareHistoryImport();
        $this->cleanup();
        $this->csv = tempnam(sys_get_temp_dir(), 'jellydash-restore-');
        $connection->insert('play_history', [
            'session_key' => 'phpunit-restore-backup', 'item_id' => 'phpunit-restore-item',
            'item_type' => 'Movie', 'play_method' => 'DirectPlay',
            'started_at' => '2099-01-01 12:00:00', 'updated_at' => '2099-01-01 12:01:00',
        ])->execute();
        $stream = fopen($this->csv, 'w+b');
        (new HistoryCsvExporter($this->repository))->write(new HistoryFilters(range: 'all', mediaId: 'phpunit-restore-item', mediaType: 'item', mediaItemType: 'Movie', mediaTitle: 'Restore fixture'), $stream);
        fclose($stream);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->other->getDibi()->disconnect();
        unlink($this->csv);
    }

    public function testCapturedPlaySurvivesRestoreContentionAndAnEmptyLaterPoll(): void
    {
        $capturedAt = new DateTimeImmutable('2099-01-02 12:00:00');
        $attempted = false;
        $failure = null;
        $wait = 0.0;
        $result = (new HistoryCsvImporter($this->database, repository: $this->repository))->importFile(
            $this->csv,
            function (array $progress) use ($capturedAt, &$attempted, &$failure, &$wait): void {
                if ($progress['phase'] !== 'importing' || $attempted) {
                    return;
                }
                $attempted = true;
                $start = microtime(true);
                try {
                    $this->poller->logActiveStreams([[
                        'id' => 'phpunit-restore-short', 'itemId' => 'phpunit-restore-short-item',
                        'itemType' => 'Movie', 'playMethod' => 'DirectPlay', 'watchedSec' => 30, 'runtimeSec' => 300,
                    ]], $capturedAt);
                } catch (Throwable $e) {
                    $failure = $e;
                }
                $wait = microtime(true) - $start;
            }
        );
        $this->assertTrue($attempted);
        $this->assertSame(1, $result['inserted']);
        $this->assertNotNull($failure);
        $this->assertLessThan(1.0, $wait, 'Collection should retain the sample without waiting five seconds behind restore.');
        $this->flushFromAnotherProcess();
        $row = $this->database->getDibi()->select('*')->from('play_history')->where('session_key = %s', 'phpunit-restore-short')->fetch();
        $this->assertNotNull($row, 'The play captured during restore must be retried even though it has ended.');
        $this->assertSame($capturedAt->getTimestamp(), (int) $row['started_at_epoch']);
        $this->assertSame(30, (int) $row['watched_sec']);
        $this->assertSame(0, (int) $row['watch_duration_sec']);
        $this->poller->logActiveStreams([], $capturedAt->modify('+2 minutes'));
        $this->assertSame(1, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->where('session_key = %s', 'phpunit-restore-short')->fetchSingle());
    }

    public function testRestoreRollbackDoesNotDiscardCapturedPlayback(): void
    {
        $attempted = false;
        try {
            (new HistoryCsvImporter($this->database, repository: $this->repository))->importFile(
                $this->csv,
                function (array $progress) use (&$attempted): void {
                    if ($progress['phase'] !== 'importing' || $attempted) {
                        return;
                    }
                    $attempted = true;
                    try {
                        $this->poller->logActiveStreams([[
                            'id' => 'phpunit-restore-short', 'itemId' => 'phpunit-restore-short-item',
                            'itemType' => 'Movie', 'playMethod' => 'DirectPlay', 'watchedSec' => 30, 'runtimeSec' => 300,
                        ]], new DateTimeImmutable('2099-01-02 12:00:00'));
                    } catch (\Mk\Framework\Jellyfin\HistoryWriteBusyException) {
                    }
                    throw new RuntimeException('Synthetic restore rollback.');
                }
            );
            $this->fail('Expected the restore to roll back.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic restore rollback.', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->where('session_key = %s', 'phpunit-restore-backup')->fetchSingle());
        $this->flushFromAnotherProcess();
        $this->assertSame(1, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->where('session_key = %s', 'phpunit-restore-short')->fetchSingle());
    }

    public function testFullServiceRetainsOrdinarySessionsWhenThemePersistenceFails(): void
    {
        $logger = Container::logger();
        Container::set('logger', new \Psr\Log\NullLogger());
        $sessions = [];
        foreach ([
            ['ordinary-audio', 'Audio', '/music/album/song.mp3'],
            ['movie', 'Movie', '/movies/movie.mkv'],
            ['theme', 'Audio', '/music/album/theme.mp3'],
            ['resolved-theme', 'Audio', null],
        ] as [$suffix, $type, $path]) {
            $item = ['Id' => 'phpunit-restore-' . $suffix, 'Type' => $type, 'Name' => 'Restore service fixture'];
            if ($path !== null) {
                $item['Path'] = $path;
            }
            $sessions[] = ['Id' => 'session-' . $suffix, 'UserName' => 'Restore Viewer', 'NowPlayingItem' => $item,
                'PlayState' => ['PositionTicks' => 300_000_000, 'PlayMethod' => 'DirectPlay']];
        }
        $client = new \Mk\Framework\Jellyfin\JellyfinClient(
            'http://restore.invalid',
            'synthetic',
            true,
            static function (string $path) use (&$sessions): array {
                if ($path === '/Sessions') {
                    return $sessions;
                }
                if (str_starts_with($path, '/Items?')) {
                    return ['Items' => [['Id' => 'phpunit-restore-resolved-theme', 'Type' => 'Audio', 'Path' => '/music/album/theme.mp3']]];
                }
                return [];
            }
        );
        $fresh = null;
        $service = null;
        $attempted = false;
        $wait = 0.0;
        $themes = $this->repository->themePlaybackExclusions();
        $themes->remember('phpunit-restore-ordinary-audio', 'Audio', \Mk\Framework\Jellyfin\ThemePlaybackClassifier::THEME);
        $themes->remember('phpunit-restore-theme', 'Audio', \Mk\Framework\Jellyfin\ThemePlaybackClassifier::ORDINARY);
        $this->database->getDibi()->insert('play_history', [
            'session_key' => 'phpunit-restore-earlier-theme', 'item_id' => 'phpunit-restore-theme',
            'item_type' => 'Audio', 'play_method' => 'DirectPlay', 'notified' => 1,
            'started_at' => '2099-01-01 10:00:00', 'updated_at' => '2099-01-01 10:01:00',
        ])->execute();
        try {
            (new HistoryCsvImporter($this->database, repository: $this->repository))->importFile(
                $this->csv,
                function (array $progress) use ($client, &$fresh, &$service, &$attempted, &$wait): void {
                    if ($progress['phase'] !== 'importing' || $attempted) {
                        return;
                    }
                    $attempted = true;
                    $started = microtime(true);
                    $fresh = new Database(new \Dibi\Connection($this->database->getDibi()->getConfig()));
                    $history = new PlayHistoryRepository($fresh);
                    $service = new \Mk\Framework\Jellyfin\NowPlayingService(
                        client: $client,
                        history: $history,
                        exclusions: new \Mk\Framework\Jellyfin\MonitoringExclusions([]),
                        movieIdentityCapture: static function (): void {
                        }
                    );
                    try {
                        $service->recordActivePlays();
                    } catch (Throwable) {
                        // The busy collection is retried after the restore commits.
                    }
                    $wait = microtime(true) - $started;
                }
            );
            $this->assertTrue($attempted);
            $this->assertLessThan(1.0, $wait);
            $sessions = [];
            $this->assertSame(0, $service->recordActivePlays());
            $ids = array_map(
                static fn (\Dibi\Row $row): string => (string) $row['item_id'],
                $this->repository->historyRows(new HistoryFilters(range: 'all'))
            );
            $ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== 'phpunit-restore-item'));
            $this->assertEqualsCanonicalizing(['phpunit-restore-ordinary-audio', 'phpunit-restore-movie'], $ids);
            $this->assertSame(\Mk\Framework\Jellyfin\ThemePlaybackClassifier::ORDINARY, $themes->classification('phpunit-restore-ordinary-audio'));
            $this->assertSame(\Mk\Framework\Jellyfin\ThemePlaybackClassifier::THEME, $themes->classification('phpunit-restore-theme'));
            $this->assertSame(\Mk\Framework\Jellyfin\ThemePlaybackClassifier::THEME, $themes->classification('phpunit-restore-resolved-theme'));
        } finally {
            if ($fresh !== null) {
                $fresh->getDibi()->disconnect();
            }
            Container::set('logger', $logger);
        }
    }

    private function flushFromAnotherProcess(): void
    {
        $environment = getenv();
        $environment = array_replace($environment, [
            'DB_DRIVER' => DATABASE_DRIVER_DIBI, 'DB_NAME' => DATABASE_NAME,
            'DB_HOST' => DATABASE_HOST, 'DB_USER' => DATABASE_USERNAME, 'DB_PASS' => DATABASE_PASSWORD,
        ]);
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $environment['DB_PORT'] = (string) DATABASE_PORT;
        }
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/history-sample-worker.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            $environment
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $this->assertSame('drained', $output);
    }

    public function testQueuedClassificationsCannotReplaceNewerObservationsInEitherDirection(): void
    {
        foreach ([
            ['ordinary', 'ordinary', 'theme'], ['theme', 'theme', 'ordinary'],
            ['theme', 'ordinary', 'ordinary'], ['ordinary', 'theme', 'theme'],
        ] as $index => [$queued, $initial, $newer]) {
            $item = 'phpunit-restore-order-' . $index;
            $themes = $this->repository->themePlaybackExclusions();
            $themes->remember($item, 'Audio', $initial, 500);
            $attempted = false;
            (new HistoryCsvImporter($this->database, repository: $this->repository))->importFile(
                $this->csv,
                function (array $progress) use ($item, $queued, &$attempted): void {
                    if ($progress['phase'] !== 'importing' || $attempted) {
                        return;
                    }
                    $attempted = true;
                    try {
                        $this->poller->logActiveStreams([[
                            'id' => 'observation-' . $item, 'itemId' => $item, 'itemType' => 'Audio',
                            'user' => 'Restore Viewer', 'playMethod' => 'DirectPlay', 'watchedSec' => 30,
                            'runtimeSec' => 300, 'themeClassification' => $queued,
                        ]], new DateTimeImmutable('@1000'));
                    } catch (\Mk\Framework\Jellyfin\HistoryWriteBusyException) {
                    }
                }
            );
            $themes->remember($item, 'Audio', $newer, 1100);
            $this->poller->logActiveStreams([], new DateTimeImmutable('@1200'));
            $this->assertSame($newer, $themes->classification($item), $queued . ' must not replace ' . $newer);
            $row = $this->database->getDibi()->select('updated_at_epoch')->from('theme_item_classifications')->where('item_id = %s', $item)->fetch();
            $this->assertSame(1100, (int) $row['updated_at_epoch']);
            $this->assertSame(0, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->where('item_id = %s', $item)->fetchSingle());
        }
    }

    public function testCachedClassificationIsNotRelabelledAsAFreshObservation(): void
    {
        $themes = $this->repository->themePlaybackExclusions();
        $client = new \Mk\Framework\Jellyfin\JellyfinClient(requester: static function (): never {
            throw new RuntimeException('A cached decision must not need a network request.');
        });
        foreach (['ordinary', 'theme'] as $index => $classification) {
            $item = 'phpunit-restore-cache-' . $index;
            $themes->remember($item, 'Audio', $classification, 500);
            $visible = $themes->filterSessions([['NowPlayingItem' => ['Id' => $item, 'Type' => 'Audio']]], $client, 1100);
            if ($classification === 'ordinary') {
                $this->assertCount(1, $visible);
                $this->assertArrayNotHasKey('_jellydash_theme_classification', $visible[0]);
            } else {
                $this->assertSame([], $visible);
            }
            $this->assertSame([], $themes->observedThemeSamples());
            $themes->remember($item, 'Audio', $classification === 'theme' ? 'ordinary' : 'theme', 1000);
            $this->assertNotSame($classification, $themes->classification($item));
        }
    }

    public function testClassificationCompareAndSwapKeepsTheLatestInterleavedObservation(): void
    {
        $themes = $this->repository->themePlaybackExclusions();
        $item = 'phpunit-restore-interleaved';
        $themes->remember($item, 'Audio', 'ordinary', 500);
        $triggered = false;
        $connection = $this->database->getDibi();
        $listener = function (\Dibi\Event $event) use ($themes, $item, &$triggered): void {
            if (!$triggered && str_contains((string) $event->sql, 'SELECT') && str_contains((string) $event->sql, 'theme_item_classifications')) {
                $triggered = true;
                $themes->remember($item, 'Audio', 'ordinary', 1200);
            }
        };
        $connection->onEvent[] = $listener;
        try {
            $themes->remember($item, 'Audio', 'theme', 1300);
        } finally {
            $connection->onEvent = array_values(array_filter($connection->onEvent, static fn ($entry): bool => $entry !== $listener));
        }
        $this->assertTrue($triggered);
        $this->assertSame('theme', $themes->classification($item));
        $this->assertSame(1300, (int) $connection->select('updated_at_epoch')->from('theme_item_classifications')->where('item_id = %s', $item)->fetchSingle());
    }

    public function testQueuedTieCannotUndoAFreshClassificationChangeInTheSameSecond(): void
    {
        $themes = $this->repository->themePlaybackExclusions();
        foreach ([['ordinary', 'theme'], ['theme', 'ordinary']] as $index => [$old, $new]) {
            $item = 'phpunit-restore-tie-' . $index;
            $themes->remember($item, 'Audio', $old, 1000);
            $themes->remember($item, 'Audio', $new, 1000);
            $this->assertSame($new, $themes->classification($item));
            $themes->remember($item, 'Audio', $old, 1000, fromQueue: true, expectedVersion: 1);
            $this->assertSame($new, $themes->classification($item));
        }
    }

    private function cleanup(): void
    {
        $this->database->getDibi()->delete('play_history')->where('item_id LIKE %s', 'phpunit-restore-%')->execute();
        $this->database->getDibi()->delete('theme_item_classifications')->where('item_id LIKE %s', 'phpunit-restore-%')->execute();
    }
}
