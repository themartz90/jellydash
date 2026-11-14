<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistorySampleQueue;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\LiveLibraryResolver;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\NowPlayingService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class PlaybackBoundaryTest extends TestCase
{
    private string $prefix;
    private string $directory;
    private NowPlayingService $service;
    private PlayHistoryRepository $history;
    private array $sessions = [];
    private bool $failRequest = false;

    protected function setUp(): void
    {
        $this->prefix = 'boundary-' . bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir() . '/' . $this->prefix;
        $history = new PlayHistoryRepository(Container::db(), new MonitoringExclusions([]), sampleQueue: new HistorySampleQueue($this->directory));
        $this->history = $history;
        $client = new JellyfinClient(
            'https://' . $this->prefix . '.invalid',
            'synthetic',
            true,
            fn (string $path): array => $path === '/Sessions' ? ($this->failRequest ? throw new RuntimeException('Synthetic session request failure') : $this->sessions) : ['Items' => [], 'TotalRecordCount' => 0]
        );
        $this->service = new NowPlayingService(
            $client,
            history: $history,
            libraryResolver: new LiveLibraryResolver(static fn (): array => []),
            exclusions: new MonitoringExclusions([]),
            movieIdentityCapture: static function (): void {
            }
        );
    }

    protected function tearDown(): void
    {
        $db = Container::db()->getDibi();
        $db->delete('play_history')->where('item_id LIKE %s', $this->prefix . '%')->execute();
        if ($db->getDatabaseInfo()->hasTable('history_playback_sessions')) {
            $db->delete('history_playback_sessions')->where('source_key = %s', hash('sha256', 'https://' . $this->prefix . '.invalid'))->execute();
        }
        if (is_dir($this->directory)) {
            foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->directory);
        }
    }

    public function testConfirmedIdleThenSameMovieCreatesANewViewingAcrossBothCollectors(): void
    {
        $this->sessions = [$this->session('movie', 80)];
        $this->service->recordActivePlays();
        $this->sessions = [$this->session(null)];
        $this->service->payload();
        $this->sessions = [$this->session('movie', 0)];
        $this->service->recordActivePlays();
        self::assertSame(2, $this->rowCount('movie'));
        $rows = Container::db()->getDibi()->select('*')->from('play_history')->where('item_id = %s', $this->prefix . '-movie')->orderBy('id')->fetchAll();
        self::assertSame([80, 0], array_map(static fn ($row): int => (int) $row['watched_sec'], $rows));
        self::assertNotSame($rows[0]['session_key'], $rows[1]['session_key']);
    }

    public function testPausedMovieAndMissingConnectionDoNotInventStops(): void
    {
        $this->sessions = [$this->session('movie', 80)];
        $this->service->recordActivePlays();
        $this->sessions[0]['PlayState']['IsPaused'] = true;
        $this->service->payload();
        $this->sessions = [];
        $this->service->recordActivePlays();
        $this->sessions = [$this->session('movie', 90)];
        $this->service->payload();
        self::assertSame(1, $this->rowCount('movie'));
    }

    public function testDifferentEligibleItemSeparatesReturnToUnfinishedMovie(): void
    {
        foreach (['movie', 'other', 'movie'] as $item) {
            $this->sessions = [$this->session($item, 30)];
            $this->service->recordActivePlays();
        }
        self::assertSame(2, $this->rowCount('movie'));
        self::assertSame(1, $this->rowCount('other'));
    }

    public function testMalformedIdleAndUnknownItemCannotCloseAViewing(): void
    {
        $this->sessions = [$this->session('movie', 30)];
        $this->service->recordActivePlays();
        foreach ([['Id' => $this->prefix], $this->session('unknown')] as $unknown) {
            if (isset($unknown['NowPlayingItem'])) {
                $unknown['NowPlayingItem']['Type'] = 'Folder';
            }
            $this->sessions = [$unknown];
            $this->service->recordActivePlays();
        }
        $this->sessions = [$this->session('movie', 40)];
        $this->service->recordActivePlays();
        self::assertSame(1, $this->rowCount('movie'));
    }

    public function testIncompleteOwnerDoesNotSplitAnExistingViewing(): void
    {
        $this->sessions = [$this->session('movie', 30)];
        $this->service->recordActivePlays();
        unset($this->sessions[0]['DeviceId']);
        $this->service->payload();
        $this->sessions = [$this->session('movie', 40)];
        $this->service->recordActivePlays();
        self::assertSame(1, $this->rowCount('movie'));
    }

    public function testWhitespaceAndDuplicateConnectionAmbiguityCannotChangeCurrentViewing(): void
    {
        $this->sessions = [$this->session('movie', 30)];
        $this->service->recordActivePlays();
        $duplicate = $this->session('other', 0);
        $duplicate['Id'] = ' ' . $duplicate['Id'] . ' ';
        $this->sessions[] = $duplicate;
        $this->service->recordActivePlays();
        $this->sessions = [$this->session('movie', 40)];
        $this->service->recordActivePlays();
        self::assertSame(1, $this->rowCount('movie'));
        self::assertSame(0, $this->rowCount('other'));
    }

    public function testUnresolvedAudioDoesNotActAsAConfirmedItemTransition(): void
    {
        $this->sessions = [$this->session('movie', 30)];
        $this->service->recordActivePlays();
        $this->sessions = [$this->session('other', 0)];
        $this->sessions[0]['NowPlayingItem']['Type'] = 'Audio';
        $this->service->recordActivePlays();
        $this->history->themePlaybackExclusions()->remember($this->prefix . '-other', 'Audio', 'theme', time());
        $this->sessions = [$this->session('movie', 40)];
        $this->service->recordActivePlays();
        self::assertSame(1, $this->rowCount('movie'));
    }

    public function testDisplayOnlyContenderReportsDegradedCollectionWithoutRecordingAnotherFrame(): void
    {
        $this->sessions = [$this->session('movie', 30)];
        $this->service->recordActivePlays();
        $lock = fopen($this->directory . '/.capture.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $result = $this->service->payload();
            self::assertCount(1, $result['streams']);
            self::assertFalse($result['stats']['recording_available']);
            self::assertSame(1, $this->rowCount('movie'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testDisplayOnlyContenderStillHidesCachedThemeUserAndProgress(): void
    {
        $this->sessions = [$this->session('movie', 30), $this->session('other', 30)];
        $this->sessions[1]['NowPlayingItem']['Type'] = 'Audio';
        $this->history->themePlaybackExclusions()->remember($this->prefix . '-other', 'Audio', 'theme', time());
        $this->service->recordActivePlays();
        $lock = fopen($this->directory . '/.capture.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $result = $this->service->payload();
            self::assertCount(1, $result['streams']);
            self::assertSame($this->prefix . '-movie', $result['streams'][0]['itemId']);
            self::assertFalse($result['stats']['recording_available']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testUnavailableQueueKeepsTheLiveDashboardWithDegradedRecording(): void
    {
        file_put_contents($this->directory, 'Synthetic storage obstruction');
        $this->sessions = [$this->session('movie', 30)];
        try {
            $result = $this->service->payload();
            self::assertCount(1, $result['streams']);
            self::assertFalse($result['stats']['recording_available']);
            self::assertSame(0, $this->rowCount('movie'));
        } finally {
            unlink($this->directory);
        }
    }

    public function testFailedSessionRequestDoesNotBecomeAnIdleObservation(): void
    {
        $this->sessions = [$this->session('movie', 30)];
        $this->service->recordActivePlays();
        $this->failRequest = true;
        try {
            $this->service->payload();
            self::fail('A failed upstream request must remain a failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic session request failure', $error->getMessage());
        }
        $this->failRequest = false;
        $this->sessions = [$this->session('movie', 40)];
        $this->service->recordActivePlays();
        self::assertSame(1, $this->rowCount('movie'));
    }

    private function session(?string $item, int $position = 0): array
    {
        return ['Id' => $this->prefix, 'UserId' => 'viewer', 'UserName' => 'Synthetic viewer', 'DeviceId' => 'device',
            'DeviceName' => 'Synthetic device', 'Client' => 'Findroid',
            'NowPlayingItem' => $item === null ? null : ['Id' => $this->prefix . '-' . $item, 'Type' => 'Movie', 'Name' => 'Synthetic movie', 'RunTimeTicks' => 72000000000],
            'PlayState' => ['PositionTicks' => $position * 10000000, 'IsPaused' => false, 'PlayMethod' => 'DirectPlay']];
    }

    private function rowCount(string $item): int
    {
        return (int) Container::db()->getDibi()->select('COUNT(*)')->from('play_history')->where('item_id = %s', $this->prefix . '-' . $item)->fetchSingle();
    }
}
