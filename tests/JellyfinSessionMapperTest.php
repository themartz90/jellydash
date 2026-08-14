<?php

use Mk\Framework\Jellyfin\JellyfinSessionMapper;
use PHPUnit\Framework\TestCase;

final class JellyfinSessionMapperTest extends TestCase
{
    public function testFiltersNonPlaybackSessionsAndMapsActiveStreams(): void
    {
        $mapper = new JellyfinSessionMapper('http://jellyfin.local:8096');

        $result = $mapper->map([
            $this->episodeSession(),
            $this->dashboardGhost(),
            $this->unknownTypeSession(),
            $this->idleSession(),
        ]);

        $this->assertSame(1, count($result['streams']));
        $this->assertSame(3, $result['hidden_count']);
        $this->assertStringContainsString('Web Dashboard', $result['hidden_sources']);

        $stream = $result['streams'][0];
        $this->assertSame('TV - Episode', $stream['kindLabel']);
        $this->assertSame('Episode', $stream['itemType']);
        $this->assertSame('item-1', $stream['itemId']);
        $this->assertSame('user-1', $stream['userId']);
        $this->assertSame('It Reaches Out', $stream['itemName']);
        $this->assertSame('The Expanse', $stream['seriesName']);
        $this->assertSame('S3 E8', $stream['seasonEp']);
        $this->assertSame('TV Shows', $stream['library']);
        $this->assertSame(750, $stream['watchedSec']);
        $this->assertSame(3000, $stream['runtimeSec']);
        $this->assertSame('The Expanse', $stream['title']);
        $this->assertSame('S3 - E8 - It Reaches Out', $stream['subtitle']);
        $this->assertSame('Maya Okafor', $stream['user']);
        $this->assertSame('MO', $stream['initials']);
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80&tag=maya-face',
            $stream['avatarUrl']
        );
        $this->assertSame('Living Room Shield - Android TV', $stream['deviceLine']);
        $this->assertSame('4K HEVC HDR', $stream['quality']);
        $this->assertTrue($stream['isTranscode']);
        $this->assertFalse($stream['isDirect']);
        $this->assertSame('Audio Transcode', $stream['methodLabel']);
        $this->assertFalse($stream['isPaused']);
        $this->assertSame('Now Playing', $stream['statusLabel']);
        $this->assertSame('25%', $stream['progressPct']);
        $this->assertSame('12:30 / 50:00', $stream['timeLabel']);
        $this->assertSame('38 min left', $stream['remaining']);
        $this->assertSame(12000000, $stream['bitrate']);
        $this->assertStringContainsString('/api/image.php?item=series-1&type=Backdrop', $stream['backdrop']);
        $this->assertSame('HEVC', $stream['sourceVideoCodec']);
        $this->assertSame('AAC', $stream['targetAudioCodec']);
        $this->assertTrue($stream['isVideoDirect']);
        $this->assertFalse($stream['isAudioDirect']);
        $this->assertContains('Audio codec not supported', $stream['transcodeReasons']);
    }

    public function testDirectMovieMapsTitleAndRuntimeSubtitle(): void
    {
        $mapper = new JellyfinSessionMapper();
        $result = $mapper->map([$this->movieSession()]);

        $stream = $result['streams'][0];
        $this->assertSame('Movie', $stream['kindLabel']);
        $this->assertSame('Arrival', $stream['title']);
        $this->assertSame('2016 - Sci-Fi - 1h 56m', $stream['subtitle']);
        $this->assertSame('1080p H264 SDR', $stream['quality']);
        $this->assertFalse($stream['isTranscode']);
        $this->assertTrue($stream['isDirect']);
        $this->assertSame('Direct Play', $stream['methodLabel']);
        $this->assertSame('', $stream['avatarUrl']);
        $this->assertStringContainsString('/api/image.php?item=item-2&type=Backdrop', $stream['backdrop']);
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeSession(): array
    {
        return [
            'Id' => 'session-1',
            'UserId' => 'user-1',
            'UserName' => 'Maya Okafor',
            'UserPrimaryImageTag' => 'maya-face',
            'Client' => 'Android TV',
            'DeviceName' => 'Living Room Shield',
            'PlayState' => [
                'PositionTicks' => 7500000000,
                'PlayMethod' => 'Transcode',
            ],
            'TranscodingInfo' => [
                'Bitrate' => 12000000,
                'Height' => 1080,
                'IsVideoDirect' => true,
                'IsAudioDirect' => false,
                'AudioCodec' => 'aac',
            ],
            'NowPlayingItem' => [
                'Id' => 'item-1',
                'Type' => 'Episode',
                'SeriesId' => 'series-1',
                'ParentBackdropItemId' => 'series-1',
                'SeriesName' => 'The Expanse',
                'Name' => 'It Reaches Out',
                'ParentIndexNumber' => 3,
                'IndexNumber' => 8,
                'RunTimeTicks' => 30000000000,
                'MediaStreams' => [
                    [
                        'Type' => 'Video',
                        'Height' => 2160,
                        'Codec' => 'hevc',
                        'DisplayTitle' => '4K HEVC HDR',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movieSession(): array
    {
        return [
            'Id' => 'session-2',
            'UserId' => 'user-2',
            'UserName' => 'Jon Bell',
            'Client' => 'Jellyfin Web',
            'DeviceName' => 'Office MacBook',
            'PlayState' => [
                'PositionTicks' => 15000000000,
                'PlayMethod' => 'DirectPlay',
            ],
            'NowPlayingItem' => [
                'Id' => 'item-2',
                'Type' => 'Movie',
                'Name' => 'Arrival',
                'ProductionYear' => 2016,
                'Genres' => ['Sci-Fi'],
                'RunTimeTicks' => 69600000000,
                'MediaStreams' => [
                    [
                        'Type' => 'Video',
                        'Height' => 1080,
                        'Codec' => 'h264',
                        'DisplayTitle' => '1080p H264 SDR',
                    ],
                ],
                'MediaSources' => [
                    [
                        'Bitrate' => 18000000,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardGhost(): array
    {
        return [
            'Client' => 'Web Dashboard',
            'DeviceName' => 'Firefox',
            'PlayState' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownTypeSession(): array
    {
        return [
            'Client' => 'Jellyseerr',
            'DeviceName' => 'Remote',
            'PlayState' => [
                'PositionTicks' => 1,
            ],
            'NowPlayingItem' => [
                'Type' => 'Unknown',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function idleSession(): array
    {
        return [
            'Client' => 'Jellyfin Web',
            'DeviceName' => 'Laptop',
            'PlayState' => [],
            'NowPlayingItem' => [
                'Type' => 'Movie',
            ],
        ];
    }
}
