<?php

declare(strict_types=1);

use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\LibraryFileRemoval;
use Mk\Framework\Jellyseerr\RequestManager;
use PHPUnit\Framework\TestCase;

final class SeerrLibraryFileRemovalTest extends TestCase
{
    private string $cache;
    private SeerrFileFixtureClient $client;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/jellydash-seerr-files-' . bin2hex(random_bytes(8));
        $this->client = new SeerrFileFixtureClient();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cache)) {
            rmdir($this->cache);
        }
    }

    public function testGatesLegacy4kNoncanonicalMovieSlugAndOverlappingEditions(): void
    {
        $resolver = new LibraryFileRemoval($this->client);
        $this->client->version = '2.7.3';
        self::assertNull($resolver->target($this->client->requestDetail(42)));
        self::assertNotNull($resolver->target($this->client->requestDetail(41)));
        $this->client->media['externalServiceSlug'] = '2000-unverified';
        self::assertNull($resolver->target($this->client->requestDetail(41)));
        $this->client->version = '3.5.2';
        self::assertNotNull($resolver->target($this->client->requestDetail(42)));
        $this->client->paths[1] = '/fixture/standard/Movie';
        self::assertNull($resolver->target($this->client->requestDetail(41)));
        $this->client->paths[1] = '/fixture/4k/Movie';
        $this->client->media['status'] = 6;
        self::assertNull($resolver->target($this->client->requestDetail(41)));
        self::assertSame([], $this->client->writes);
    }

    public function testChangedTargetAndRevokedPermissionStopRemoval(): void
    {
        $resolver = new LibraryFileRemoval($this->client);
        $request = $this->client->requestDetail(41);
        $version = RequestManager::version($request);
        $target = $resolver->target($request);
        $manager = new RequestManager($this->client, $this->cache);
        $this->client->paths[0] .= ' changed';
        self::assertSame('file-target-changed', $manager->execute('remove-files', 41, $version, static fn (): bool => true, $target['token']));
        $this->client->paths[0] = '/fixture/standard/Movie';
        $checks = 0;
        $allowed = static function () use (&$checks): bool {
            return ++$checks === 1;
        };
        self::assertSame('denied', $manager->execute('remove-files', 41, $version, $allowed, $target['token']));
        self::assertSame([], $this->client->writes);
    }

    public function testUncertainWriteBlocksAnotherRequestForTheSameEdition(): void
    {
        $resolver = new LibraryFileRemoval($this->client);
        $manager = new RequestManager($this->client, $this->cache);
        $this->client->timedOut = true;
        foreach ([41, 43, 41] as $id) {
            $request = $this->client->requestDetail($id);
            $target = $resolver->target($request);
            self::assertSame('unknown', $manager->execute('remove-files', $id, RequestManager::version($request), static fn (): bool => true, $target['token']));
        }
        self::assertSame([[500, false]], $this->client->writes);
    }

    public function testSuccessfulRemovalKeepsRequestAndOtherEdition(): void
    {
        $request = $this->client->requestDetail(42);
        $version = RequestManager::version($request);
        $target = (new LibraryFileRemoval($this->client))->target($request);
        $manager = new RequestManager($this->client, $this->cache);
        self::assertSame('files-removed', $manager->execute('remove-files', 42, $version, static fn (): bool => true, $target['token']));
        self::assertSame('files-removed', $manager->execute('remove-files', 42, $version, static fn (): bool => true, $target['token']));
        self::assertSame([[500, true]], $this->client->writes);
        self::assertSame(42, $this->client->requestDetail(42)['id']);
        self::assertSame(7, $this->client->media['status4k']);
        self::assertSame(5, $this->client->media['status']);
    }
}

final class SeerrFileFixtureClient extends JellyseerrClient
{
    public string $version = '3.5.2';
    public array $paths = ['/fixture/standard/Movie', '/fixture/4k/Movie'];
    public array $writes = [];
    public bool $timedOut = false;
    public array $media = [
        'id' => 500, 'tmdbId' => 2000, 'mediaType' => 'movie', 'status' => 5, 'status4k' => 5,
        'serviceId' => 0, 'serviceId4k' => 1, 'externalServiceId' => 91, 'externalServiceId4k' => 92, 'externalServiceSlug' => '2000',
    ];

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', 'synthetic-key');
    }

    public function requestDetail(int $requestId): array
    {
        return ['id' => $requestId, 'type' => 'movie', 'is4k' => $requestId === 42, 'status' => 2,
            'media' => $this->media, 'createdAt' => '2026-11-24T01:00:00.000Z'];
    }

    public function movie(int $tmdbId): array
    {
        return ['id' => 2000, 'title' => 'Synthetic Movie'];
    }

    public function libraryRemovalVersion(): ?string
    {
        return $this->version;
    }

    public function libraryServices(string $mediaType): array
    {
        return array_map(static fn (int $id): array => [
            'id' => $id, 'name' => 'Fixture ' . $id, 'useSsl' => false, 'hostname' => '127.0.0.1',
            'port' => 39000 + $id, 'apiKey' => 'synthetic-key-' . $id, 'is4k' => $id === 1, 'isDefault' => true,
        ], [0, 1]);
    }

    public function libraryLookup(array $service, string $kind, int $providerId): array
    {
        return [['id' => str_ends_with($service['url'], '39000') ? 91 : 92, 'tmdbId' => $providerId]];
    }

    public function libraryEntry(array $service, string $kind, int $id): ?array
    {
        $path = $this->paths[$id - 91] ?? null;

        return $path !== null ? ['id' => $id, 'tmdbId' => 2000, 'path' => $path, 'hasFile' => true] : null;
    }

    public function removeLibraryFiles(int $mediaId, bool $is4k): void
    {
        $this->writes[] = [$mediaId, $is4k];
        if ($this->timedOut) {
            throw new RuntimeException('Synthetic timeout.');
        }
        unset($this->paths[(int) $is4k]);
        $this->media[$is4k ? 'status4k' : 'status'] = 7;
    }
}
