<?php

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryFilters;
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

    public function testLogActiveStreamsStartsFreshPlayAfterLongGap(): void
    {
        $start = new \DateTimeImmutable('2099-06-19 12:00:00');

        // First viewing reaches the half-way point (not finished).
        $this->repository->logActiveStreams([$this->stream(1800, 3600)], $start);

        // Three hours later the same long-lived session+item shows up again near
        // the beginning: a re-watch, which must become a fresh play, not an
        // update of the old row.
        $this->repository->logActiveStreams([$this->stream(120, 3600)], $start->modify('+3 hours'));

        $row = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', 'phpunit-session')
            ->where('item_id = %s', 'phpunit-item')
            ->fetch();

        $started = $row['started_at'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($row['started_at'])
            : new \DateTimeImmutable((string) $row['started_at']);

        $this->assertNotNull($row);
        $this->assertSame(120, (int) $row['watched_sec']); // reset to the new play, not kept at 1800
        $this->assertSame('2099-06-19 15:00:00', $started->format('Y-m-d H:i:s'));
    }

    public function testWatchTimeTodaySumsRowsForCurrentDay(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');

        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);

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

        $this->assertSame(900, $this->repository->watchTimeToday($now));
    }

    public function testHistoryRowsApplyFiltersAndExposeUsers(): void
    {
        $now = new \DateTimeImmutable('2026-06-19 12:00:00');
        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);

        $this->dibi->insert('play_history', [
            'session_key' => 'phpunit-movie',
            'user_id' => 'phpunit-user-2',
            'user_name' => 'Jon Bell',
            'item_id' => 'phpunit-movie-item',
            'item_type' => 'Movie',
            'item_name' => 'Arrival',
            'library' => 'Movies',
            'play_method' => 'DirectPlay',
            'client' => 'Web',
            'device' => 'MacBook',
            'watched_sec' => 1200,
            'runtime_sec' => 1200,
            'started_at' => '2026-06-19 11:00:00',
            'updated_at' => '2026-06-19 11:20:00',
        ])->execute();

        $movieRows = $this->repository->historyRows(new HistoryFilters(library: 'Movies', range: 'all'), $now);
        $searchRows = $this->repository->historyRows(new HistoryFilters(search: 'expanse', range: 'all'), $now);

        $this->assertCount(1, $movieRows);
        $this->assertSame('Arrival', $movieRows[0]['item_name']);
        $this->assertCount(1, $searchRows);
        $this->assertSame('The Expanse', $searchRows[0]['series_name']);
        $this->assertSame(1, $this->repository->historyTotal(new HistoryFilters(user: 'PHPUnit Viewer', range: 'all'), $now));
        $this->assertContains('Jon Bell', $this->repository->users());
        $this->assertContains('PHPUnit Viewer', $this->repository->users());
        $this->assertContains('Movies', $this->repository->libraries());
        $this->assertContains('TV Shows', $this->repository->libraries());
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

    public function testImportReportsProgressAsRowsAreWritten(): void
    {
        $parser = new PlaybackReportingParser();
        $rows = $parser->parseTsv(
            "2024-01-08 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120"
        );
        $calls = [];

        $this->repository->importHistoricalPlays($rows, false, static function (array $payload) use (&$calls): void {
            $calls[] = $payload;
        });

        $this->assertNotEmpty($calls);
        $last = $calls[array_key_last($calls)];
        $this->assertSame('importing', $last['phase']);
        $this->assertSame(1, $last['processed']);
        $this->assertSame(1, $last['total']);
        $this->assertSame(1, $last['inserted']);
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
            ->where('session_key LIKE %s', 'phpunit-%')
            ->execute();
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', PlaybackReportingParser::SESSION_PREFIX . '%')
            ->execute();
    }
}
