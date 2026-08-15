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
}
