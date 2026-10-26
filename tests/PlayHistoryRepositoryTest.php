<?php

use Mk\Framework\AppSettings;
use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\PlaybackReportingImporter;
use Mk\Framework\Jellyfin\PlaybackReportingParser;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class PlayHistoryRepositoryTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private PlayHistoryRepository $repository;

    protected function setUp(): void
    {
        try {
            $this->dibi = Container::db()->getDibi();
            $this->repository = new PlayHistoryRepository(Container::db());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
    }

    public function testLogActiveStreamsInsertsAndKeepsHighestWatchedProgress(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');

        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);
        $this->repository->logActiveStreams([$this->stream(600, 3600)], $now->modify('+5 seconds'));

        $row = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', 'phpunit-session')
            ->where('item_id = %s', 'phpunit-item')
            ->fetch();

        $this->assertNotNull($row);
        $this->assertSame(900, (int) $row['watched_sec']);
        $this->assertSame(3600, (int) $row['runtime_sec']);
        $this->assertSame('PHPUnit Viewer', $row['user_name']);
        $this->assertSame('TV Shows', $row['library']);
        $this->assertSame('Audio Transcode', $row['play_method_detail']);
        $this->assertSame('HEVC', $row['source_video_codec']);
        $this->assertSame('AAC', $row['target_audio_codec']);
        $this->assertSame(1, (int) $row['is_video_direct']);
        $this->assertSame(0, (int) $row['is_audio_direct']);
        $this->assertStringContainsString('Audio codec not supported', (string) $row['transcode_reasons']);
    }

    public function testLogActiveStreamsStartsFreshPlayWhenFinishedPlaybackRestarts(): void
    {
        $start = new \DateTimeImmutable('2099-06-19 12:00:00');

        // A finished viewing restarts near the beginning after a long gap.
        $this->repository->logActiveStreams([$this->stream(3500, 3600)], $start);
        $this->dibi->update('play_history', [
            'notified' => 0,
            'notification_attempts' => 2,
            'notification_claim_token' => str_repeat('a', 64),
            'notification_claimed_at_epoch' => $start->getTimestamp(),
            'notification_next_attempt_at_epoch' => $start->getTimestamp() + 300,
        ])->execute();

        // The same long-lived session+item now reports a position well behind
        // the completed viewing, so this is the existing explicit restart case.
        $this->repository->logActiveStreams([$this->stream(120, 3600)], $start->modify('+3 hours'));

        $rows = $this->dibi->select('*')->from('play_history')
            ->where('item_id = %s', 'phpunit-item')->orderBy('started_at')->desc()->fetchAll();
        $this->assertCount(2, $rows);
        [$row, $completed] = $rows;
        $this->assertSame('phpunit-session', (string) $completed['session_key']);
        $this->assertSame(3500, (int) $completed['watched_sec']);
        $this->assertSame(1, (int) $completed['is_finished']);
        $this->assertSame(str_repeat('a', 64), (string) $completed['notification_claim_token']);

        $started = $row['started_at'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($row['started_at'])
            : new \DateTimeImmutable((string) $row['started_at']);

        $this->assertNotNull($row);
        $this->assertSame(120, (int) $row['watched_sec']);
        $this->assertSame('2099-06-19 15:00:00', $started->format('Y-m-d H:i:s'));
        $this->assertSame(0, (int) $row['notification_attempts']);
        $this->assertNull($row['notification_claim_token']);
        $this->assertNull($row['notification_claimed_at_epoch']);
        $this->assertNull($row['notification_next_attempt_at_epoch']);
    }

    public function testRepeatedViewingPreservesMeasuredTimeAndDoesNotDuplicateContinuations(): void
    {
        $start = new DateTimeImmutable('2099-06-19 12:00:00');
        foreach ([[0, 0], [30, 30], [60, 3500], [90, 0], [120, 30], [150, 3500], [180, 0], [210, 30]] as [$seconds, $position]) {
            $this->repository->logActiveStreams([$this->stream($position, 3600)], $start->modify('+' . $seconds . ' seconds'));
        }
        $rows = $this->dibi->select('*')->from('play_history')->where('item_id = %s', 'phpunit-item')
            ->orderBy('started_at')->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertSame([60, 60, 30], array_map(static fn (\Dibi\Row $row): int => (int) $row['watch_duration_sec'], $rows));
        $this->assertSame([1, 1, 0], array_map(static fn (\Dibi\Row $row): int => (int) $row['is_finished'], $rows));
        $this->assertCount(3, array_unique(array_map(static fn (\Dibi\Row $row): string => (string) $row['session_key'], $rows)));
    }

    public function testLongLiveMetadataIsPreservedWithoutSkippingOtherStreams(): void
    {
        $stream = $this->stream(120, 3600);
        foreach (['user' => 128, 'itemName' => 255, 'seriesName' => 255, 'client' => 64, 'device' => 64, 'library' => 64] as $field => $limit) {
            $stream[$field] = str_repeat('Ž', $limit + 1);
        }
        $before = $this->stream(30, 3600);
        $before['id'] = 'phpunit-before-long';
        $after = $this->stream(60, 3600);
        $after['id'] = 'phpunit-after-long';
        $this->repository->logActiveStreams([$before, $stream, $after], new DateTimeImmutable('2099-06-19 12:00:00'));
        $this->assertSame(3, (int) $this->dibi->select('COUNT(*)')->from('play_history')->fetchSingle());
        $row = $this->dibi->select('*')->from('play_history')->where('session_key = %s', 'phpunit-session')->fetch();
        foreach (['user_name' => 128, 'item_name' => 255, 'series_name' => 255, 'client' => 64, 'device' => 64, 'library' => 64] as $field => $limit) {
            $this->assertSame(str_repeat('Ž', $limit + 1), $row[$field], $field);
        }
        $this->assertSame('phpunit-item', $row['item_id']);
    }

    public function testLongNamesKeepExactNotificationExclusions(): void
    {
        $start = new DateTimeImmutable('2099-06-19 12:00:00');
        $library = str_repeat('Library', 10);
        $stream = array_replace($this->stream(120, 3600), ['library' => $library, 'libraryResolved' => true]);
        $this->repository->logActiveStreams([$stream], $start);
        $this->assertSame([], $this->repository->claimUnnotifiedPlays([], 600, $start->modify('+1 second'), [$library]));
        $this->dibi->delete('play_history')->where('session_key = %s', 'phpunit-session')->execute();
        $user = str_repeat('Viewer', 25);
        $this->repository->logActiveStreams([array_replace($stream, ['user' => $user])], $start);
        $this->assertSame([], $this->repository->claimUnnotifiedPlays([$user], 600, $start->modify('+1 second')));
    }

    public function testSampleAlreadyCoveredByRestoredHistoryDoesNotInventAReplay(): void
    {
        $start = new DateTimeImmutable('2099-06-19 12:00:00');
        $this->insertPlay([
            'session_key' => 'phpunit-session', 'watched_sec' => 3500, 'watch_duration_sec' => 300,
            'is_finished' => 1, 'updated_at_epoch' => $start->modify('+60 seconds')->getTimestamp(),
            'last_sample_epoch' => null,
        ]);
        $this->repository->logActiveStreams([$this->stream(120, 3600)], $start->modify('+30 seconds'));
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('play_history')->fetchSingle());
        $row = $this->dibi->select('watched_sec, watch_duration_sec')->from('play_history')->fetch();
        $this->assertSame(3500, (int) $row['watched_sec']);
        $this->assertSame(300, (int) $row['watch_duration_sec']);
    }

    public function testInvalidStreamIdentityDoesNotDiscardLaterStreams(): void
    {
        $logger = Container::logger();
        Container::set('logger', new \Psr\Log\NullLogger());
        $bad = $this->stream(120, 3600);
        $bad['id'] = str_repeat('x', 129);
        try {
            $this->repository->logActiveStreams([$bad, $this->stream(60, 3600)], new DateTimeImmutable('2099-06-19 12:00:00'));
            $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('play_history')->fetchSingle());
            $this->assertSame('phpunit-session', (string) $this->dibi->select('session_key')->from('play_history')->fetchSingle());
        } finally {
            Container::set('logger', $logger);
            $this->dibi->delete('play_history')->where('session_key = %s', str_repeat('x', 129))->execute();
        }
    }

    public function testUnresolvedLiveUpdateCannotReplaceConfirmedLibrary(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');
        $resolved = $this->stream(600, 3600);
        $resolved['library'] = 'Anime';
        $resolved['libraryResolved'] = true;
        $this->repository->logActiveStreams([$resolved], $now);

        $generic = $this->stream(900, 3600);
        $generic['library'] = 'TV Shows';
        $generic['libraryResolved'] = false;
        $this->repository->logActiveStreams([$generic], $now->modify('+5 seconds'));

        $row = $this->dibi->select('library, library_resolved_at')
            ->from('play_history')
            ->where('session_key = %s', 'phpunit-session')
            ->where('item_id = %s', 'phpunit-item')
            ->fetch();

        $this->assertNotNull($row);
        $this->assertSame('Anime', (string) $row['library']);
        $this->assertStringStartsWith('2099-06-19 12:00:00', (string) $row['library_resolved_at']);
    }

    public function testResolvedLibrariesForStreamsUsesResolutionFlagEvenForGenericName(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');
        $resolved = $this->stream(600, 3600);
        $resolved['library'] = 'TV Shows';
        $resolved['libraryResolved'] = true;
        $this->repository->logActiveStreams([$resolved], $now);

        $this->assertSame(
            [0 => 'TV Shows'],
            $this->repository->resolvedLibrariesForStreams([$this->stream(900, 3600)], $now->modify('+5 seconds'))
        );
    }

    public function testNotificationLibraryExclusionsWaitForConfirmedMetadataAndKeepHistory(): void
    {
        $now = new DateTimeImmutable('2099-06-19 12:00:30');
        $music = $this->insertPlay([
            'library' => 'Music',
            'library_resolved_at' => '2099-06-19 12:00:00',
            'notified' => 0,
        ]);
        $movie = $this->insertPlay([
            'library' => 'Movies',
            'library_resolved_at' => '2099-06-19 12:00:00',
            'notified' => 0,
        ]);
        $unknown = $this->insertPlay([
            'library' => 'Audio',
            'library_resolved_at' => null,
            'notified' => 0,
        ]);

        $claims = $this->repository->claimUnnotifiedPlays([], 600, $now, ['mUsIc']);

        $this->assertSame([$movie], array_map(static fn (\Dibi\Row $row): int => (int) $row['id'], $claims));
        $this->assertSame(1, (int) $this->dibi->select('notified')->from('play_history')->where('id = %i', $music)->fetchSingle());
        $this->assertSame(0, (int) $this->dibi->select('notification_attempts')->from('play_history')->where('id = %i', $unknown)->fetchSingle());
        $this->assertSame(3, $this->repository->totalRows());

        $this->dibi->update('play_history', [
            'library' => 'Movies',
            'library_resolved_at' => '2099-06-19 12:01:00',
        ])->where('id = %i', $unknown)->execute();

        $later = $this->repository->claimUnnotifiedPlays([], 600, $now->modify('+30 seconds'), ['Music']);
        $this->assertSame([$unknown], array_map(static fn (\Dibi\Row $row): int => (int) $row['id'], $later));
    }

    public function testUnresolvedLibraryCanStillNotifyWithoutLibraryExclusions(): void
    {
        $unknown = $this->insertPlay([
            'library' => 'Audio',
            'library_resolved_at' => null,
            'notified' => 0,
        ]);

        $claims = $this->repository->claimUnnotifiedPlays([], 600, new DateTimeImmutable('2099-06-19 12:00:30'));
        $this->assertSame([$unknown], array_map(static fn (\Dibi\Row $row): int => (int) $row['id'], $claims));
    }

    public function testLateExcludedLibraryAndUnresolvedPlayExpireWithoutAlerts(): void
    {
        $lateMusic = $this->insertPlay([
            'library' => 'Audio',
            'library_resolved_at' => null,
            'notified' => 0,
        ]);
        $unresolved = $this->insertPlay([
            'library' => 'Audio',
            'library_resolved_at' => null,
            'notified' => 0,
        ]);
        $now = new DateTimeImmutable('2099-06-19 12:00:30');

        $this->assertSame([], $this->repository->claimUnnotifiedPlays([], 600, $now, ['Music']));
        $this->dibi->update('play_history', [
            'library' => 'Music',
            'library_resolved_at' => '2099-06-19 12:01:00',
        ])->where('id = %i', $lateMusic)->execute();

        $this->assertSame([], $this->repository->claimUnnotifiedPlays([], 600, $now->modify('+30 seconds'), ['Music']));
        $this->assertSame(1, (int) $this->dibi->select('notified')->from('play_history')->where('id = %i', $lateMusic)->fetchSingle());
        $this->assertSame(0, (int) $this->dibi->select('notified')->from('play_history')->where('id = %i', $unresolved)->fetchSingle());

        $this->assertSame([], $this->repository->claimUnnotifiedPlays([], 600, $now->modify('+11 minutes'), ['Music']));
        $this->assertSame(1, (int) $this->dibi->select('notified')->from('play_history')->where('id = %i', $unresolved)->fetchSingle());
    }

    public function testWatchTimeTodaySumsRowsForCurrentDay(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');

        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);
        $this->repository->logActiveStreams([$this->stream(930, 3600)], $now->modify('+30 seconds'));

        $this->dibi->insert('play_history', [
            'session_key' => 'phpunit-yesterday',
            'item_id' => 'phpunit-yesterday-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 1200,
            'runtime_sec' => 1200,
            'started_at' => '2099-06-18 12:00:00',
            'updated_at' => '2099-06-18 12:05:00',
        ])->execute();

        $this->assertSame(30, $this->repository->watchTimeToday($now));
    }

    public function testHistoryRowsApplyFiltersAndExposeUsers(): void
    {
        $now = new \DateTimeImmutable('2026-06-19 12:00:00');
        $episode = $this->stream(900, 3600);
        $episode['seriesName'] = 'PHPUnit Expanse Fixture';
        $episode['library'] = 'PHPUnit TV Shows';
        $this->repository->logActiveStreams([$episode], $now);

        $this->dibi->insert('play_history', [
            'session_key' => 'phpunit-movie',
            'user_id' => 'phpunit-user-2',
            'user_name' => 'Jon Bell',
            'item_id' => 'phpunit-movie-item',
            'item_type' => 'Movie',
            'item_name' => 'Arrival',
            'library' => 'PHPUnit Movies',
            'play_method' => 'DirectPlay',
            'client' => 'Web',
            'device' => 'MacBook',
            'watched_sec' => 1200,
            'runtime_sec' => 1200,
            'started_at' => '2026-06-19 11:00:00',
            'updated_at' => '2026-06-19 11:20:00',
        ])->execute();

        $movieRows = $this->repository->historyRows(new HistoryFilters(library: 'PHPUnit Movies', range: 'all'), $now);
        $searchRows = $this->repository->historyRows(new HistoryFilters(search: 'PHPUnit Expanse Fixture', range: 'all'), $now);

        $this->assertCount(1, $movieRows);
        $this->assertSame('Arrival', $movieRows[0]['item_name']);
        $this->assertCount(1, $searchRows);
        $this->assertSame('PHPUnit Expanse Fixture', $searchRows[0]['series_name']);
        $this->assertSame(1, $this->repository->historyTotal(new HistoryFilters(user: 'PHPUnit Viewer', range: 'all'), $now));
        $this->assertContains('Jon Bell', $this->repository->users());
        $this->assertContains('PHPUnit Viewer', $this->repository->users());
        $this->assertContains('PHPUnit Movies', $this->repository->libraries());
        $this->assertContains('PHPUnit TV Shows', $this->repository->libraries());
    }

    public function testHistoryRowsHonorLimitAndOffset(): void
    {
        $now = new \DateTimeImmutable('2026-06-19 12:00:00');
        $user = 'PHPUnit History Pager';
        $this->insertPlay([
            'session_key' => 'phpunit-page-a',
            'user_name' => $user,
            'item_name' => 'Newest',
            'started_at' => '2026-06-19 12:00:00',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-page-b',
            'user_name' => $user,
            'item_name' => 'Middle',
            'started_at' => '2026-06-19 11:00:00',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-page-c',
            'user_name' => $user,
            'item_name' => 'Oldest',
            'started_at' => '2026-06-19 10:00:00',
        ]);

        $pageTwo = $this->repository->historyRows(new HistoryFilters(
            user: $user,
            range: 'all',
            limit: 1,
            offset: 1,
        ), $now);

        $this->assertCount(1, $pageTwo);
        $this->assertSame('Middle', $pageTwo[0]['item_name']);
    }

    public function testEpisodeWithoutSeriesDrilldownFiltersToItsExactItem(): void
    {
        foreach (['episode-a' => 'Pilot', 'episode-b' => 'Another episode'] as $id => $title) {
            $this->insertPlay([
                'session_key' => 'phpunit-episode-drilldown-' . $id,
                'item_id' => $id,
                'item_type' => 'Episode',
                'series_name' => '',
                'item_name' => $title,
            ]);
        }
        $filters = HistoryFilters::fromQuery([
            'media_type' => 'item', 'media_id' => 'episode-a',
            'media_item_type' => 'Episode', 'media_title' => 'Pilot',
            'range' => 'all',
        ]);

        $this->assertSame(['episode-a'], array_map(
            static fn (\Dibi\Row $row): string => (string) $row['item_id'],
            $this->repository->historyRows($filters),
        ));
        $this->assertSame(1, $this->repository->historyTotal($filters));
    }

    public function testFreeTextSearchKeepsTheDocumentedBackendAccentContract(): void
    {
        $this->insertPlay([
            'session_key' => 'phpunit-accent-search',
            'item_id' => 'phpunit-accent-search-item',
            'item_name' => 'CAFÉ audit contract',
        ]);

        $rows = $this->repository->historyRows(new HistoryFilters(
            search: 'cafe',
            range: 'all',
        ));

        if (\Mk\Framework\DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->assertCount(0, $rows, 'SQLite free-text LIKE does not fold accents.');
        } else {
            $this->assertCount(1, $rows, 'MariaDB utf8mb4_unicode_ci folds case and accents.');
        }
    }

    public function testFreeTextSearchTreatsLikeWildcardsAsLiteralAcrossRowsCountsAndExport(): void
    {
        $user = 'PHPUnit Literal Search Viewer';
        foreach (['50% done', '500 done', '100_percent', '100Xpercent', 'bang!_match', 'bang!Xmatch'] as $index => $title) {
            $this->insertPlay([
                'session_key' => 'phpunit-literal-search-' . $index,
                'user_name' => $user,
                'item_name' => $title,
            ]);
        }

        foreach (['50%' => '50% done', '100_' => '100_percent', 'bang!_' => 'bang!_match', '%' => '50% done'] as $term => $expected) {
            $filters = new HistoryFilters(user: $user, search: $term, range: 'all');
            $this->assertSame([$expected], array_map(
                static fn (\Dibi\Row $row): string => (string) $row['item_name'],
                $this->repository->historyRows($filters),
            ), $term);
            $this->assertSame(1, $this->repository->historyTotal($filters), $term);
            $this->assertSame(1, $this->repository->historyAggregate($filters)['plays'], $term);
            $this->assertSame([$expected], array_map(
                static fn (\Dibi\Row $row): string => (string) $row['item_name'],
                iterator_to_array($this->repository->historyExportRows($filters)),
            ), $term);
        }
    }

    public function testCustomPeriodUsesInclusiveStartExclusiveEndAcrossRowsAndAggregates(): void
    {
        $user = 'PHPUnit Exact History Period';
        foreach ([
            ['before', '2026-03-01 23:59:59', 60],
            ['start', '2026-03-02 00:00:00', 120],
            ['middle', '2026-03-15 12:00:00', 180],
            ['end', '2026-04-01 00:00:00', 240],
        ] as [$suffix, $startedAt, $watchSec]) {
            $this->insertPlay([
                'session_key' => 'phpunit-exact-period-' . $suffix,
                'user_name' => $user,
                'item_name' => ucfirst($suffix),
                'watched_sec' => $watchSec,
                'started_at' => $startedAt,
            ]);
        }

        $filters = HistoryFilters::fromQuery([
            'user' => $user,
            'range' => 'custom',
            'start' => '2026-03-02',
            'end' => '2026-04-01',
        ]);
        $pageTwo = new HistoryFilters(
            user: $filters->user,
            range: $filters->range,
            limit: 1,
            offset: 1,
            start: $filters->start,
            end: $filters->end,
        );

        $this->assertSame(2, $this->repository->historyTotal($filters));
        $this->assertSame(['Middle', 'Start'], array_map(
            static fn (\Dibi\Row $row): string => (string) $row['item_name'],
            $this->repository->historyRows($filters),
        ));
        $this->assertSame('Start', (string) $this->repository->historyRows($pageTwo)[0]['item_name']);

        $aggregate = $this->repository->historyAggregate($pageTwo);
        $this->assertSame(2, $aggregate['plays']);
        $this->assertSame(300, $aggregate['watch_sec']);
    }

    public function testClientAndPlaybackMethodFiltersShareStatisticsGroupingSemantics(): void
    {
        $user = 'PHPUnit Client Method Filters';
        foreach ([
            ['web-transcode', 'Web', 'Transcode', 100, '12:09:00'],
            ['web-stream', 'Web', 'DirectStream', 200, '12:08:00'],
            ['web-play', 'Web', 'DirectPlay', 300, '12:07:00'],
            ['web-legacy', 'Web', 'LegacyMethod', 400, '12:06:00'],
            ['web-lower-transcode', 'Web', 'transcode', 500, '12:05:00'],
            ['lower-web-transcode', 'web', 'Transcode', 600, '12:04:00'],
            ['missing-client', null, 'DirectPlay', 700, '12:03:00'],
            ['empty-client', '', 'DirectStream', 800, '12:02:00'],
            ['named-unknown', 'Unknown client', 'Transcode', 900, '12:01:00'],
        ] as [$key, $client, $method, $watchSec, $time]) {
            $this->insertPlay([
                'session_key' => 'phpunit-client-method-' . $key,
                'user_name' => $user,
                'client' => $client,
                'play_method' => $method,
                'watched_sec' => $watchSec,
                'started_at' => '2026-09-09 ' . $time,
                'updated_at' => '2026-09-09 ' . $time,
            ]);
        }

        $webDirect = new HistoryFilters(
            user: $user,
            client: 'Web',
            method: 'direct',
            range: 'all',
            limit: 1,
            offset: 1,
        );
        $this->assertSame(4, $this->repository->historyTotal($webDirect));
        $this->assertSame(
            'phpunit-client-method-web-play',
            (string) $this->repository->historyRows($webDirect)[0]['session_key'],
        );
        $this->assertCount(4, iterator_to_array($this->repository->historyExportRows($webDirect)));
        $this->assertSame(0, $this->repository->historyAggregate($webDirect)['transcodes']);

        $this->assertSame(3, $this->repository->historyTotal(new HistoryFilters(
            user: $user,
            client: 'Unknown client',
            range: 'all',
        )));
        $this->assertSame(3, $this->repository->historyTotal(new HistoryFilters(
            user: $user,
            method: 'transcode',
            range: 'all',
        )));
        $this->assertSame(2, $this->repository->historyTotal(new HistoryFilters(
            user: $user,
            method: 'direct-stream',
            range: 'all',
        )));
        $this->assertSame(4, $this->repository->historyTotal(new HistoryFilters(
            user: $user,
            method: 'direct-play',
            range: 'all',
        )));
        $this->assertSame(6, $this->repository->historyTotal(new HistoryFilters(
            user: $user,
            method: 'direct',
            range: 'all',
        )));

        $clients = $this->repository->clients();
        $this->assertContains('Web', $clients);
        $this->assertContains('web', $clients);
        $this->assertSame(1, count(array_keys($clients, 'Unknown client', true)));

        $excluded = new PlayHistoryRepository(Container::db(), new MonitoringExclusions([$user]));
        $this->assertNotContains('Web', $excluded->clients());
        $this->assertNotContains('web', $excluded->clients());
    }

    public function testHistoryAggregateCoversTheFullFilteredResultNotOnePage(): void
    {
        $user = 'PHPUnit History Aggregate';
        $this->insertPlay([
            'session_key' => 'phpunit-aggregate-a',
            'user_name' => $user,
            'watched_sec' => 600,
            'play_method' => 'DirectPlay',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-aggregate-b',
            'user_name' => $user,
            'watched_sec' => 1200,
            'play_method' => 'Transcode',
        ]);

        $filters = new HistoryFilters(user: $user, range: 'all', limit: 1);
        $this->assertCount(1, $this->repository->historyRows($filters));

        $aggregate = $this->repository->historyAggregate($filters);
        $this->assertSame(2, $aggregate['plays']);
        $this->assertSame(1, $aggregate['unique_users']);
        $this->assertSame(1800, $aggregate['watch_sec']);
        $this->assertSame(1, $aggregate['transcodes']);
    }

    public function testExactUserAndLibraryFiltersPreserveCaseAcrossRowsAggregatesAndExport(): void
    {
        foreach ([
            ['upper', 'Maya', 'Movies', 60],
            ['lower-user', 'maya', 'Movies', 120],
            ['lower-library', 'Maya', 'movies', 180],
            ['accented', 'Máya', 'Movies', 240],
        ] as [$suffix, $user, $library, $watchSec]) {
            $this->insertPlay([
                'session_key' => 'phpunit-exact-text-' . $suffix,
                'user_name' => $user,
                'library' => $library,
                'watched_sec' => $watchSec,
                'started_at' => '2026-09-10 12:00:00',
            ]);
        }

        $filters = new HistoryFilters(user: 'Maya', library: 'Movies', range: 'all', limit: 1);
        $rows = $this->repository->historyRows($filters);
        $aggregate = $this->repository->historyAggregate($filters);
        $export = iterator_to_array($this->repository->historyExportRows($filters));

        $this->assertCount(1, $rows);
        $this->assertSame('phpunit-exact-text-upper', (string) $rows[0]['session_key']);
        $this->assertSame(1, $this->repository->historyTotal($filters));
        $this->assertSame(1, $aggregate['plays']);
        $this->assertSame(1, $aggregate['unique_users']);
        $this->assertSame(60, $aggregate['watch_sec']);
        $this->assertCount(1, $export);
        $this->assertSame('phpunit-exact-text-upper', (string) $export[0]['session_key']);
        $this->assertContains('Maya', $this->repository->users());
        $this->assertContains('maya', $this->repository->users());
        $this->assertContains('Máya', $this->repository->users());
        $this->assertContains('Movies', $this->repository->libraries());
        $this->assertContains('movies', $this->repository->libraries());
    }

    public function testExactMediaScopesAreSharedByRowsTotalsAggregatesAndExport(): void
    {
        foreach ([
            ['movie-a', 'movie-a', 'Movie', 'Crash', null, 'Movies', '2026-09-10 10:00:00'],
            ['movie-b', 'movie-b', 'Movie', 'Crash', null, 'Movies', '2026-09-10 10:01:00'],
            ['movie-id-audio', 'movie-a', 'Audio', 'Audio with reused ID', null, 'Music', '2026-09-10 10:01:30'],
            ['series-tv', 'episode-tv', 'Episode', 'Episode 1', 'Shared Show', 'TV', '2026-09-10 10:02:00'],
            ['series-kids', 'episode-kids', 'Episode', 'Episode 2', 'Shared Show', 'Kids TV', '2026-09-10 10:03:00'],
            ['series-unresolved', 'episode-unresolved', 'Episode', 'Episode 3', 'Shared Show', 'Kids TV', null],
        ] as [$suffix, $itemId, $type, $itemName, $seriesName, $library, $resolvedAt]) {
            $this->insertPlay([
                'session_key' => 'phpunit-media-scope-' . $suffix,
                'item_id' => $itemId,
                'item_type' => $type,
                'item_name' => $itemName,
                'series_name' => $seriesName,
                'library' => $library,
                'library_resolved_at' => $resolvedAt,
                'watched_sec' => 60,
                'started_at' => '2026-09-10 12:00:00',
            ]);
        }

        $movie = new HistoryFilters(
            range: 'all',
            mediaType: 'item',
            mediaId: 'movie-a',
            mediaItemType: 'Movie',
            mediaTitle: 'Crash',
        );
        $this->assertSame(['phpunit-media-scope-movie-a'], array_map(
            static fn (\Dibi\Row $row): string => (string) $row['session_key'],
            $this->repository->historyRows($movie),
        ));
        $this->assertSame(1, $this->repository->historyTotal($movie));
        $this->assertSame(1, $this->repository->historyAggregate($movie)['plays']);
        $this->assertCount(1, iterator_to_array($this->repository->historyExportRows($movie)));

        $series = new HistoryFilters(
            range: 'all',
            mediaType: 'series',
            mediaTitle: 'Shared Show',
            mediaLibrary: 'Kids TV',
        );
        $this->assertSame(['phpunit-media-scope-series-kids'], array_map(
            static fn (\Dibi\Row $row): string => (string) $row['session_key'],
            $this->repository->historyRows($series),
        ));
        $this->assertSame(1, $this->repository->historyTotal($series));
        $this->assertSame(1, $this->repository->historyAggregate($series)['plays']);
        $this->assertCount(1, iterator_to_array($this->repository->historyExportRows($series)));
    }

    public function testUniqueUserAggregateSeparatesAnonymousNamedUnknownAndCaseVariants(): void
    {
        foreach ([
            ['null', null],
            ['empty', ''],
            ['named', 'Unknown user'],
            ['upper', 'Maya'],
            ['lower', 'maya'],
        ] as [$suffix, $user]) {
            $this->insertPlay([
                'session_key' => 'phpunit-unique-viewer-' . $suffix,
                'user_name' => $user,
                'started_at' => '2026-09-10 12:00:00',
            ]);
        }

        $aggregate = $this->repository->historyAggregate(new HistoryFilters(
            search: 'Arrival',
            range: 'all',
        ));

        $this->assertSame(4, $aggregate['unique_users']);
    }

    public function testUniqueConflictFallbackDoesNotResetAnAlreadyClaimedNotification(): void
    {
        $now = new DateTimeImmutable('2099-06-19 12:00:00');
        $other = new PlayHistoryRepository(Container::db());
        $interleaved = false;
        $token = str_repeat('b', 64);
        $listener = function (\Dibi\Event $event) use ($other, $now, $token, &$interleaved): void {
            if (!$interleaved && str_contains((string) $event->sql, 'SELECT id, watched_sec')) {
                $interleaved = true;
                $other->logActiveStreams([$this->stream(120, 3600)], $now);
                $this->dibi->update('play_history', [
                    'notification_attempts' => 1,
                    'notification_claim_token' => $token,
                    'notification_claimed_at_epoch' => $now->getTimestamp(),
                ])->where('session_key = %s', 'phpunit-session')->execute();
            }
        };
        $this->dibi->onEvent[] = $listener;
        try {
            $this->repository->logActiveStreams([$this->stream(120, 3600)], $now);
        } finally {
            $this->dibi->onEvent = array_values(array_filter(
                $this->dibi->onEvent,
                static fn ($callback): bool => $callback !== $listener,
            ));
        }

        $row = $this->dibi->select('notification_attempts, notification_claim_token')
            ->from('play_history')->where('session_key = %s', 'phpunit-session')->fetch();
        $this->assertTrue($interleaved);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['notification_attempts']);
        $this->assertSame($token, (string) $row['notification_claim_token']);
    }

    public function testHistoryRowsBreakTimestampTiesByIdDesc(): void
    {
        $now = new \DateTimeImmutable('2026-06-19 12:00:00');
        $user = 'PHPUnit History Tiebreak';
        $startedAt = '2026-06-19 12:00:00';

        $this->insertPlay([
            'user_name' => $user,
            'item_name' => 'First inserted',
            'started_at' => $startedAt,
        ]);
        $this->insertPlay([
            'user_name' => $user,
            'item_name' => 'Second inserted',
            'started_at' => $startedAt,
        ]);

        $rows = $this->repository->historyRows(new HistoryFilters(
            user: $user,
            range: 'all',
        ), $now);

        $this->assertCount(2, $rows);
        $this->assertSame('Second inserted', $rows[0]['item_name']);
        $this->assertSame('First inserted', $rows[1]['item_name']);
    }

    public function testImportHistoricalPlaysInsertsThenSkipsDuplicates(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120"
        );
        $rows[0]['user_name'] = 'PHPUnit Import';

        $first = $this->repository->importHistoricalPlays($rows);
        $second = $this->repository->importHistoricalPlays($rows);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped']);

        $stored = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetch();

        $this->assertNotNull($stored);
        $this->assertSame('Dune', $stored['item_name']);
        $this->assertSame(1, (int) $stored['notified']);
        $this->assertSame(120, (int) $stored['watched_sec']);
        $this->assertSame(0, (int) $stored['runtime_sec']);
        $this->assertSame(0, (int) $stored['is_finished']);
    }

    public function testImportedEmbyRowsWithNumericIdsAreIdempotent(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2026-08-31 20:14:00.1234567\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t600\t120\t192.0.2.10\t"
        );
        $rows[0]['user_name'] = 'PHPUnit Emby Import';

        $first = $this->repository->importHistoricalPlays($rows);
        $second = $this->repository->importHistoricalPlays($rows);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped']);
    }

    public function testEquivalentPluginGuidSpellingsImportOnlyOnce(): void
    {
        $parser = new PlaybackReportingParser();
        $first = $parser->parseTsv(
            "2024-01-01 12:00:00\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t600"
        );
        $equivalent = $parser->parseTsv(
            "2024-01-01 12:00:00.000\t0E394F8A-9BC6-4ABE-BA29-F63CDC7A12A0\tAAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA\tMovie\tDune\tDirectPlay\tWeb\tChrome\t600"
        );
        $this->assertSame(1, $this->repository->importHistoricalPlays($first)['inserted']);
        $this->assertSame(1, $this->repository->importHistoricalPlays($equivalent, true)['skipped']);
        $second = $this->repository->importHistoricalPlays($equivalent);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['conflicts']);
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('play_history')
            ->where('session_key = %s', $first[0]['session_key'])->fetchSingle());
    }

    public function testLegacyPluginKeyIsSkippedExactlyAndAmbiguousSameSecondConflicts(): void
    {
        $parser = new PlaybackReportingParser();
        $first = $parser->parseTsv(
            "2024-01-02 12:00:00.001\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tWeb\tChrome\t600"
        );
        $legacy = $first[0];
        $legacy['session_key'] = $legacy['legacy_session_key'];
        unset($legacy['legacy_session_key']);
        $this->assertSame(1, $this->repository->importNativeHistoricalPlays([$legacy])['inserted']);
        $exact = $this->repository->importHistoricalPlays($first);
        $this->assertSame(1, $exact['skipped']);
        $this->assertSame(0, $exact['conflicts']);

        $ambiguous = $parser->parseTsv(
            "2024-01-02 12:00:00.002\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tWeb\tChrome\t600"
        );
        $preview = $this->repository->importHistoricalPlays($ambiguous, true);
        $commit = $this->repository->importHistoricalPlays($ambiguous);
        $this->assertSame(1, $preview['conflicts']);
        $this->assertSame(1, $commit['conflicts']);
        $this->assertSame(0, $commit['inserted']);
        $this->assertSame(0, $commit['skipped']);
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('play_history')
            ->where('item_id = %s', '1234567')->fetchSingle());
    }

    public function testDistinctFractionalPluginEventsRemainSeparateWithoutLegacyRows(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-03 12:00:00.001\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tWeb\tChrome\t600\n"
            . "2024-01-03 12:00:00.002\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tWeb\tChrome\t600"
        );
        $result = $this->repository->importHistoricalPlays($rows);
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['conflicts']);
    }

    public function testImportSkipsPlaysAlreadyRecordedByThePoller(): void
    {
        $itemId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $userId = '0e394f8a-9bc6-4abe-ba29-f63cdc7a12a0';
        $started = new \DateTimeImmutable('2024-01-01 12:00:00');

        $stream = $this->stream(400, 3600);
        $stream['id'] = 'phpunit-live-overlap';
        $stream['itemId'] = $itemId;
        $stream['userId'] = $userId;
        $this->repository->logActiveStreams([$stream], $started);

        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-01 12:02:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t180"
        );
        $rows[0]['runtime_sec'] = 3600;
        $rows[0]['user_name'] = 'PHPUnit Import';

        $result = $this->repository->importHistoricalPlays($rows);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(
            1,
            (int) $this->dibi->select('COUNT(*)')->from('play_history')->where('item_id = %s', $itemId)->fetchSingle()
        );
        $this->assertSame(
            0,
            (int) $this->dibi->select('COUNT(*)')->from('play_history')
                ->where('item_id = %s', $itemId)
                ->where('session_key LIKE %s', PlaybackReportingParser::SESSION_PREFIX . '%')
                ->fetchSingle()
        );
    }

    public function testImportSkipsLiveOverlapWhenItemIdsDifferOnlyByDashes(): void
    {
        $undashedItemId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $started = new \DateTimeImmutable('2024-01-01 12:00:00');

        $stream = $this->stream(400, 3600);
        $stream['id'] = 'phpunit-live-overlap-undashed';
        $stream['itemId'] = $undashedItemId;
        $stream['userId'] = '0e394f8a9bc64abeba29f63cdc7a12a0';
        $this->repository->logActiveStreams([$stream], $started);

        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-01 12:02:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\t{$undashedItemId}\tMovie\tDune\tDirectPlay\tWeb\tChrome\t180"
        );
        $rows[0]['runtime_sec'] = 3600;
        $rows[0]['user_name'] = 'PHPUnit Import';

        $result = $this->repository->importHistoricalPlays($rows);

        $this->assertSame('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $rows[0]['item_id']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(
            1,
            (int) $this->dibi->select('COUNT(*)')->from('play_history')
                ->where('session_key = %s', 'phpunit-live-overlap-undashed')
                ->fetchSingle()
        );
        $this->assertSame(
            0,
            (int) $this->dibi->select('COUNT(*)')->from('play_history')
                ->where('session_key LIKE %s', PlaybackReportingParser::SESSION_PREFIX . '%')
                ->fetchSingle()
        );
    }

    public function testImportRepairsRuntimeOnDuplicateWhenStoredRuntimeIsZero(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t200"
        );

        $this->repository->importHistoricalPlays($rows);
        $rows[0]['runtime_sec'] = 3600;
        $rows[0]['watched_sec'] = 200;
        $rows[0]['is_finished'] = 0;

        $second = $this->repository->importHistoricalPlays($rows);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped']);

        $stored = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetch();

        $this->assertSame(3600, (int) $stored['runtime_sec']);
        $this->assertSame(200, (int) $stored['watched_sec']);
        $this->assertSame(0, (int) $stored['is_finished']);
        $this->assertNull($stored['ended_at']);
    }

    public function testImportRepairsEndedAtWhenRuntimeMakesThePlayFinished(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t5700"
        );
        $rows[0]['runtime_sec'] = 0;
        $rows[0]['is_finished'] = 0;
        $rows[0]['ended_at'] = null;

        $this->repository->importHistoricalPlays($rows);
        $this->assertSame(0, (int) $this->dibi->select('is_finished')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetchSingle());
        $this->assertNull($this->dibi->select('ended_at')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetchSingle());

        $rows[0]['runtime_sec'] = 6000;
        $rows[0]['watched_sec'] = 5700;
        $rows[0]['is_finished'] = 1;
        $rows[0]['ended_at'] = '2024-01-01 13:35:00';

        $second = $this->repository->importHistoricalPlays($rows);
        $this->assertSame(1, $second['repaired']);

        $stored = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetch();

        $this->assertSame(6000, (int) $stored['runtime_sec']);
        $this->assertSame(1, (int) $stored['is_finished']);
        $this->assertStringStartsWith('2024-01-01 13:35:00', (string) $stored['ended_at']);
        $this->assertStringStartsWith('2024-01-01 13:35:00', (string) $stored['updated_at']);
    }

    public function testImportRepairsGenericLibraryOnDuplicate(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-09 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tEpisode\tShow - s1e1 - Pilot\tDirectPlay\tWeb\tChrome\t180"
        );

        $this->assertSame('TV Shows', $rows[0]['library']);
        $this->assertSame(1, $this->repository->importHistoricalPlays($rows)['inserted']);

        $rows[0]['library'] = 'Anime';
        $second = $this->repository->importHistoricalPlays($rows);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, $second['repaired']);

        $stored = $this->dibi->select('library')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetch();

        $this->assertSame('Anime', $stored['library']);
    }

    public function testImportRepairsMissingConfirmedLibraryMarkerEvenWhenNameIsGeneric(): void
    {
        $rows = (new PlaybackReportingParser())->parseTsv(
            "2024-01-11 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120"
        );
        $this->assertSame(1, $this->repository->importHistoricalPlays($rows)['inserted']);
        $this->assertNull($this->dibi->select('library_resolved_at')->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])->fetchSingle());

        $confirmed = (new PlaybackReportingImporter())->applyLibraries($rows, [
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 'Movies',
        ]);
        $second = $this->repository->importHistoricalPlays($confirmed);
        $this->assertSame(1, $second['repaired']);
        $stored = $this->dibi->select('library, library_resolved_at, library_resolved_at_epoch')
            ->from('play_history')->where('session_key = %s', $rows[0]['session_key'])->fetch();
        $this->assertNotNull($stored);
        $this->assertSame('Movies', $stored['library']);
        $this->assertNotEmpty($stored['library_resolved_at']);
        $this->assertGreaterThan(0, (int) $stored['library_resolved_at_epoch']);
    }

    public function testRuntimeRepairKeepsElapsedEndWhenPlaybackIncludedPause(): void
    {
        $rows = (new PlaybackReportingParser())->parseTsv(
            "2024-01-12 10:00:00.0000000\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t900\t300"
        );
        $this->assertSame('2024-01-12 10:15:00', $rows[0]['updated_at']);
        $this->assertSame(1, $this->repository->importHistoricalPlays($rows)['inserted']);
        $rows[0]['runtime_sec'] = 600;
        $this->assertSame(1, $this->repository->importHistoricalPlays($rows)['repaired']);
        $stored = $this->dibi->select('watched_sec, watch_duration_sec, updated_at, ended_at, is_finished')
            ->from('play_history')->where('session_key = %s', $rows[0]['session_key'])->fetch();
        $this->assertNotNull($stored);
        $this->assertSame(600, (int) $stored['watched_sec']);
        $this->assertSame(600, (int) $stored['watch_duration_sec']);
        $this->assertSame(1, (int) $stored['is_finished']);
        $this->assertStringStartsWith('2024-01-12 10:15:00', (string) $stored['updated_at']);
        $this->assertStringStartsWith('2024-01-12 10:15:00', (string) $stored['ended_at']);
    }

    public function testRuntimeRepairPreservesKnownEpochAcrossAmbiguousLocalTime(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        try {
            $rows = (new PlaybackReportingParser())->parseTsv(
                "2026-10-25 01:55:00.000\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tWeb\tChrome\t1200"
            );
            self::assertSame('2026-10-25 02:15:00', $rows[0]['updated_at']);
            self::assertSame(1, $this->repository->importHistoricalPlays($rows)['inserted']);
            $knownEpoch = (new DateTimeImmutable('2026-10-25T02:15:00+02:00'))->getTimestamp();
            $this->dibi->update('play_history', [
                'updated_at_epoch' => $knownEpoch,
                'ended_at_epoch' => $knownEpoch,
            ])->where('session_key = %s', $rows[0]['session_key'])->execute();

            $rows[0]['runtime_sec'] = 1200;
            self::assertSame(1, $this->repository->importHistoricalPlays($rows)['repaired']);
            $stored = $this->dibi->select('updated_at_epoch, ended_at_epoch')
                ->from('play_history')->where('session_key = %s', $rows[0]['session_key'])->fetch();
            self::assertNotNull($stored);
            self::assertSame($knownEpoch, (int) $stored['updated_at_epoch']);
            self::assertSame($knownEpoch, (int) $stored['ended_at_epoch']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testImportDoesNotReplaceResolvedLibraryWithGenericLabel(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-10 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\tbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\tEpisode\tShow - s1e1 - Pilot\tDirectPlay\tWeb\tChrome\t180"
        );
        $rows[0]['library'] = 'Anime';
        $this->assertSame(1, $this->repository->importHistoricalPlays($rows)['inserted']);

        $rows[0]['library'] = 'TV Shows';
        $second = $this->repository->importHistoricalPlays($rows);
        $this->assertSame(0, $second['repaired']);

        $stored = $this->dibi->select('library')
            ->from('play_history')
            ->where('session_key = %s', $rows[0]['session_key'])
            ->fetch();

        $this->assertSame('Anime', $stored['library']);
    }

    public function testDryRunCountsExistingDuplicatesWithoutWriting(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-07 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120"
        );

        $this->assertSame(1, $this->repository->importHistoricalPlays($rows)['inserted']);
        $dry = $this->repository->importHistoricalPlays($rows, true);

        $this->assertSame(0, $dry['inserted']);
        $this->assertSame(1, $dry['skipped']);
        $this->assertSame(
            1,
            (int) $this->dibi->select('COUNT(*)')->from('play_history')->where('item_id = %s', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')->fetchSingle()
        );
    }

    public function testImportReportsProgressAfterTheBatchCommits(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-08 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120"
        );
        $calls = [];
        $visibleRows = [];

        $this->repository->importHistoricalPlays($rows, false, function (array $payload) use (&$calls, &$visibleRows): void {
            $calls[] = $payload;
            $visibleRows[] = (int) $this->dibi->select('COUNT(*)')->from('play_history')
                ->where('item_id = %s', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')->fetchSingle();
        });

        $this->assertNotEmpty($calls);
        $last = $calls[array_key_last($calls)];
        $this->assertSame('importing', $last['phase']);
        $this->assertSame(1, $last['processed']);
        $this->assertSame(1, $last['total']);
        $this->assertSame(1, $last['inserted']);
        $this->assertSame([1], $visibleRows);
    }

    public function testPlaybackReportingBatchRollsBackWhenALaterRowFails(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = array_merge(
            $parser->parseTsv("2024-01-08 12:00:00.0000000\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tFirst\tDirectPlay\tWeb\tChrome\t120"),
            $parser->parseTsv("2024-01-08 13:00:00.0000000\t0e394f8a9bc64abeba29f63cdc7a12a0\tbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\tMovie\tSecond\tDirectPlay\tWeb\tChrome\t120"),
        );
        $writes = 0;
        $repository = new PlayHistoryRepository(Container::db(), null, static function () use (&$writes): void {
            ++$writes;
            if ($writes === 2) {
                throw new RuntimeException('Injected second-row failure.');
            }
        });

        $cache = new ReflectionProperty(AppSettings::class, 'cache');
        $schemaConnections = new ReflectionProperty(AppSettings::class, 'schemaConnections');
        $previousCache = $cache->getValue();
        $previousSchemaConnections = $schemaConnections->getValue();

        try {
            $cache->setValue(null, null);
            $schemaConnections->setValue(null, null);

            try {
                $repository->importHistoricalPlays($rows);
                self::fail('The injected write failure should escape the batch.');
            } catch (RuntimeException $error) {
                self::assertSame('Injected second-row failure.', $error->getMessage());
            }

            self::assertSame(0, (int) $this->dibi->select('COUNT(*)')->from('play_history')
                ->where('item_id IN %in', ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'])
                ->fetchSingle());
        } finally {
            $cache->setValue(null, $previousCache);
            $schemaConnections->setValue(null, $previousSchemaConnections);
        }
    }

    public function testItemPlaySummariesGroupsPlaysByItemAndKeepsLatest(): void
    {
        $parser = new PlaybackReportingParser();
        $first = $parser->parseTsv(
            "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120"
        );
        $second = $parser->parseTsv(
            "2024-01-02 15:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t180"
        );
        $other = $parser->parseTsv(
            "2024-01-03 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\tbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\tMovie\tArrival\tDirectPlay\tWeb\tChrome\t200"
        );

        $this->repository->importHistoricalPlays([...$first, ...$second, ...$other]);
        $summaries = $this->repository->itemPlaySummaries();
        $byItem = [];
        foreach ($summaries as $row) {
            $byItem[(string) $row['item_id']] = $row;
        }

        $dune = $byItem['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'] ?? null;
        $arrival = $byItem['bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'] ?? null;
        $this->assertNotNull($dune);
        $this->assertNotNull($arrival);
        $this->assertSame(2, (int) $dune['plays']);
        $this->assertSame(300, (int) $dune['watch_sec']);
        $this->assertSame('Dune', (string) $dune['item_name']);
        $this->assertStringStartsWith('2024-01-02 15:00:00', (string) $dune['started_at']);
        $this->assertSame(1, (int) $arrival['plays']);
        $this->assertSame(200, (int) $arrival['watch_sec']);
    }

    public function testStatisticsRowsUseTheCompleteMinimalProjection(): void
    {
        $expected = [
            'item_id',
            'item_type',
            'item_name',
            'series_name',
            'library',
            'library_resolved_at',
            'user_id',
            'user_name',
            'client',
            'play_method',
            'watched_sec',
            'watch_duration_sec',
            'source_video_codec',
            'transcode_reasons',
            'started_at',
        ];
        $itemId = 'phpunit-statistics-projection';
        $this->insertPlay([
            'item_id' => $itemId,
            'library' => 'Movies',
            'library_resolved_at' => '2099-06-19 12:00:00',
            'source_video_codec' => 'HEVC',
            'transcode_reasons' => '["VideoCodecNotSupported"]',
        ]);

        $rows = $this->repository->statisticsRowsForPeriod(
            new DateTimeImmutable('2099-06-19 00:00:00'),
            new DateTimeImmutable('2099-06-20 00:00:00'),
        );
        $row = null;
        foreach ($rows as $candidate) {
            if ((string) $candidate['item_id'] === $itemId) {
                $row = $candidate;
                break;
            }
        }

        $this->assertNotNull($row);
        $actual = array_keys($row->toArray());
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);

        $columns = new ReflectionClassConstant(PlayHistoryRepository::class, 'STATISTICS_COLUMNS');
        $projected = $columns->getValue();
        $this->assertIsArray($projected);
        sort($projected);
        $this->assertSame($expected, $projected);
    }

    /**
     * @return array<string, mixed>
     */
    private function stream(int $watchedSec, int $runtimeSec): array
    {
        return [
            'id' => 'phpunit-session',
            'itemId' => 'phpunit-item',
            'itemType' => 'Episode',
            'itemName' => 'It Reaches Out',
            'seriesName' => 'The Expanse',
            'seasonEp' => 'S3 E8',
            'library' => 'TV Shows',
            'libraryResolved' => false,
            'userId' => 'phpunit-user',
            'user' => 'PHPUnit Viewer',
            'client' => 'Android TV',
            'device' => 'Living Room Shield',
            'playMethod' => 'Transcode',
            'methodLabel' => 'Audio Transcode',
            'watchedSec' => $watchedSec,
            'runtimeSec' => $runtimeSec,
            'sourceVideoCodec' => 'HEVC',
            'sourceAudioCodec' => 'AC3',
            'sourceContainer' => 'MKV',
            'targetVideoCodec' => 'H.264',
            'targetAudioCodec' => 'AAC',
            'targetContainer' => 'MP4',
            'isVideoDirect' => true,
            'isAudioDirect' => false,
            'transcodeReasons' => ['Audio codec not supported'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPlay(array $overrides = []): int
    {
        $this->dibi->insert('play_history', array_merge([
            'session_key' => 'phpunit-' . bin2hex(random_bytes(4)),
            'user_id' => 'phpunit-user',
            'user_name' => 'PHPUnit Viewer',
            'item_id' => 'phpunit-item',
            'item_type' => 'Movie',
            'item_name' => 'Arrival',
            'library' => 'Movies',
            'play_method' => 'DirectPlay',
            'client' => 'Web',
            'device' => 'MacBook',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'started_at' => '2099-06-19 12:00:00',
            'updated_at' => '2099-06-19 12:10:00',
            'is_finished' => 0,
            'notified' => 1,
        ], $overrides))->execute();

        return (int) $this->dibi->getInsertId();
    }

    private function cleanup(): void
    {
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s AND item_id = %s', 'jd-replay-%', 'phpunit-item')
            ->execute();
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', 'phpunit-%')
            ->execute();
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', PlaybackReportingParser::SESSION_PREFIX . '%')
            ->execute();
    }
}
