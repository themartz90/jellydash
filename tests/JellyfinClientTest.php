<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinClient;
use PHPUnit\Framework\TestCase;

final class JellyfinClientTest extends TestCase
{
    public function testLibraryNameForPathPicksTheLongestMatchingFolder(): void
    {
        $client = new JellyfinClient('http://jellyfin.test', 'token');
        $locations = [
            'tv shows' => ['/data/media'],
            'anime' => ['/data/media/anime'],
        ];
        $names = ['TV Shows', 'Anime'];

        $this->assertSame(
            'Anime',
            $client->libraryNameForPath('/data/media/anime/Naruto/S01E01.mkv', $locations, $names)
        );
        $this->assertSame(
            'TV Shows',
            $client->libraryNameForPath('/data/media/expanse/S01E01.mkv', $locations, $names)
        );
    }

    public function testLibraryNameForPathNormalizesWindowsPaths(): void
    {
        $client = new JellyfinClient('http://jellyfin.test', 'token');
        $locations = [
            'stand-up comedy' => ['C:\\media\\standup'],
        ];
        $names = ['Stand-Up Comedy'];

        $this->assertSame(
            'Stand-Up Comedy',
            $client->libraryNameForPath('C:\\media\\standup\\Special.mkv', $locations, $names)
        );
    }

    public function testLibraryNameForPathReturnsNullWhenUnmatched(): void
    {
        $client = new JellyfinClient('http://jellyfin.test', 'token');

        $this->assertNull($client->libraryNameForPath('/tmp/orphan.mkv', ['movies' => ['/data/movies']], ['Movies']));
        $this->assertNull($client->libraryNameForPath('', ['movies' => ['/data/movies']], ['Movies']));
    }
}
