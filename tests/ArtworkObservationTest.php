<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkObservation;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\HistorySampleQueue;
use Mk\Framework\Jellyfin\HistoryWriteBusyException;
use Mk\Framework\Jellyfin\JellyfinSessionMapper;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ArtworkObservationTest extends TestCase
{
    public function testMapperAddsScopedSeriesAndPosterIdentityWithoutChangingPlaybackFiltering(): void
    {
        $baseUrl = 'http://Jellyfin.example.test:8096/';
        $episode = $this->session('Episode');
        $episode['ServerId'] = 'session-server';
        $episode['NowPlayingItem']['ServerId'] = 'item-server';
        $episode['NowPlayingItem']['SeriesId'] = 'series-7';
        $episode['NowPlayingItem']['SeriesPrimaryImageTag'] = 'series.tag_7';
        $mapped = (new JellyfinSessionMapper($baseUrl))->map([$episode]);

        $this->assertCount(1, $mapped['streams']);
        $this->assertSame(ThemePlaybackExclusions::serverKey($baseUrl), $mapped['streams'][0]['artworkSource']);
        $this->assertSame('item-server', $mapped['streams'][0]['artworkServerId']);
        $this->assertSame('series-7', $mapped['streams'][0]['seriesId']);
        $this->assertSame('series.tag_7', $mapped['streams'][0]['posterTag']);

        $movie = $this->session('Movie');
        $movie['ServerId'] = 'session-server';
        $movie['NowPlayingItem']['ImageTags']['Primary'] = 'movie-tag_3';
        $movieStream = (new JellyfinSessionMapper($baseUrl))->map([$movie])['streams'][0];
        $this->assertSame('session-server', $movieStream['artworkServerId']);
        $this->assertSame('movie-tag_3', $movieStream['posterTag']);
        $this->assertArrayNotHasKey('seriesId', $movieStream);

        $other = $this->session('Audio');
        $this->assertArrayNotHasKey('artworkSource', (new JellyfinSessionMapper($baseUrl))->map([$other])['streams'][0]);
        $unconfigured = (new JellyfinSessionMapper())->map([$this->session('Episode')])['streams'][0];
        $this->assertArrayNotHasKey('artworkSource', $unconfigured);
    }

    public function testInvalidOptionalArtworkMetadataDoesNotDiscardAnActivePlay(): void
    {
        $session = $this->session('Episode');
        $session['ServerId'] = 'bad server';
        $session['NowPlayingItem']['SeriesId'] = 'series/unsafe';
        $session['NowPlayingItem']['SeriesPrimaryImageTag'] = 'https://private.invalid/?api_key=secret';

        $result = (new JellyfinSessionMapper('http://jellyfin.local:8096'))->map([$session]);
        $this->assertCount(1, $result['streams']);
        $stream = $result['streams'][0];
        $this->assertSame('', $stream['artworkServerId']);
        $this->assertArrayNotHasKey('seriesId', $stream);
        $this->assertArrayNotHasKey('posterTag', $stream);
    }

    public function testEncodingPreservesPartialIdentityOnlyWithinTheSameSource(): void
    {
        $time = new DateTimeImmutable('2026-11-13T12:34:56.123456+01:00');
        $source = str_repeat('a', 64);
        $previous = ArtworkObservation::encode([
            'itemType' => 'Episode', 'artworkSource' => $source, 'artworkServerId' => 'server-1',
            'seriesId' => 'series-1', 'posterTag' => 'tag-1',
        ], $time);
        $this->assertNotNull($previous);
        $partial = ArtworkObservation::encode([
            'itemType' => 'Episode', 'artworkSource' => $source, 'artworkServerId' => '',
        ], $time->modify('+1 second'), $previous);
        $this->assertSame([
            'source' => $source,
            'server' => 'server-1',
            'series' => 'series-1',
            'tag' => 'tag-1',
            'observedAt' => (int) $time->modify('+1 second')->format('Uu'),
        ], ArtworkObservation::decode($partial));

        $changedSource = ArtworkObservation::encode([
            'itemType' => 'Episode', 'artworkSource' => str_repeat('b', 64), 'artworkServerId' => 'server-2',
        ], $time->modify('+2 seconds'), $previous);
        $this->assertSame([
            'source' => str_repeat('b', 64),
            'server' => 'server-2',
            'series' => null,
            'tag' => null,
            'observedAt' => (int) $time->modify('+2 seconds')->format('Uu'),
        ], ArtworkObservation::decode($changedSource));
        $changedServer = ArtworkObservation::encode([
            'itemType' => 'Episode', 'artworkSource' => $source, 'artworkServerId' => 'server-2',
        ], $time->modify('+2 seconds'), $previous);
        $this->assertSame([
            'source' => $source,
            'server' => 'server-2',
            'series' => null,
            'tag' => null,
            'observedAt' => (int) $time->modify('+2 seconds')->format('Uu'),
        ], ArtworkObservation::decode($changedServer));
        $this->assertNull(ArtworkObservation::decode('{"source":"bad","unexpected":true}'));
        $this->assertNull(ArtworkObservation::encode(['itemType' => 'Movie', 'artworkSource' => 'invalid'], $time));
        $this->assertSame([
            'artworkSource' => str_repeat('c', 64),
            'artworkServerId' => 'server-3',
            'seriesId' => 'series-3',
            'posterTag' => 'safe.tag',
        ], ArtworkObservation::streamFields([
            'artworkSource' => str_repeat('c', 64),
            'artworkServerId' => 'server-3',
            'seriesId' => 'series-3',
            'posterTag' => 'safe.tag',
            'posterUrl' => 'https://private.invalid/?api_key=secret',
            'apiToken' => 'secret',
        ]));
        $this->assertSame(['artworkServerId' => ''], ArtworkObservation::streamFields([
            'artworkSource' => 'https://private.invalid/?token=secret',
            'artworkServerId' => 'server/unsafe',
            'seriesId' => 'series/unsafe',
            'posterTag' => 'tag?token=secret',
        ]));
    }

    public function testQueueRetainsOnlySanitizedArtworkFieldsAndReplaysOriginalMicrosecondTime(): void
    {
        $directory = sys_get_temp_dir() . '/jellydash-artwork-observation-' . bin2hex(random_bytes(8));
        $queue = new HistorySampleQueue($directory);
        $observedAt = new DateTimeImmutable('2026-11-13T12:34:56.123456Z');
        $stream = [
            'id' => 'stream-queue', 'itemId' => 'movie-1', 'itemType' => 'Movie',
            'artworkSource' => str_repeat('d', 64), 'artworkServerId' => 'server-4', 'posterTag' => 'tag-4',
            'posterUrl' => 'https://private.invalid/image?api_key=queue-secret', 'apiToken' => 'queue-secret',
        ];
        try {
            try {
                $queue->record([$stream], $observedAt, static function (): void {
                    throw new HistoryWriteBusyException();
                });
            } catch (HistoryWriteBusyException) {
            }
            $files = glob($directory . '/*.json');
            $this->assertCount(1, $files);
            $stored = (string) file_get_contents($files[0]);
            $this->assertStringNotContainsString('queue-secret', $stored);
            $this->assertStringContainsString(str_repeat('d', 64), $stored);
            /** @var list<array<string, mixed>> $replayedStreams */
            $replayedStreams = [];
            $replayedAt = '';
            $queue->record([], new DateTimeImmutable('2030-01-01T00:00:00Z'), static function (array $streams, DateTimeImmutable $time) use (&$replayedStreams, &$replayedAt): void {
                $replayedStreams = $streams;
                $replayedAt = $time->format('Uu');
            });
            $this->assertSame((int) $observedAt->format('Uu'), (int) $replayedAt);
            $this->assertSame('server-4', $replayedStreams[0]['artworkServerId']);
            $this->assertArrayNotHasKey('posterUrl', $replayedStreams[0]);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($directory . '/.lock');
            @rmdir($directory);
        }
    }

    public function testHistoryKeepsNewestObservationWhenProgressIsSameSecondOrReplayIsOlder(): void
    {
        $database = Container::db();
        $db = $database->getDibi();
        $repository = new PlayHistoryRepository($database);
        $session = 'artobs-' . bin2hex(random_bytes(8));
        $item = 'artobs-item-' . bin2hex(random_bytes(4));
        $source = str_repeat('e', 64);
        $baseTime = new DateTimeImmutable('2026-11-13T12:34:56.100000Z');
        try {
            $repository->logActiveStreams([$this->historyStream($session, $item, [
                'artworkSource' => $source, 'artworkServerId' => 'server-new', 'seriesId' => 'series-new', 'posterTag' => 'tag-new',
                'watchedSec' => 120,
            ])], $baseTime);
            $repository->logActiveStreams([$this->historyStream($session, $item, [
                'artworkSource' => $source, 'artworkServerId' => '', 'watchedSec' => 10,
            ])], $baseTime->modify('+200 milliseconds'));
            $repository->logActiveStreams([$this->historyStream($session, $item, [
                'artworkSource' => str_repeat('f', 64), 'artworkServerId' => 'server-old', 'seriesId' => 'series-old', 'posterTag' => 'tag-old',
                'watchedSec' => 5,
            ])], $baseTime->modify('-1 second'));

            $row = $db->select('watched_sec, artwork_observation, artwork_observed_at')
                ->from('play_history')->where('session_key = %s AND item_id = %s', $session, $item)->fetch();
            $this->assertNotFalse($row);
            $this->assertSame(120, (int) $row['watched_sec']);
            $this->assertSame((int) $baseTime->modify('+200 milliseconds')->format('Uu'), (int) $row['artwork_observed_at']);
            $this->assertSame([
                'source' => $source, 'server' => 'server-new', 'series' => 'series-new', 'tag' => 'tag-new',
                'observedAt' => (int) $baseTime->modify('+200 milliseconds')->format('Uu'),
            ], ArtworkObservation::decode((string) $row['artwork_observation']));

            $statisticsColumns = (new ReflectionClassConstant(PlayHistoryRepository::class, 'STATISTICS_COLUMNS'))->getValue();
            $this->assertNotContains('artwork_observation', $statisticsColumns);
            $this->assertNotContains('artwork_observed_at', $statisticsColumns);
            $csv = fopen('php://temp', 'w+b');
            $this->assertNotFalse($csv);
            (new HistoryCsvExporter($repository))->write(new HistoryFilters(range: 'all'), $csv, $baseTime);
            rewind($csv);
            $header = (string) fgets($csv);
            fclose($csv);
            $this->assertStringNotContainsString('artwork_observation', $header);
            $this->assertStringNotContainsString('artwork_observed_at', $header);
        } finally {
            $db->delete('play_history')->where('session_key = %s AND item_id = %s', $session, $item)->execute();
        }
    }

    public function testQueuedHistoryReplayPersistsObservationWithItsOriginalTime(): void
    {
        $database = Container::db();
        $db = $database->getDibi();
        $session = 'artobs-queue-' . bin2hex(random_bytes(8));
        $item = 'artobs-queue-item-' . bin2hex(random_bytes(4));
        $directory = sys_get_temp_dir() . '/jellydash-artwork-history-' . bin2hex(random_bytes(8));
        $repository = new PlayHistoryRepository($database, sampleQueue: new HistorySampleQueue($directory));
        $observedAt = new DateTimeImmutable('2026-11-13T12:34:56.654321Z');
        mkdir($directory, 0700, true);
        $lock = fopen($directory . '/.lock', 'c');
        $this->assertNotFalse($lock);
        try {
            flock($lock, LOCK_EX);
            try {
                $repository->logActiveStreams([$this->historyStream($session, $item, [
                    'artworkSource' => str_repeat('a', 64), 'artworkServerId' => 'server-queued',
                    'seriesId' => 'series-queued', 'posterTag' => 'tag-queued',
                ])], $observedAt);
                $this->fail('The held sample lock should make the first History write wait.');
            } catch (HistoryWriteBusyException) {
            }
            flock($lock, LOCK_UN);
            $repository->logActiveStreams([], new DateTimeImmutable('2030-01-01T00:00:00Z'));
            $row = $db->select('artwork_observation, artwork_observed_at')
                ->from('play_history')->where('session_key = %s AND item_id = %s', $session, $item)->fetch();
            $this->assertNotFalse($row);
            $this->assertSame((int) $observedAt->format('Uu'), (int) $row['artwork_observed_at']);
            $this->assertSame('series-queued', ArtworkObservation::decode((string) $row['artwork_observation'])['series']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $db->delete('play_history')->where('session_key = %s AND item_id = %s', $session, $item)->execute();
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($directory . '/.lock');
            @rmdir($directory);
        }
    }

    /** @return array<string, mixed> */
    private function session(string $type): array
    {
        return [
            'Id' => 'session-' . strtolower($type),
            'UserId' => 'user-1',
            'UserName' => 'Artwork Tester',
            'PlayState' => ['PositionTicks' => 10_000_000, 'PlayMethod' => 'DirectPlay'],
            'NowPlayingItem' => ['Id' => 'item-1', 'Type' => $type, 'Name' => 'Test item', 'RunTimeTicks' => 1_000_000_000],
        ];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function historyStream(string $session, string $item, array $extra): array
    {
        return array_replace([
            'id' => $session, 'itemId' => $item, 'itemType' => 'Episode', 'itemName' => 'Episode',
            'userId' => 'user', 'user' => 'Artwork Tester', 'playMethod' => 'DirectPlay',
            'watchedSec' => 60, 'runtimeSec' => 3600, 'isPaused' => false, 'playbackRate' => 1.0,
        ], $extra);
    }
}
