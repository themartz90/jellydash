<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackReportingParser;
use PHPUnit\Framework\TestCase;

final class PlaybackReportingParserTest extends TestCase
{
    private PlaybackReportingParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PlaybackReportingParser();
    }

    public function testParseTsvMapsPluginBackupRows(): void
    {
        $contents = file_get_contents(ROOT_DIR . '/tests/fixtures/playback-reporting.tsv');
        $this->assertIsString($contents);

        $rows = $this->parser->parseTsv($contents);

        $this->assertCount(9, $rows);

        $movie = $rows[1];
        $this->assertSame('pr-', substr((string) $movie['session_key'], 0, 3));
        $this->assertSame('2023-12-04 21:13:05', $movie['started_at']);
        $this->assertSame('0e394f8a-9bc6-4abe-ba29-f63cdc7a12a0', $movie['user_id']);
        $this->assertSame('39a0947d-67b3-22cd-d587-88d1dff5567b', $movie['item_id']);
        $this->assertSame('Movie', $movie['item_type']);
        $this->assertSame('Sample Movie (2018)', $movie['item_name']);
        $this->assertSame('Movies', $movie['library']);
        $this->assertSame('Transcode', $movie['play_method']);
        $this->assertSame('Video Transcode', $movie['play_method_detail']);
        $this->assertSame('H.264', $movie['target_video_codec']);
        $this->assertSame(0, $movie['is_video_direct']);
        $this->assertSame(1, $movie['is_audio_direct']);
        $this->assertSame(127, $movie['watched_sec']);
        $this->assertSame(1, $movie['notified']);
        $this->assertSame(0, $movie['runtime_sec']);
        $this->assertSame(0, $movie['is_finished']);

        $fromFile = iterator_to_array($this->parser->iterateTsvFile(ROOT_DIR . '/tests/fixtures/playback-reporting.tsv'), false);
        $this->assertCount(9, $fromFile);
        $this->assertSame($rows[1]['session_key'], $fromFile[1]['session_key']);

        $sixDigitDate = $rows[2];
        $this->assertSame('2023-12-06 20:03:14', $sixDigitDate['started_at']);
        $this->assertSame('DirectPlay', $sixDigitDate['play_method']);
        $this->assertSame('Direct Play', $sixDigitDate['play_method_detail']);

        $episode = $rows[3];
        $this->assertSame('Episode', $episode['item_type']);
        $this->assertSame('Game of Thrones', $episode['series_name']);
        $this->assertSame('Winter Is Coming', $episode['item_name']);
        $this->assertSame('S1 E1', $episode['season_ep']);
        $this->assertSame('TV Shows', $episode['library']);

        $channel = $rows[4];
        $this->assertSame('TvChannel', $channel['item_type']);
        $this->assertSame('Videos', $channel['library']);
        $this->assertSame('H.264', $channel['target_video_codec']);
        $this->assertSame('AAC', $channel['target_audio_codec']);

        $audioTranscode = $rows[5];
        $this->assertSame('Audio Transcode', $audioTranscode['play_method_detail']);
        $this->assertSame(1, $audioTranscode['is_video_direct']);
        $this->assertSame(0, $audioTranscode['is_audio_direct']);
        $this->assertSame('AAC', $audioTranscode['target_audio_codec']);

        $remux = $rows[6];
        $this->assertSame('Remux', $remux['play_method_detail']);
        $this->assertSame('Transcode', $remux['play_method']);
        $this->assertSame(1, $remux['is_video_direct']);
        $this->assertSame(1, $remux['is_audio_direct']);
    }

    public function testParseTsvStripsBomAndAppliesUserNames(): void
    {
        $line = "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120";
        $rows = $this->parser->parseTsv("\xEF\xBB\xBF" . $line);
        $named = $this->parser->applyUserNames($rows, [
            '0e394f8a9bc64abeba29f63cdc7a12a0' => 'Maya',
        ]);

        $this->assertCount(1, $named);
        $this->assertSame('Maya', $named[0]['user_name']);
        $this->assertSame('2024-01-01 12:00:00', $named[0]['started_at']);
        $this->assertSame('2024-01-01 12:02:00', $named[0]['ended_at']);
    }

    public function testParseApiResultsUsesPluginColumnTypo(): void
    {
        $rows = $this->parser->parseApiResults(
            ['DateCreated', 'UserId', 'ItemId', 'ItemType', 'ItemName', 'PlaybackMethod', 'ClientName', 'DeviceName', 'PlayDuration'],
            [[
                '2024-03-01 08:00:00',
                '0e394f8a9bc64abeba29f63cdc7a12a0',
                'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'AudioBook',
                'Chapter 2',
                'DirectPlay',
                'Android',
                'Phone',
                '180',
            ]],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('AudioBook', $rows[0]['item_type']);
        $this->assertSame('Music', $rows[0]['library']);
        $this->assertSame('Chapter 2', $rows[0]['item_name']);
        $this->assertSame(180, $rows[0]['watched_sec']);
        $this->assertSame(0, $rows[0]['runtime_sec']);
        $this->assertSame(0, $rows[0]['is_finished']);
    }

    public function testParseSqliteFileReadsPlaybackActivity(): void
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

            $rows = $this->parser->parseSqliteFile($dbPath);
            $this->assertCount(1, $rows);
            $this->assertSame('Arrival', $rows[0]['item_name']);
            $this->assertSame(300, $rows[0]['watched_sec']);
            $this->assertSame(0, $rows[0]['runtime_sec']);
            $this->assertSame(0, $rows[0]['is_finished']);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testReimportUsesStableSessionKey(): void
    {
        $line = "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t120";
        $first = $this->parser->parseTsv($line);
        $second = $this->parser->parseTsv($line);

        $this->assertSame($first[0]['session_key'], $second[0]['session_key']);
    }

    public function testHistoryMetadataAndIdentityAgreeAcrossAllImportSources(): void
    {
        $title = str_repeat('🎬', 16_383);
        $client = str_repeat('界', 90);
        $device = str_repeat('ž', 85);
        $row = ['2024-01-01 12:00:00', '412', '314', 'Movie', $title, 'DirectPlay', $client, $device, '45'];
        $columns = ['DateCreated', 'UserId', 'ItemId', 'ItemType', 'ItemName', 'PlaybackMethod', 'ClientName', 'DeviceName', 'PlayDuration'];
        $path = tempnam(sys_get_temp_dir(), 'jd-history-meta-');
        self::assertNotFalse($path);
        $db = new SQLite3($path);
        try {
            $db->exec('CREATE TABLE PlaybackActivity (' . implode(' TEXT, ', $columns) . ' TEXT)');
            foreach (['accepted' => $row, 'overlong' => array_replace($row, [4 => $title . 'x'])] as $case => $input) {
                $db->exec('DELETE FROM PlaybackActivity');
                $insert = $db->prepare('INSERT INTO PlaybackActivity VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($input as $index => $value) {
                    $insert->bindValue($index + 1, $value, SQLITE3_TEXT);
                }
                $insert->execute()->finalize();
                $insert->close();
                $parsed = [
                    fn () => $this->parser->parseTsv(implode("\t", $input)),
                    fn () => $this->parser->parseApiResults($columns, [$input]),
                    fn () => $this->parser->parseSqliteFile($path),
                ];
                $keys = [];
                foreach ($parsed as $parse) {
                    if ($case === 'overlong') {
                        try {
                            $parse();
                            self::fail('An overlong title was accepted.');
                        } catch (\Mk\Framework\Jellyfin\ImportValidationException $error) {
                            self::assertStringContainsString('item title exceeds the History limit', $error->getMessage());
                        }
                        continue;
                    }
                    [$play] = $parse();
                    self::assertSame($title, $play['item_name']);
                    self::assertSame($client, $play['client']);
                    self::assertSame($device, $play['device']);
                    $keys[] = $play['session_key'];
                }
                if ($case === 'accepted') {
                    self::assertCount(1, array_unique($keys));
                }
            }
        } finally {
            $db->close();
            unlink($path);
        }
    }

    public function testParseTsvKeepsShortAndLongPlayDurations(): void
    {
        $zero = "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t0";
        $short = "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t119";
        $long = "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t90000";
        $negative = "2024-01-01 12:00:00.1234567\t0e394f8a9bc64abeba29f63cdc7a12a0\taaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\tMovie\tDune\tDirectPlay\tWeb\tChrome\t-12";

        $this->assertSame(0, $this->parser->parseTsv($zero)[0]['watched_sec']);
        $this->assertSame(119, $this->parser->parseTsv($short)[0]['watched_sec']);
        $this->assertSame(90000, $this->parser->parseTsv($long)[0]['watched_sec']);
        $this->assertSame([], $this->parser->parseTsv($negative));
    }

    public function testParseEmbyTsvPreservesNumericIdsAndExcludesPausedTime(): void
    {
        $line = "2026-08-31 20:14:00.1234567\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t600\t120\t192.0.2.10\tVideoCodecNotSupported";

        $rows = $this->parser->parseTsv($line);
        $named = $this->parser->applyUserNames($rows, ['7654321' => 'Emby User']);

        $this->assertCount(1, $named);
        $this->assertSame('7654321', $named[0]['user_id']);
        $this->assertSame('Emby User', $named[0]['user_name']);
        $this->assertSame('1234567', $named[0]['item_id']);
        $this->assertSame(480, $named[0]['watched_sec']);
        $this->assertSame('2026-08-31 20:24:00', $named[0]['updated_at']);
        $this->assertSame('2026-08-31 20:24:00', $named[0]['ended_at']);
    }

    public function testParseApiResultsUsesOptionalPauseDuration(): void
    {
        $rows = $this->parser->parseApiResults(
            ['DateCreated', 'UserId', 'ItemId', 'ItemType', 'ItemName', 'PlaybackMethod', 'ClientName', 'DeviceName', 'PlayDuration', 'PauseDuration'],
            [[
                '2026-08-31 20:14:00.1234567',
                '7654321',
                '1234567',
                'Movie',
                'Arrival',
                'DirectPlay',
                'Emby Web',
                'Chrome',
                '600',
                '120',
            ]],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('1234567', $rows[0]['item_id']);
        $this->assertSame(480, $rows[0]['watched_sec']);
        $this->assertSame('2026-08-31 20:24:00', $rows[0]['updated_at']);
    }

    public function testParseEmbySqliteReadsOptionalPauseDuration(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'emby-prdb');
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
                PlayDuration INT,
                PauseDuration INT,
                RemoteAddress TEXT,
                TranscodeReasons TEXT
            )');
            $sqlite->exec("INSERT INTO PlaybackActivity VALUES (
                '2026-08-31 20:14:00.1234567',
                '7654321',
                '1234567',
                'Movie',
                'Arrival',
                'DirectPlay',
                'Emby Web',
                'Chrome',
                600,
                120,
                '192.0.2.10',
                'VideoCodecNotSupported'
            )");
            $sqlite->close();

            $rows = $this->parser->parseSqliteFile($dbPath);

            $this->assertCount(1, $rows);
            $this->assertSame('1234567', $rows[0]['item_id']);
            $this->assertSame(480, $rows[0]['watched_sec']);
            $this->assertSame('2026-08-31 20:24:00', $rows[0]['updated_at']);
        } finally {
            @unlink($dbPath);
        }
    }

    public function testParseTsvStillRejectsUnsupportedIds(): void
    {
        $line = "2026-08-31 20:14:00.1234567\t7654321\temby.item\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t600\t0";

        $this->assertSame([], $this->parser->parseTsv($line));
    }

    public function testParseEmbyTsvHandlesBlankAndOversizedPauseDurations(): void
    {
        $blank = "2026-08-31 20:14:00.1234567\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t600\t\t\t";
        $longPause = "2026-08-31 20:14:00.1234567\t7654321\t1234568\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t600\t900\t\t";
        $invalidPause = "2026-08-31 20:14:00.1234567\t7654321\t1234569\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t600\tpaused\t\t";

        $this->assertSame(600, $this->parser->parseTsv($blank)[0]['watched_sec']);
        $this->assertSame(0, $this->parser->parseTsv($longPause)[0]['watched_sec']);
        $this->assertSame('2026-08-31 20:24:00', $this->parser->parseTsv($longPause)[0]['updated_at']);
        $this->assertSame([], $this->parser->parseTsv($invalidPause));
    }

    public function testImportDurationRejectsValuesOutsideTheHistoryIntegerContract(): void
    {
        $line = static fn (string $play, string $pause = '0'): string =>
            "2026-08-31 20:14:00.1234567\t7654321\t1234567\tMovie\tArrival\tDirectPlay\tEmby Web\tChrome\t{$play}\t{$pause}";

        $maximum = $this->parser->parseTsv($line('2147483647', '2147483646'));
        $this->assertCount(1, $maximum);
        $this->assertSame(1, $maximum[0]['watched_sec']);
        $this->assertCount(1, $this->parser->parseTsv($line('000600', '000120')));

        foreach ([
            $line('2147483648'),
            $line('999999999999999999999999999999999999'),
            $line('-1'),
            $line('600', '2147483648'),
            $line('600', '999999999999999999999999999999999999'),
            $line('600', '-1'),
        ] as $invalid) {
            $this->assertSame([], $this->parser->parseTsv($invalid));
        }
    }

    public function testCanonicalImportIdentityPreservesFractionsAndLegacyEvidence(): void
    {
        $user = '0e394f8a9bc64abeba29f63cdc7a12a0';
        $item = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $line = static fn (string $stamp, string $rawUser, string $rawItem): string =>
            "{$stamp}\t{$rawUser}\t{$rawItem}\tMovie\tDune\tDirectPlay\tWeb\tChrome\t600";
        $first = $this->parser->parseTsv($line('2024-01-01 12:00:00', $user, $item))[0];
        $equivalent = $this->parser->parseTsv($line(
            '2024-01-01 12:00:00.000',
            '0E394F8A-9BC6-4ABE-BA29-F63CDC7A12A0',
            'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA',
        ))[0];
        $fractionOne = $this->parser->parseTsv($line('2024-01-01 12:00:00.0010', $user, $item))[0];
        $fractionOneShort = $this->parser->parseTsv($line('2024-01-01 12:00:00.001', $user, $item))[0];
        $fractionTwo = $this->parser->parseTsv($line('2024-01-01 12:00:00.002', $user, $item))[0];

        $this->assertStringStartsWith('pr-v2-', $first['session_key']);
        $this->assertSame($first['session_key'], $equivalent['session_key']);
        $this->assertNotSame($first['legacy_session_key'], $equivalent['legacy_session_key']);
        $this->assertSame('pr-' . sha1('2024-01-01 12:00:00|' . $user . '|' . $item), $first['legacy_session_key']);
        $this->assertSame($fractionOne['session_key'], $fractionOneShort['session_key']);
        $this->assertNotSame($fractionOne['session_key'], $fractionTwo['session_key']);
        $this->assertNotSame($first['session_key'], $fractionOne['session_key']);
        $this->assertSame([], $this->parser->parseTsv($line('2024-01-01 12:00:00.bad', $user, $item)));
        $this->assertSame([], $this->parser->parseTsv($line('2024-02-30 12:00:00', $user, $item)));
    }
}
