<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackReportingImporter;
use Mk\Framework\Jellyfin\PlaybackReportingParser;
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

    public function testMergeSameDayPlaysSumsWatchTimeAndKeepsEarliestSession(): void
    {
        $first = $this->parsedLine('2024-03-01 10:00:00.0000000', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 600);
        $second = $this->parsedLine('2024-03-01 18:00:00.0000000', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 900);
        $second[0]['client'] = 'Android';

        $merged = (new PlaybackReportingImporter())->mergeSameDayPlays(array_merge($second, $first));

        $this->assertCount(1, $merged);
        $this->assertSame($first[0]['session_key'], $merged[0]['session_key']);
        $this->assertSame('2024-03-01 10:00:00', $merged[0]['started_at']);
        $this->assertSame(1500, $merged[0]['watched_sec']);
        $this->assertSame('Web', $merged[0]['client']);
    }

    public function testMergeSameDayPlaysKeepsDifferentDaysAndUsersApart(): void
    {
        $dayOne = $this->parsedLine('2024-03-01 10:00:00.0000000', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 600);
        $dayTwo = $this->parsedLine('2024-03-02 10:00:00.0000000', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 600);
        $otherUser = $this->parsedLine(
            '2024-03-01 11:00:00.0000000',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            600,
            'e6f86694d1604ca0a335d497effd8e2b',
        );

        $merged = (new PlaybackReportingImporter())->mergeSameDayPlays(array_merge($dayOne, $dayTwo, $otherUser));

        $this->assertCount(3, $merged);
        $this->assertSame(600, $merged[0]['watched_sec']);
        $this->assertSame(600, $merged[1]['watched_sec']);
        $this->assertSame(600, $merged[2]['watched_sec']);
    }

    public function testMergeSameDayThenApplyRuntimesKeepsSummedWatchTimeAndMarksFinished(): void
    {
        $first = $this->parsedLine('2024-03-03 09:00:00.0000000', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 2000);
        $second = $this->parsedLine('2024-03-03 21:00:00.0000000', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 2500);
        $importer = new PlaybackReportingImporter();
        $merged = $importer->mergeSameDayPlays(array_merge($first, $second));
        $enriched = $importer->applyRuntimes($merged, [$merged[0]['item_id'] => 3600]);

        $this->assertCount(1, $enriched);
        $this->assertSame(4500, $enriched[0]['watched_sec']);
        $this->assertSame(3600, $enriched[0]['runtime_sec']);
        $this->assertSame(1, $enriched[0]['is_finished']);
    }

    public function testPreviewFileCountsMergedSameDayRows(): void
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
            $this->assertSame(2, $preview['parsed']);
        } finally {
            @unlink($path);
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
