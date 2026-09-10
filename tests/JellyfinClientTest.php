<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinClient;
use PHPUnit\Framework\TestCase;

final class JellyfinClientTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['libraryLocations', 'libraryNames'] as $propertyName) {
            $property = new ReflectionProperty(JellyfinClient::class, $propertyName);
            $property->setValue(null, null);
        }
    }

    public function testFailedLibraryLocationRequestCanBeRetried(): void
    {
        $calls = 0;
        $client = new JellyfinClient('http://jellyfin.test', 'token', true, static function () use (&$calls): array {
            ++$calls;
            if ($calls === 1) {
                throw new RuntimeException('Temporary Jellyfin failure');
            }

            return [['Name' => 'Movies', 'Locations' => ['/media/movies']]];
        });

        try {
            $client->libraryLocations();
            self::fail('The first request should fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Temporary Jellyfin failure', $error->getMessage());
        }

        self::assertSame(['movies' => ['/media/movies']], $client->libraryLocations());
        self::assertSame(['Movies'], $client->libraryNames());
        self::assertSame(2, $calls);
    }

    public function testSuccessfulEmptyLibraryLocationResponseIsCached(): void
    {
        $calls = 0;
        $client = new JellyfinClient('http://jellyfin.test', 'token', true, static function () use (&$calls): array {
            ++$calls;

            return [];
        });

        self::assertSame([], $client->libraryLocations());
        self::assertSame([], $client->libraryLocations());
        self::assertSame(1, $calls);
    }

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

    public function testLibraryNameForPathTreatsWindowsPathsAsCaseInsensitive(): void
    {
        $client = new JellyfinClient('http://jellyfin.test', 'token');

        $this->assertSame(
            'Stand-Up Comedy',
            $client->libraryNameForPath(
                'c:\\MEDIA\\StandUp\\Special.mkv',
                ['stand-up comedy' => ['C:\\media\\standup']],
                ['Stand-Up Comedy'],
            )
        );
        $this->assertNull($client->libraryNameForPath(
            '/DATA/Movies/Film.mkv',
            ['movies' => ['/data/movies']],
            ['Movies'],
        ));
        $this->assertSame('Drive', $client->libraryNameForPath(
            'c:\\Anything\\Film.mkv',
            ['drive' => ['C:\\']],
            ['Drive'],
        ));
    }

    public function testLibraryNameForPathReturnsNullWhenUnmatched(): void
    {
        $client = new JellyfinClient('http://jellyfin.test', 'token');

        $this->assertNull($client->libraryNameForPath('/tmp/orphan.mkv', ['movies' => ['/data/movies']], ['Movies']));
        $this->assertNull($client->libraryNameForPath('', ['movies' => ['/data/movies']], ['Movies']));
    }
}
