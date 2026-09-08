<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class ViewingTimeMeasurementTest extends TestCase
{
    private Database $database;
    private PlayHistoryRepository $repository;

    protected function setUp(): void
    {
        $this->database = Container::db();
        $this->repository = new PlayHistoryRepository($this->database);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testViewingTimeUsesObservedSamplesWithoutCountingSeeksPausesOrGaps(): void
    {
        $database = $this->database;
        $repository = $this->repository;
        $start = new DateTimeImmutable('2026-09-08 12:00:00');

        $repository->logActiveStreams([$this->stream(1800)], $start);
        $this->assertSame(0, $this->duration($database));

        $repository->logActiveStreams([$this->stream(1830, false, 2.0)], $start->modify('+30 seconds'));
        $repository->logActiveStreams([$this->stream(1890, false, 2.0)], $start->modify('+60 seconds'));
        $this->assertSame(60, $this->duration($database));

        $repository->logActiveStreams([$this->stream(1920, true)], $start->modify('+90 seconds'));
        $repository->logActiveStreams([$this->stream(1920, true)], $start->modify('+120 seconds'));
        $repository->logActiveStreams([$this->stream(1920)], $start->modify('+150 seconds'));
        $repository->logActiveStreams([$this->stream(1950)], $start->modify('+180 seconds'));
        $this->assertSame(90, $this->duration($database));

        $repository->logActiveStreams([$this->stream(3000)], $start->modify('+210 seconds'));
        $this->assertSame(120, $this->duration($database));
        $repository->logActiveStreams([$this->stream(2500)], $start->modify('+240 seconds'));
        $repository->logActiveStreams([$this->stream(2900)], $start->modify('+400 seconds'));
        $this->assertSame(120, $this->duration($database));
        $this->assertSame(3000, $this->position($database));
    }

    public function testHalfSpeedPlaybackCountsWallClockTime(): void
    {
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $this->repository->logActiveStreams([$this->stream(100, false, 0.5)], $start);
        $this->repository->logActiveStreams([$this->stream(115, false, 0.5)], $start->modify('+30 seconds'));

        $this->assertSame(30, $this->duration($this->database));
    }

    public function testConflictingNewerWriterIsRereadWithoutLosingEitherInterval(): void
    {
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $this->repository->logActiveStreams([$this->stream(0)], $start);
        $other = new PlayHistoryRepository($this->database);
        $interleaved = false;
        $listener = function (\Dibi\Event $event) use ($other, $start, &$interleaved): void {
            if (!$interleaved && str_contains((string) $event->sql, 'SELECT id, watched_sec')) {
                $interleaved = true;
                $other->logActiveStreams([$this->stream(30)], $start->modify('+30 seconds'));
            }
        };
        $connection = $this->database->getDibi();
        $connection->onEvent[] = $listener;
        try {
            $this->repository->logActiveStreams([$this->stream(60)], $start->modify('+60 seconds'));
        } finally {
            $connection->onEvent = array_values(array_filter(
                $connection->onEvent,
                static fn ($callback): bool => $callback !== $listener,
            ));
        }

        $this->assertTrue($interleaved);
        $this->assertSame(60, $this->duration($this->database));
        $this->assertSame(60, $this->position($this->database));
    }

    public function testCompetingInsertRecordsOneBaselineWithoutDoubleCounting(): void
    {
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $other = new PlayHistoryRepository($this->database);
        $interleaved = false;
        $listener = function (\Dibi\Event $event) use ($other, $start, &$interleaved): void {
            if (!$interleaved && str_contains((string) $event->sql, 'SELECT id, watched_sec')) {
                $interleaved = true;
                $other->logActiveStreams([$this->stream(120)], $start);
            }
        };
        $connection = $this->database->getDibi();
        $connection->onEvent[] = $listener;
        try {
            $this->repository->logActiveStreams([$this->stream(120)], $start);
        } finally {
            $connection->onEvent = array_values(array_filter(
                $connection->onEvent,
                static fn ($callback): bool => $callback !== $listener,
            ));
        }

        $this->assertTrue($interleaved);
        $this->assertSame(1, (int) $connection->select('COUNT(*)')->from('play_history')->fetchSingle());
        $this->assertSame(0, $this->duration($this->database));
    }

    public function testDuplicateAndOlderSamplesCannotDoubleCountOrOverwriteProgress(): void
    {
        $database = $this->database;
        $repository = $this->repository;
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $repository->logActiveStreams([$this->stream(0)], $start);
        $repository->logActiveStreams([$this->stream(30)], $start->modify('+30 seconds'));
        $repository->logActiveStreams([$this->stream(30)], $start->modify('+30 seconds'));
        $repository->logActiveStreams([$this->stream(10)], $start->modify('+10 seconds'));

        $this->assertSame(30, $this->duration($database));
        $this->assertSame(30, $this->position($database));
    }

    public function testOlderSampleCannotRestartAFinishedRow(): void
    {
        $database = $this->database;
        $repository = $this->repository;
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $repository->logActiveStreams([$this->stream(3600)], $start);
        $repository->logActiveStreams([$this->stream(120)], $start->modify('-10 seconds'));

        $row = $database->getDibi()->select('watched_sec, started_at_epoch, watch_duration_sec')
            ->from('play_history')->fetch();
        $this->assertNotNull($row);
        $this->assertSame(3600, (int) $row['watched_sec']);
        $this->assertSame($start->getTimestamp(), (int) $row['started_at_epoch']);
        $this->assertSame(0, (int) $row['watch_duration_sec']);
    }

    public function testEpochPreventsNaiveWallClockFromCreatingAFalseGap(): void
    {
        $database = $this->database;
        $repository = $this->repository;
        $start = new DateTimeImmutable('2026-10-25 02:30:00+03:00');
        $repository->logActiveStreams([$this->stream(600)], $start);
        $database->getDibi()->update('play_history', [
            'updated_at' => '2026-10-24 22:00:00',
            'last_sample_epoch' => null,
            'last_sample_position_sec' => null,
        ])->where('session_key LIKE %s', 'phpunit-viewing-%')->execute();

        $repository->logActiveStreams([$this->stream(630)], $start->modify('+30 seconds'));

        $row = $database->getDibi()->select('watched_sec, started_at_epoch')->from('play_history')->fetch();
        $this->assertNotNull($row);
        $this->assertSame(630, (int) $row['watched_sec']);
        $this->assertSame($start->getTimestamp(), (int) $row['started_at_epoch']);
    }

    public function testLegacyContinuationRemainsEstimatedUntilARealNewPlay(): void
    {
        $database = $this->database;
        $repository = $this->repository;
        $connection = $database->getDibi();
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $repository->logActiveStreams([$this->stream(600)], $start);
        $connection->update('play_history', [
            'watch_duration_sec' => null,
            'last_sample_epoch' => null,
            'last_sample_position_sec' => null,
            'last_sample_paused' => null,
            'last_sample_rate' => null,
        ])->where('session_key LIKE %s', 'phpunit-viewing-%')->execute();

        $repository->logActiveStreams([$this->stream(630)], $start->modify('+30 seconds'));
        $repository->logActiveStreams([$this->stream(660)], $start->modify('+60 seconds'));
        $this->assertNull($connection->select('watch_duration_sec')->from('play_history')->fetchSingle());

        $repository->logActiveStreams([$this->stream(120)], $start->modify('+3 hours'));
        $this->assertSame(0, $this->duration($database));
        $this->assertSame(120, $this->position($database));
    }

    public function testAggregatesUseMeasuredDurationAndLegacyFallback(): void
    {
        $database = $this->database;
        $repository = $this->repository;
        $start = new DateTimeImmutable('2026-09-08 12:00:00');
        $repository->logActiveStreams([$this->stream(900)], $start);
        $repository->logActiveStreams([$this->stream(930)], $start->modify('+30 seconds'));
        $database->getDibi()->insert('play_history', [
            'session_key' => 'phpunit-viewing-legacy', 'item_id' => 'phpunit-viewing-legacy-item', 'item_type' => 'Movie',
            'play_method' => 'DirectPlay', 'watched_sec' => 600, 'runtime_sec' => 1200,
            'started_at' => '2026-09-08 10:00:00', 'updated_at' => '2026-09-08 10:10:00',
            'is_finished' => 0, 'notified' => 1,
        ])->execute();

        $aggregate = $repository->historyAggregate(new \Mk\Framework\Jellyfin\HistoryFilters(range: 'all'), $start);
        $this->assertSame(630, $aggregate['watch_sec']);
        $this->assertSame(1, $aggregate['estimated_plays']);
        $this->assertSame(630, $repository->watchTimeToday($start));
    }

    /** @return array<string, mixed> */
    private function stream(int $position, bool $paused = false, float $rate = 1.0): array
    {
        return [
            'id' => 'phpunit-viewing-session', 'itemId' => 'phpunit-viewing-item', 'itemType' => 'Movie',
            'itemName' => 'Measured movie', 'user' => 'Viewer', 'playMethod' => 'DirectPlay',
            'watchedSec' => $position, 'runtimeSec' => 3600, 'isPaused' => $paused,
            'playbackRate' => $rate,
        ];
    }

    private function duration(Database $database): int
    {
        return (int) $database->getDibi()->select('watch_duration_sec')->from('play_history')->fetchSingle();
    }

    private function position(Database $database): int
    {
        return (int) $database->getDibi()->select('watched_sec')->from('play_history')->fetchSingle();
    }

    private function cleanup(): void
    {
        if (isset($this->database)) {
            $this->database->getDibi()->delete('play_history')
                ->where('session_key LIKE %s', 'phpunit-viewing-%')->execute();
        }
    }
}
