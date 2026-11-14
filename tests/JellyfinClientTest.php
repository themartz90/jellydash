<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinClient;
use PHPUnit\Framework\TestCase;

final class JellyfinClientTest extends TestCase
{
    private string $httpDirectory;
    private mixed $httpProcess = null;

    protected function setUp(): void
    {
        $this->httpDirectory = sys_get_temp_dir() . '/jellydash-jellyfin-http-' . bin2hex(random_bytes(8));
        mkdir($this->httpDirectory);

        foreach (['libraryLocations', 'libraryNames'] as $propertyName) {
            $property = new ReflectionProperty(JellyfinClient::class, $propertyName);
            $property->setValue(null, null);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->httpProcess)) {
            proc_terminate($this->httpProcess);
            proc_close($this->httpProcess);
        }

        foreach (glob($this->httpDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->httpDirectory);
    }

    public function testPostJsonSendsPayloadAndDecodesBoundedResponse(): void
    {
        $payload = ['Name' => 'Café', 'Ids' => ['series-1', 'movie-2']];
        $response = (new JellyfinClient($this->startHttpFixture('success'), 'fixture-token'))
            ->postJson('/Items/RemoteSearch/Apply', $payload, 3, 256);

        self::assertSame(['accepted' => true], $response);
        $request = json_decode((string) file_get_contents($this->httpDirectory . '/request.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST /Items/RemoteSearch/Apply HTTP/1.1', $request['line']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame('MediaBrowser Token="fixture-token"', $request['headers']['authorization']);
        self::assertSame($payload, json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testPostJsonRejectsChunkedResponseThatExceedsLimit(): void
    {
        try {
            (new JellyfinClient($this->startHttpFixture('chunked-large'), 'fixture-token'))
                ->postJson('/Items/RemoteSearch/Apply', [], 3, 128);
            self::fail('Expected the chunked response to exceed the configured cap.');
        } catch (RuntimeException $error) {
            self::assertSame('Jellyfin response exceeded its size limit.', $error->getMessage());
        }
    }

    public function testPostJsonDoesNotFollowRedirect(): void
    {
        try {
            (new JellyfinClient($this->startHttpFixture('redirect'), 'fixture-token'))
                ->postJson('/Items/RemoteSearch/Apply', [], 3, 256);
            self::fail('Expected a non-success HTTP response.');
        } catch (RuntimeException $error) {
            self::assertSame('Jellyfin request failed with HTTP 302 (/Items/RemoteSearch/Apply).', $error->getMessage());
        }

        self::assertFileDoesNotExist($this->httpDirectory . '/followed');
    }

    public function testPostJsonPreservesBoundedRetryAfterOnRateLimit(): void
    {
        try {
            (new JellyfinClient($this->startHttpFixture('429'), 'fixture-token'))
                ->postJson('/Items/RemoteSearch/Apply', [], 3, 256);
            self::fail('Expected the rate limit response to be surfaced.');
        } catch (Mk\Framework\Http\RemoteRequestException $error) {
            self::assertSame(429, $error->getCode());
            self::assertSame(120, $error->retryAfterSeconds);
        }
    }

    private function startHttpFixture(string $mode): string
    {
        $this->httpProcess = proc_open([PHP_BINARY, ROOT_DIR . '/tests/fixtures/artwork-metadata-http.php', $this->httpDirectory, $mode], [
            0 => ['pipe', 'r'], 1 => ['file', $this->httpDirectory . '/stdout', 'w'], 2 => ['file', $this->httpDirectory . '/stderr', 'w'],
        ], $pipes);
        self::assertIsResource($this->httpProcess);
        fclose($pipes[0]);

        $deadline = microtime(true) + 5;
        while (!is_file($this->httpDirectory . '/ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($this->httpDirectory . '/ready');

        return 'http://' . trim((string) file_get_contents($this->httpDirectory . '/ready'));
    }

    public function testOversizedRateLimitResponseStillPreservesRetryDelay(): void
    {
        try {
            (new JellyfinClient($this->startHttpFixture('429-large'), 'fixture-token'))
                ->postJson('/Items/RemoteSearch/Movie', [], 3, 128);
            self::fail('Expected rate limit information despite the oversized body.');
        } catch (Mk\Framework\Http\RemoteRequestException $error) {
            self::assertSame(429, $error->getCode());
            self::assertSame(120, $error->retryAfterSeconds);
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
