<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\LibraryOverviewClient;
use Mk\Framework\Jellyfin\LibraryOverviewService;
use Mk\Framework\Jellyfin\NowPlayingService;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\StatisticsPayloadCache;
use Mk\Framework\Jellyfin\ThemePlaybackClassifier;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ThemePlaybackIntegrationTest extends TestCase
{
    private \Dibi\Connection $db;
    private ThemePlaybackExclusions $themes;
    private PlayHistoryRepository $history;
    private string $serverKey;
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $database = Container::db();
        $this->db = $database->getDibi();
        $this->serverKey = ThemePlaybackExclusions::serverKey('http://issue25.example/emby');
        $this->themes = new ThemePlaybackExclusions($database, $this->serverKey);
        $this->history = new PlayHistoryRepository($database, null, null, $this->themes);
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-theme-stats-' . bin2hex(random_bytes(6));
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        foreach (['week', 'month', 'year', 'all'] as $range) {
            @unlink($this->cacheDirectory . DIRECTORY_SEPARATOR . 'statistics-' . $range . '.json');
            @unlink($this->cacheDirectory . DIRECTORY_SEPARATOR . 'statistics-' . $range . '.json.lock');
        }
        @rmdir($this->cacheDirectory);
        @unlink($this->cacheDirectory . '.libraries.json');
        @unlink($this->cacheDirectory . '.libraries.json.lock');
        @unlink($this->cacheDirectory . '.libraries.json.retry');
    }

    public function testConfirmedHistoricalThemeRowsStayStoredButDisappearFromEverySharedRead(): void
    {
        $baselineTotal = $this->history->totalRows();
        $this->insertPlay('issue25-ordinary', 'issue25-ordinary', 'Audio', 'Issue25 ordinary music', 'Issue25 Listener', 'Issue25 Music', 'Issue25 Player', 120);
        $this->insertPlay('issue25-theme', 'issue25-theme', 'Audio', 'Issue25 similar title', 'Issue25 Theme Listener', 'Issue25 Themes', 'Issue25 Theme Player', 90);
        $this->themes->remember('issue25-theme', 'Audio', ThemePlaybackClassifier::THEME, 1_789_000_000);

        self::assertSame(2, (int) $this->db->select('COUNT(*)')->from('play_history')
            ->where('session_key LIKE %s', 'issue25-%')->fetchSingle());
        self::assertSame($baselineTotal + 1, $this->history->totalRows());

        $filters = new HistoryFilters(search: 'Issue25', range: 'all');
        self::assertSame(1, $this->history->historyTotal($filters));
        self::assertSame(1, $this->history->historyAggregate($filters)['plays']);
        self::assertSame(
            ['issue25-ordinary'],
            array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], $this->history->historyRows($filters)),
        );
        self::assertSame(
            ['issue25-ordinary'],
            array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], iterator_to_array($this->history->historyExportRows($filters))),
        );

        $statistics = $this->history->statisticsRowsForPeriod(
            new DateTimeImmutable('2088-01-01 00:00:00'),
            new DateTimeImmutable('2088-01-02 00:00:00'),
        );
        self::assertSame(['issue25-ordinary'], array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], $statistics));
        $summaries = $this->history->itemPlaySummaries();
        self::assertContains('issue25-ordinary', array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], $summaries));
        self::assertNotContains('issue25-theme', array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], $summaries));
        self::assertContains('Issue25 Listener', $this->history->users());
        self::assertNotContains('Issue25 Theme Listener', $this->history->users(true));
        self::assertContains('Issue25 Music', $this->history->libraries());
        self::assertNotContains('Issue25 Themes', $this->history->libraries());
        self::assertContains('Issue25 Player', $this->history->clients());
        self::assertNotContains('Issue25 Theme Player', $this->history->clients());

        $this->history->logActiveStreams([[
            'id' => 'issue25-theme-live-again',
            'itemId' => 'issue25-theme',
            'itemType' => 'Audio',
            'user' => 'Issue25 Theme Listener',
            'playMethod' => 'DirectPlay',
        ]], new DateTimeImmutable('2088-01-01 13:00:00'));
        self::assertSame(0, (int) $this->db->select('COUNT(*)')->from('play_history')
            ->where('session_key = %s', 'issue25-theme-live-again')->fetchSingle());

        $knownThemeImport = $this->importRow('issue25-theme-import', 'issue25-theme');
        self::assertSame(['inserted' => 0, 'skipped' => 1, 'repaired' => 0], $this->history->importHistoricalPlays([$knownThemeImport]));
        self::assertSame(['inserted' => 0, 'skipped' => 1], $this->history->importNativeHistoricalPlays([$knownThemeImport]));
    }

    public function testLiveThemeIsFilteredBeforeMappingAndHistoryRecording(): void
    {
        $calls = [];
        $client = new JellyfinClient('http://issue25.example/emby', 'token', true, static function (string $path) use (&$calls): array {
            $calls[] = $path;
            if ($path === '/Sessions') {
                return [
                    self::session('issue25-live-theme', 'Audio', ['ExtraType' => 'ThemeSong']),
                    self::session('issue25-live-music', 'Audio', ['Path' => 'D:\\Music\\Theme From A Summer Place.mp3']),
                ];
            }
            if (str_starts_with($path, '/Items?')) {
                return ['Items' => []];
            }
            if ($path === '/Library/VirtualFolders') {
                return [];
            }

            return [];
        });

        $payload = (new NowPlayingService($client, null, $this->history))->payload();

        self::assertSame(1, $payload['stats']['active_streams']);
        self::assertSame('issue25-live-music', $payload['streams'][0]['itemId']);
        self::assertSame(0, (int) $this->db->select('COUNT(*)')->from('play_history')
            ->where('item_id = %s', 'issue25-live-theme')->fetchSingle());
        self::assertSame(1, (int) $this->db->select('COUNT(*)')->from('play_history')
            ->where('item_id = %s', 'issue25-live-music')->fetchSingle());
        self::assertNotEmpty($calls);
    }

    public function testUnknownAudioKeepsNormalNotificationBehaviorAndConfirmedThemeIsRetired(): void
    {
        $now = new DateTimeImmutable('2088-01-01 12:05:00');
        $this->insertPlay('issue25-pending', 'issue25-pending', 'Audio', 'Issue25 pending', 'Issue25 Pending User', 'Music', 'Web', 30, 0, '2088-01-01 12:04:50');
        $this->insertPlay('issue25-alert-theme', 'issue25-alert-theme', 'Audio', 'Issue25 theme', 'Issue25 Theme User', 'Music', 'Web', 30, 0, '2088-01-01 12:04:50');
        $this->insertPlay('issue25-alert-normal', 'issue25-alert-normal', 'Movie', 'Issue25 movie', 'Issue25 Movie User', 'Movies', 'Web', 30, 0, '2088-01-01 12:04:50');

        $failingClient = new JellyfinClient('http://issue25.example/emby', 'token', true, static function (): never {
            throw new RuntimeException('metadata unavailable');
        });
        $this->themes->classifyHistoryBatch($failingClient, 25, $now->getTimestamp());
        $this->themes->remember('issue25-alert-theme', 'Audio', ThemePlaybackClassifier::THEME, $now->getTimestamp());

        $claims = $this->history->claimUnnotifiedPlays([], 300, $now);
        $claimedIds = array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], $claims);
        self::assertContains('issue25-pending', $claimedIds);
        self::assertContains('issue25-alert-normal', $claimedIds);
        self::assertNotContains('issue25-alert-theme', $claimedIds);
        self::assertSame(1, (int) $this->db->select('notified')->from('play_history')->where('item_id = %s', 'issue25-alert-theme')->fetchSingle());
    }

    public function testStatisticsCacheRebuildsAsSoonAsAThemeClassificationChanges(): void
    {
        $this->insertPlay('issue25-cache', 'issue25-cache', 'Audio', 'Issue25 cache theme', 'Issue25 Cache User', 'Music', 'Web', 30, 1, '2088-01-01 12:00:00');
        $now = new DateTimeImmutable('2088-01-01 12:10:00');
        $service = new PlaybackStatisticsService(
            $this->history,
            null,
            new StatisticsPayloadCache($this->cacheDirectory, 120),
        );
        $before = $service->cachedData('week', $now);
        self::assertGreaterThanOrEqual(1, $before['kpis'][1]['value']);

        $this->themes->remember('issue25-cache', 'Audio', ThemePlaybackClassifier::THEME, $now->getTimestamp());
        $after = $service->cachedData('week', $now);

        self::assertSame((int) $before['kpis'][1]['value'] - 1, (int) $after['kpis'][1]['value']);
    }

    public function testLibraryCacheRebuildsAsSoonAsAThemeClassificationChanges(): void
    {
        $this->insertPlay('issue25-library-cache', 'issue25-library-cache', 'Audio', 'Issue25 library theme', 'Issue25 User', 'Music', 'Web', 30);
        $client = new ThemeLibraryOverviewClient();
        $service = new LibraryOverviewService(
            $client,
            $this->history,
            $this->cacheDirectory . '.libraries.json',
            null,
            static fn (): int => 1_800_000_000,
        );
        $before = $service->cachedPayload();
        $beforePlays = (int) $before['libraries'][0]['totalPlaysRaw'];
        self::assertGreaterThanOrEqual(1, $beforePlays);
        self::assertSame(1, $client->folderCalls);

        $this->themes->remember('issue25-library-cache', 'Audio', ThemePlaybackClassifier::THEME, 1_800_000_000);
        $after = $service->cachedPayload();

        self::assertSame($beforePlays - 1, (int) $after['libraries'][0]['totalPlaysRaw']);
        self::assertSame(2, $client->folderCalls);
    }

    public function testItemPlaybackMetadataKeepsNumericAndHyphenatedIdsDistinct(): void
    {
        $client = new JellyfinClient('http://issue25.example/emby', 'token', true, static function (string $path): array {
            self::assertStringContainsString('Fields=Path%2CMediaSources', $path);
            self::assertStringNotContainsString('ExtraType', $path);

            return ['Items' => [
                ['Id' => '12345', 'Type' => 'Audio', 'Path' => '/shows/A/theme.mp3'],
                ['Id' => 'a-b', 'Type' => 'Audio', 'Path' => '/music/song.mp3'],
                ['Id' => 'ab', 'Type' => 'Video', 'Path' => '/movies/film.mkv'],
                ['Id' => 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA', 'Type' => 'Video', 'ExtraType' => 'ThemeVideo'],
                ['Id' => 'multiple', 'Type' => 'Audio', 'MediaSources' => [
                    ['Path' => '/shows/A/theme.mp3'],
                    ['Path' => '/music/song.mp3'],
                ]],
            ]];
        });

        $meta = $client->itemPlaybackMeta(['12345', 'a-b', 'ab', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'multiple']);

        self::assertSame(
            ['12345', 'a-b', 'ab', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'multiple'],
            array_map('strval', array_keys($meta)),
        );
        self::assertSame('', $meta['multiple']['Path']);
    }

    /** @return array<string, mixed> */
    private static function session(string $itemId, string $type, array $itemOverrides): array
    {
        return [
            'Id' => 'issue25-session-' . $itemId,
            'UserId' => 'issue25-user',
            'UserName' => 'Issue25 User',
            'Client' => 'Emby for Android TV',
            'DeviceName' => 'Television',
            'PlayState' => ['PositionTicks' => 100000000, 'PlayMethod' => 'DirectPlay'],
            'NowPlayingItem' => array_merge([
                'Id' => $itemId,
                'Type' => $type,
                'Name' => 'Theme',
                'RunTimeTicks' => 1800000000,
            ], $itemOverrides),
        ];
    }

    private function insertPlay(
        string $sessionKey,
        string $itemId,
        string $itemType,
        string $itemName,
        string $user,
        string $library,
        string $client,
        int $watched,
        int $notified = 1,
        string $startedAt = '2088-01-01 12:00:00',
    ): void {
        $this->db->insert('play_history', [
            'session_key' => $sessionKey,
            'user_id' => 'issue25-user',
            'user_name' => $user,
            'item_id' => $itemId,
            'item_type' => $itemType,
            'item_name' => $itemName,
            'library' => $library,
            'play_method' => 'DirectPlay',
            'client' => $client,
            'watched_sec' => $watched,
            'runtime_sec' => 180,
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'is_finished' => 0,
            'notified' => $notified,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->db->delete('play_history')->where('session_key LIKE %s', 'issue25-%')->execute();
        $this->db->delete('play_history')->where('session_key LIKE %s', 'session-issue25-%')->execute();
        $this->db->delete('theme_item_classifications')->where('server_key = %s', $this->serverKey)->execute();
        $this->db->delete('theme_classification_state')->where('server_key = %s', $this->serverKey)->execute();
    }

    /** @return array<string, mixed> */
    private function importRow(string $sessionKey, string $itemId): array
    {
        return [
            'session_key' => $sessionKey,
            'user_id' => 'issue25-user',
            'user_name' => 'Issue25 Import User',
            'item_id' => $itemId,
            'item_type' => 'Audio',
            'item_name' => 'Issue25 Imported Theme',
            'library' => 'Issue25 Themes',
            'play_method' => 'DirectPlay',
            'watched_sec' => 30,
            'runtime_sec' => 180,
            'started_at' => '2088-01-01 12:00:00',
            'updated_at' => '2088-01-01 12:00:30',
            'is_finished' => 0,
            'notified' => 1,
        ];
    }
}

final class ThemeLibraryOverviewClient implements LibraryOverviewClient
{
    public int $folderCalls = 0;

    public function mediaFolders(): array
    {
        ++$this->folderCalls;

        return [['Id' => 'music', 'Name' => 'Music', 'CollectionType' => 'music']];
    }

    public function itemCount(array $query): int
    {
        return (string) ($query['IncludeItemTypes'] ?? '') === 'Audio' ? 1 : 0;
    }
}
