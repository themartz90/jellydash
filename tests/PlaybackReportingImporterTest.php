<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\PlaybackReportingClient;
use Mk\Framework\Jellyfin\PlaybackReportingImporter;
use Mk\Framework\Jellyfin\PlaybackReportingParser;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class PlaybackReportingImporterTest extends TestCase
{
    public function testPreviewFileCountsValidTsvRows(): void
    {
        $path = ROOT_DIR . '/tests/fixtures/playback-reporting.tsv';
        $preview = (new PlaybackReportingImporter())->previewFile($path);

        $this->assertSame('tsv', $preview['kind']);
        $this->assertSame(9, $preview['parsed']);
    }

    public function testPreviewFileDetectsSqliteWithoutParsingAsTsv(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'prdb');
        $this->assertNotFalse($path);
        $dbPath = $path . '.db';
        rename($path, $dbPath);

        try {
            $sqlite = new SQLite3($dbPath);
            $sqlite->exec('CREATE TABLE PlaybackActivity (
                DateCreated DATETIME NOT NULL,
                UserId TEXT,
                ItemId TEXT,
                ItemType TEXT,
                ItemName TEXT,
                PlaybackMethod TEXT,
                ClientName TEXT,
                DeviceName TEXT,
                PlayDuration INT
            )');
            $sqlite->exec("INSERT INTO PlaybackActivity VALUES (
                '2024-04-01 09:00:00.1111111',
                '0e394f8a9bc64abeba29f63cdc7a12a0',
                'cccccccccccccccccccccccccccccccc',
                'Movie',
                'Arrival',
                'DirectPlay',
                'Web',
                'Laptop',
                300
            )");
            $sqlite->close();

            $preview = (new PlaybackReportingImporter())->previewFile($dbPath);
            $this->assertSame('sqlite', $preview['kind']);
            $this->assertSame(1, $preview['parsed']);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testApplyRuntimesLeavesShortSessionsUnfinished(): void
    {
        $rows = $this->parsedLine('2024-01-01 12:00:00.1234567', '11111111111111111111111111111111', 127);
        $itemId = $rows[0]['item_id'];

        $enriched = (new PlaybackReportingImporter())->applyRuntimes($rows, [$itemId => 7200]);

        $this->assertSame(127, $enriched[0]['watched_sec']);
        $this->assertSame(7200, $enriched[0]['runtime_sec']);
        $this->assertSame(0, $enriched[0]['is_finished']);
        $this->assertNull($enriched[0]['ended_at']);
    }

    public function testApplyRuntimesMarksFinishedAtNinetyFivePercentWithoutCappingWatchTime(): void
    {
        $finished = $this->parsedLine('2024-01-02 12:00:00.0000000', '22222222222222222222222222222222', 5700);
        $longerThanRuntime = $this->parsedLine('2024-01-03 12:00:00.0000000', '33333333333333333333333333333333', 5000);
        $importer = new PlaybackReportingImporter();

        $finishedRow = $importer->applyRuntimes($finished, [$finished[0]['item_id'] => 6000])[0];
        $this->assertSame(5700, $finishedRow['watched_sec']);
        $this->assertSame(6000, $finishedRow['runtime_sec']);
        $this->assertSame(1, $finishedRow['is_finished']);
        $this->assertSame('2024-01-02 13:35:00', $finishedRow['ended_at']);

        $longRow = $importer->applyRuntimes($longerThanRuntime, [$longerThanRuntime[0]['item_id'] => 3600])[0];
        $this->assertSame(5000, $longRow['watched_sec']);
        $this->assertSame(3600, $longRow['runtime_sec']);
        $this->assertSame(1, $longRow['is_finished']);
    }

    public function testApplyRuntimesLeavesRuntimeEmptyWhenItemIsUnknown(): void
    {
        $rows = $this->parsedLine('2024-01-04 12:00:00.0000000', '44444444444444444444444444444444', 180);
        $enriched = (new PlaybackReportingImporter())->applyRuntimes($rows, []);

        $this->assertSame(180, $enriched[0]['watched_sec']);
        $this->assertSame(0, $enriched[0]['runtime_sec']);
        $this->assertSame(0, $enriched[0]['is_finished']);
    }

    public function testApplyRuntimesMatchesJellyfinIdsWithoutDashes(): void
    {
        $rows = $this->parsedLine('2024-01-05 12:00:00.0000000', 'a30bd8fdfe192b487b85bd2008b03120', 185);
        $enriched = (new PlaybackReportingImporter())->applyRuntimes($rows, [
            'a30bd8fdfe192b487b85bd2008b03120' => 8026,
        ]);

        $this->assertSame('a30bd8fd-fe19-2b48-7b85-bd2008b03120', $enriched[0]['item_id']);
        $this->assertSame(185, $enriched[0]['watched_sec']);
        $this->assertSame(8026, $enriched[0]['runtime_sec']);
        $this->assertSame(0, $enriched[0]['is_finished']);
    }

    public function testApplyLibrariesReplacesTypeBasedLabel(): void
    {
        $rows = $this->parsedLine('2024-01-06 12:00:00.0000000', 'a30bd8fdfe192b487b85bd2008b03120', 185);
        $this->assertSame('Movies', $rows[0]['library']);

        $enriched = (new PlaybackReportingImporter())->applyLibraries($rows, [
            'a30bd8fdfe192b487b85bd2008b03120' => 'Stand-Up Comedy',
        ]);

        $this->assertSame('Stand-Up Comedy', $enriched[0]['library']);
    }

    public function testApplyLibrariesKeepsFallbackWhenItemIsUnknown(): void
    {
        $rows = $this->parsedLine('2024-01-07 12:00:00.0000000', '55555555555555555555555555555555', 180);
        $enriched = (new PlaybackReportingImporter())->applyLibraries($rows, []);

        $this->assertSame('Movies', $enriched[0]['library']);
    }

    public function testSameDaySessionsStaySeparatePlays(): void
    {
        $first = $this->parsedLine('2024-03-01 10:00:00.0000000', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 600);
        $second = $this->parsedLine('2024-03-01 18:00:00.0000000', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 900);
        $second[0]['client'] = 'Android';

        $this->assertNotSame($first[0]['session_key'], $second[0]['session_key']);
        $this->assertSame('2024-03-01 10:00:00', $first[0]['started_at']);
        $this->assertSame('2024-03-01 18:00:00', $second[0]['started_at']);
        $this->assertSame(600, $first[0]['watched_sec']);
        $this->assertSame(900, $second[0]['watched_sec']);
        $this->assertSame('Android', $second[0]['client']);
    }

    public function testApplyRuntimesKeepsEachSameDaySessionIndependent(): void
    {
        $first = $this->parsedLine('2024-03-03 09:00:00.0000000', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 2000);
        $second = $this->parsedLine('2024-03-03 21:00:00.0000000', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 3500);
        $importer = new PlaybackReportingImporter();
        $enriched = $importer->applyRuntimes(array_merge($first, $second), [$first[0]['item_id'] => 3600]);

        $this->assertCount(2, $enriched);
        $this->assertSame(2000, $enriched[0]['watched_sec']);
        $this->assertSame(0, $enriched[0]['is_finished']);
        $this->assertSame(3500, $enriched[1]['watched_sec']);
        $this->assertSame(1, $enriched[1]['is_finished']);
    }

    public function testPreviewFileCountsEachSameDayRow(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'prtsv');
        $this->assertNotFalse($path);

        try {
            $tsv = "2024-03-04 10:00:00.0000000\t0e394f8a9bc64abeba29f63cdc7a12a0\tcccccccccccccccccccccccccccccccc\tMovie\tDune\tDirectPlay\tWeb\tChrome\t600\n"
                . "2024-03-04 20:00:00.0000000\t0e394f8a9bc64abeba29f63cdc7a12a0\tcccccccccccccccccccccccccccccccc\tMovie\tDune\tDirectPlay\tWeb\tChrome\t700\n"
                . "2024-03-05 10:00:00.0000000\t0e394f8a9bc64abeba29f63cdc7a12a0\tcccccccccccccccccccccccccccccccc\tMovie\tDune\tDirectPlay\tWeb\tChrome\t800\n";
            file_put_contents($path, $tsv);

            $preview = (new PlaybackReportingImporter())->previewFile($path);
            $this->assertSame('tsv', $preview['kind']);
            $this->assertSame(3, $preview['parsed']);
        } finally {
            @unlink($path);
        }
    }

    public function testImportFileProcessesRowsInBoundedChunks(): void
    {
        try {
            $repository = new PlayHistoryRepository(Container::db());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $path = tempnam(sys_get_temp_dir(), 'prtsv');
        $this->assertNotFalse($path);

        try {
            $lines = [];
            for ($i = 0; $i < PlaybackReportingClient::CHUNK_SIZE + 1; $i++) {
                $started = (new DateTimeImmutable('2024-05-01 00:00:00'))
                    ->modify('+' . $i . ' seconds')
                    ->format('Y-m-d H:i:s') . '.0000000';
                $lines[] = $started . "\t0e394f8a9bc64abeba29f63cdc7a12a0\tcccccccccccccccccccccccccccccccc\tMovie\tDune\tDirectPlay\tWeb\tChrome\t60";
            }
            file_put_contents($path, implode("\n", $lines) . "\n");

            $processed = [];
            $result = (new PlaybackReportingImporter(null, $repository))->importFile(
                $path,
                true,
                'tsv',
                static function (array $payload) use (&$processed): void {
                    if (($payload['phase'] ?? '') === 'importing') {
                        $processed[] = (int) $payload['processed'];
                    }
                },
            );

            $this->assertSame(PlaybackReportingClient::CHUNK_SIZE + 1, $result['parsed']);
            $this->assertSame(PlaybackReportingClient::CHUNK_SIZE + 1, $result['inserted']);
            $this->assertContains(PlaybackReportingClient::CHUNK_SIZE, $processed);
            $this->assertSame(PlaybackReportingClient::CHUNK_SIZE + 1, $processed[array_key_last($processed)] ?? 0);
        } finally {
            @unlink($path);
        }
    }

    public function testImportFromPluginWritesEachPageBeforeReadingTheNext(): void
    {
        try {
            $database = Container::db();
            $dibi = $database->getDibi();
            $repository = new PlayHistoryRepository($database);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $rows = [];
        for ($i = 0; $i < PlaybackReportingClient::CHUNK_SIZE + 1; $i++) {
            $started = (new DateTimeImmutable('2024-06-01 00:00:00'))
                ->modify('+' . $i . ' seconds')
                ->format('Y-m-d H:i:s') . '.0000000';
            $rows[] = $this->parsedLine($started, 'dddddddddddddddddddddddddddddddd', 60)[0];
        }

        $itemId = (string) $rows[0]['item_id'];
        $plugin = new FakePlaybackReportingPlugin($rows);
        $countsAfterFirstPage = [];
        $plugin->beforePage = static function (int $offset) use ($dibi, $itemId, &$countsAfterFirstPage): void {
            if ($offset === PlaybackReportingClient::CHUNK_SIZE) {
                $countsAfterFirstPage[] = (int) $dibi->select('COUNT(*)')
                    ->from('play_history')
                    ->where('item_id = %s', $itemId)
                    ->fetchSingle();
            }
        };

        try {
            $result = (new PlaybackReportingImporter(null, $repository, null, $plugin))->importFromPlugin();

            $this->assertSame([PlaybackReportingClient::CHUNK_SIZE, 1, 0], $plugin->fetched);
            $this->assertSame([PlaybackReportingClient::CHUNK_SIZE], $countsAfterFirstPage);
            $this->assertSame(PlaybackReportingClient::CHUNK_SIZE + 1, $result['parsed']);
            $this->assertSame(PlaybackReportingClient::CHUNK_SIZE + 1, $result['inserted']);
        } finally {
            $dibi->delete('play_history')
                ->where('item_id = %s', $itemId)
                ->execute();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parsedLine(
        string $startedAt,
        string $itemHex,
        int $duration,
        string $userHex = '0e394f8a9bc64abeba29f63cdc7a12a0',
    ): array {
        return (new PlaybackReportingParser())->parseTsv(
            $startedAt . "\t" . $userHex . "\t" . $itemHex . "\tMovie\tDune\tDirectPlay\tWeb\tChrome\t" . $duration
        );
    }
}

/**
 * @internal
 */
final class FakePlaybackReportingPlugin extends PlaybackReportingClient
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var callable(int): void|null */
    public $beforePage = null;

    /** @var list<int> */
    public array $fetched = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function activityChunk(PlaybackReportingParser $parser, int $offset, int $limit = self::CHUNK_SIZE): array
    {
        if ($this->beforePage !== null) {
            ($this->beforePage)($offset);
        }

        $page = array_values(array_slice($this->rows, $offset, $limit));
        $this->fetched[] = count($page);

        return [
            'rows' => $page,
            'fetched' => count($page),
        ];
    }
}
