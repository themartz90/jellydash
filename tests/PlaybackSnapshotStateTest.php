<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistorySampleQueue;
use Mk\Framework\Jellyfin\JellyfinSessionMapper;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\PlaybackSnapshot;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class PlaybackSnapshotStateTest extends TestCase
{
    private string $scope;
    private string $id;
    private string $directory;
    private PlayHistoryRepository $history;

    protected function setUp(): void
    {
        $this->id = 'snapshot-' . bin2hex(random_bytes(8));
        $this->scope = hash('sha256', $this->id);
        $this->directory = sys_get_temp_dir() . '/' . $this->id;
        $this->history = new PlayHistoryRepository(Container::db(), new MonitoringExclusions([]), sampleQueue: new HistorySampleQueue($this->directory));
    }

    protected function tearDown(): void
    {
        $db = Container::db()->getDibi();
        $db->delete('play_history')->where('item_id = %s', $this->id)->execute();
        $db->delete('history_playback_sessions')->where('source_key = %s', $this->scope)->execute();
        $db->delete('history_capture_receipts')->where('source_key = %s', $this->scope)->execute();
        if (is_dir($this->directory)) {
            foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->directory);
        }
    }

    public function testBackloggedIdleSurvivesWallClockRollbackAndRetainsBothViews(): void
    {
        $this->capture(30, 1000);
        $this->capture(null, 990);
        $this->capture(0, 980);
        $this->drain();
        self::assertSame(2, $this->rows());
        self::assertSame([30, 0], array_map(static fn ($r): int => (int) $r['watched_sec'], Container::db()->getDibi()->select('watched_sec')->from('play_history')->where('item_id = %s', $this->id)->orderBy('id')->fetchAll()));
    }

    public function testCounterLossWithoutBacklogResynchronizesInsteadOfRejectingNewFrames(): void
    {
        $this->capture(30, 1000);
        $this->drain();
        unlink($this->directory . '/.capture-order.json');
        $this->capture(40, 1030);
        $this->drain();
        self::assertSame(2, $this->rows());
    }

    public function testCounterRecoveryKeepsPendingIdleInItsOriginalJournal(): void
    {
        $this->capture(30, 1000);
        $this->capture(null, 1030);
        unlink($this->directory . '/.capture-order.json');
        $this->capture(0, 1060);
        $this->drain();
        self::assertSame(2, $this->rows());
    }

    public function testTruncatedCounterRecoversPendingIdle(): void
    {
        $this->capture(30, 1000);
        $this->capture(null, 1030);
        file_put_contents($this->directory . '/.capture-order.json', '{"journal":');
        $this->capture(0, 1060);
        $this->drain();
        self::assertSame(2, $this->rows());
    }

    public function testEmptyCounterAfterDrainStartsAFreshScopedViewing(): void
    {
        $this->capture(30, 1000);
        $this->drain();
        file_put_contents($this->directory . '/.capture-order.json', '');
        $this->capture(40, 1030);
        $this->drain();
        self::assertSame(2, $this->rows());
    }

    public function testStateFailureRollsBackItsHistoryWriteAndKeepsTheQueuedObservation(): void
    {
        $this->capture(30, 1000);
        $db = Container::db()->getDibi();
        $events = $db->onEvent;
        $db->onEvent[] = static function ($event): void {
            if ($event->type === Dibi\Event::INSERT && str_contains((string) $event->sql, 'history_playback_sessions')) {
                throw new Dibi\ConstraintViolationException('Synthetic state write failure');
            }
        };
        try {
            $this->drain();
            self::fail('Injected state write must fail.');
        } catch (Dibi\ConstraintViolationException $error) {
            self::assertSame('Synthetic state write failure', $error->getMessage());
        } finally {
            $db->onEvent = $events;
        }
        self::assertSame(0, $this->rows());
        self::assertCount(1, glob($this->directory . '/capture-*.json'));
        $this->drain();
        self::assertSame(1, $this->rows());
    }

    public function testCaptureContenderNeverRunsItsFetch(): void
    {
        $this->capture(0, 1000);
        $lock = fopen($this->directory . '/.capture.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->expectException(Mk\Framework\Jellyfin\HistoryWriteBusyException::class);
            $this->history->captureSnapshot($this->scope, static fn () => throw new LogicException('Must not fetch concurrently'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testWarmRepositoryConstructionDoesNotCommitAnOpenTransaction(): void
    {
        $db = Container::db()->getDibi();
        $db->begin();
        try {
            $db->insert('play_history', ['session_key' => $this->id, 'item_id' => $this->id, 'item_type' => 'Movie',
                'play_method' => 'DirectPlay', 'started_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00'])->execute();
            new PlayHistoryRepository(Container::db());
        } finally {
            $db->rollback();
        }
        self::assertSame(0, $this->rows());
    }

    public function testDuplicatePublishedFrameIsIdempotent(): void
    {
        $this->capture(30, 1000);
        $files = glob($this->directory . '/capture-*.json');
        $body = file_get_contents($files[0]);
        $this->drain();
        file_put_contents($this->directory . '/capture-duplicate.json', $body);
        $this->drain();
        self::assertSame(1, $this->rows());
    }

    public function testRestoredValidCounterUsesTheSurvivingDatabaseHighWater(): void
    {
        $this->capture(30, 1000);
        $this->drain();
        $oldCounter = file_get_contents($this->directory . '/.capture-order.json');
        $this->capture(40, 1030);
        $this->drain();
        file_put_contents($this->directory . '/.capture-order.json', $oldCounter);
        $this->capture(null, 1060);
        $this->capture(0, 1090);
        $this->drain();
        self::assertSame(2, $this->rows());
    }

    public function testBadClientRowDoesNotPoisonOtherClientsOrAdvanceItsState(): void
    {
        $sessions = [];
        foreach (['good-first', 'bad', 'good-last'] as $client) {
            $sessions[] = ['Id' => $this->id . '-' . $client, 'UserId' => 'viewer', 'UserName' => 'Synthetic viewer', 'DeviceId' => 'device',
                'Client' => $client, 'NowPlayingItem' => ['Id' => $this->id, 'Type' => 'Movie', 'Name' => 'Synthetic movie'], 'PlayState' => ['PositionTicks' => 0]];
        }
        $snapshot = PlaybackSnapshot::create($sessions, (new JellyfinSessionMapper())->map($sessions)['streams']);
        $this->history->captureSnapshot($this->scope, static fn (): array => $snapshot + ['at' => new DateTimeImmutable('@1000')]);
        $db = Container::db()->getDibi();
        $events = $db->onEvent;
        $db->onEvent[] = static function ($event): void {
            if ($event->type === Dibi\Event::INSERT && str_contains((string) $event->sql, 'play_history') && str_contains((string) $event->sql, "'bad'")) {
                throw new Dibi\ConstraintViolationException('Synthetic rejected client row');
            }
        };
        try {
            $this->drain();
        } finally {
            $db->onEvent = $events;
        }
        self::assertSame(2, $this->rows());
        self::assertSame(2, (int) $db->select('COUNT(*)')->from('history_playback_sessions')->where('source_key = %s', $this->scope)->fetchSingle());
        self::assertSame([], glob($this->directory . '/capture-*.json'));
    }

    public function testPendingAudioBecomesOrdinaryWithoutLosingItsViewingOrTime(): void
    {
        foreach ([[1000, 0, false], [1030, 30, true], [1060, 60, true]] as [$at, $position, $ordinary]) {
            $raw = [['Id' => $this->id, 'UserId' => 'viewer', 'UserName' => 'Synthetic viewer', 'DeviceId' => 'device', 'Client' => 'Findroid',
                'NowPlayingItem' => ['Id' => $this->id, 'Type' => 'Audio', 'Name' => 'Synthetic track'],
                'PlayState' => ['PositionTicks' => $position * 10000000]]];
            $streams = (new JellyfinSessionMapper())->map($raw)['streams'];
            if ($ordinary) {
                $streams[0]['themeClassification'] = 'ordinary';
                $streams[0]['themeClassificationEpoch'] = $at;
            }
            $snapshot = PlaybackSnapshot::create($raw, $streams);
            $this->history->captureSnapshot($this->scope, static fn (): array => $snapshot + ['at' => new DateTimeImmutable('@' . $at)]);
            $this->drain();
        }
        self::assertSame(1, $this->rows());
        self::assertSame(60, (int) Container::db()->getDibi()->select('watch_duration_sec')->from('play_history')->where('item_id = %s', $this->id)->fetchSingle());
    }

    public function testAdvisoryDatabaseReadFailureDoesNotSplitContinuousPlayback(): void
    {
        $this->capture(0, 1000);
        $this->drain();
        $db = Container::db()->getDibi();
        $events = $db->onEvent;
        $db->onEvent[] = static function ($event): void {
            if (str_contains((string) $event->sql, 'MAX(capture_order)')) {
                throw new RuntimeException('Synthetic unavailable ordering read');
            }
        };
        try {
            $this->capture(30, 1030);
            $this->capture(60, 1060);
        } finally {
            $db->onEvent = $events;
        }
        $this->drain();
        self::assertSame(1, $this->rows());
        self::assertSame(60, (int) $db->select('watch_duration_sec')->from('play_history')->where('item_id = %s', $this->id)->fetchSingle());
    }

    public function testIdleDuringAdvisoryReadFailureStillSeparatesRestart(): void
    {
        $this->capture(30, 1000);
        $this->drain();
        $db = Container::db()->getDibi();
        $events = $db->onEvent;
        $db->onEvent[] = static function ($event): void {
            if (str_contains((string) $event->sql, 'MAX(capture_order)')) {
                throw new RuntimeException('Synthetic unavailable ordering read');
            }
        };
        try {
            $this->capture(null, 1030);
            $this->capture(0, 1060);
        } finally {
            $db->onEvent = $events;
        }
        $this->drain();
        self::assertSame(2, $this->rows());
    }

    public function testCounterRestoreDuringReadOutageRetainsIdleAndReceiptsRejectItsReplay(): void
    {
        $this->capture(0, 1000);
        $this->drain();
        $oldCounter = file_get_contents($this->directory . '/.capture-order.json');
        $this->capture(30, 1030);
        $this->drain();
        file_put_contents($this->directory . '/.capture-order.json', $oldCounter);
        $db = Container::db()->getDibi();
        $events = $db->onEvent;
        $db->onEvent[] = static function ($event): void {
            if (str_contains((string) $event->sql, 'MAX(capture_order)')) {
                throw new RuntimeException('Synthetic unavailable ordering read');
            }
        };
        try {
            $this->capture(null, 1060);
            $this->capture(0, 1090);
        } finally {
            $db->onEvent = $events;
        }
        $files = glob($this->directory . '/capture-*.json');
        sort($files);
        $idle = file_get_contents($files[0]);
        $this->drain();
        self::assertSame(2, $this->rows());
        file_put_contents($this->directory . '/capture-replayed-idle.json', $idle);
        $this->drain();
        $this->capture(30, 1120);
        $this->drain();
        self::assertSame(2, $this->rows());
        self::assertSame(2, (int) $db->select('COUNT(*)')->from('history_capture_receipts')->where('source_key = %s', $this->scope)->fetchSingle());
        self::assertSame([30, 30], array_map(static fn ($row): int => (int) $row['watch_duration_sec'], $db->select('watch_duration_sec')->from('play_history')->where('item_id = %s', $this->id)->orderBy('id')->fetchAll()));
    }

    public function testPendingServerEvidenceSurvivesMissingUuidAndCannotBeBorrowedByAnotherServer(): void
    {
        $state = new Mk\Framework\Jellyfin\PlaybackSessionState(Container::db());
        $frame = ['source' => $this->scope, 'journal' => str_repeat('a', 32), 'order' => 1];
        $connection = ['id' => $this->id, 'owner' => hash('sha256', 'owner'), 'server' => hash('sha256', 'first-server'), 'idle' => false, 'eligible' => false, 'item' => $this->id];
        $first = $state->next($frame, $connection, new DateTimeImmutable('@1000'));
        $state->save($first);
        $frame['order'] = 2;
        $connection['server'] = hash('sha256', '');
        $second = $state->next($frame, $connection, new DateTimeImmutable('@1030'));
        $state->save($second);
        self::assertSame(hash('sha256', 'first-server'), $second['pending_server_key']);
        $frame['order'] = 3;
        $connection['server'] = hash('sha256', 'second-server');
        $connection['eligible'] = true;
        $third = $state->next($frame, $connection, new DateTimeImmutable('@1060'));
        self::assertNotSame($second['pending_stream_key'], $third['stream_key']);
    }

    public function testIdleForPendingOtherServerCannotCloseTheConfirmedViewing(): void
    {
        $state = new Mk\Framework\Jellyfin\PlaybackSessionState(Container::db());
        $frame = ['source' => $this->scope, 'journal' => str_repeat('b', 32), 'order' => 1];
        $connection = ['id' => $this->id, 'owner' => hash('sha256', 'owner'), 'server' => hash('sha256', 'first-server'), 'idle' => false, 'eligible' => true, 'item' => $this->id];
        $state->save($state->next($frame, $connection, new DateTimeImmutable('@1000')));
        $frame['order'] = 2;
        $connection['server'] = hash('sha256', 'second-server');
        $connection['eligible'] = false;
        $state->save($state->next($frame, $connection, new DateTimeImmutable('@1030')));
        $frame['order'] = 3;
        $connection['idle'] = true;
        $connection['item'] = null;
        $third = $state->next($frame, $connection, new DateTimeImmutable('@1060'));
        self::assertSame(1, $third['is_open']);
        self::assertSame(hash('sha256', 'first-server'), $third['server_key']);
        self::assertNull($third['pending_stream_key']);
    }

    public function testForeignQueuedThemeCannotRelabelTheActiveSourcesItem(): void
    {
        $foreign = hash('sha256', $this->id . '-foreign');
        $this->history->captureSnapshot($foreign, fn (): array => ['streams' => [['id' => 'theme-observation', 'itemId' => $this->id,
            'itemType' => 'Audio', 'themeClassification' => 'theme', 'themeClassificationEpoch' => 1000]], 'connections' => [], 'at' => new DateTimeImmutable('@1000')]);
        $this->history->captureSnapshot($this->scope, static fn (): array => ['streams' => [], 'connections' => [], 'at' => new DateTimeImmutable('@1100')]);
        $this->drain();
        self::assertNotSame('theme', $this->history->themePlaybackExclusions()->classification($this->id));
        self::assertSame('theme', (new Mk\Framework\Jellyfin\ThemePlaybackExclusions(Container::db(), $foreign))->classification($this->id));
        Container::db()->getDibi()->delete('theme_item_classifications')->where('item_id = %s', $this->id)->execute();
        Container::db()->getDibi()->delete('theme_classification_state')->where('server_key = %s', $foreign)->execute();
    }

    private function capture(?int $position, int $at): void
    {
        $session = ['Id' => $this->id, 'UserId' => 'viewer', 'UserName' => 'Synthetic viewer', 'DeviceId' => 'device', 'Client' => 'Findroid',
            'NowPlayingItem' => $position === null ? null : ['Id' => $this->id, 'Type' => 'Movie', 'Name' => 'Synthetic movie', 'RunTimeTicks' => 72000000000],
            'PlayState' => ['PositionTicks' => ($position ?? 0) * 10000000, 'IsPaused' => false]];
        $snapshot = PlaybackSnapshot::create([$session], (new JellyfinSessionMapper())->map([$session])['streams']);
        $this->history->captureSnapshot($this->scope, static fn (): array => $snapshot + ['at' => new DateTimeImmutable('@' . $at)]);
    }

    private function drain(): void
    {
        $this->history->drainSnapshots(static fn (array $streams): array => $streams);
    }
    private function rows(): int
    {
        return (int) Container::db()->getDibi()->select('COUNT(*)')->from('play_history')->where('item_id = %s', $this->id)->fetchSingle();
    }
}
