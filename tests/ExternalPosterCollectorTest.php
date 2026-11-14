<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ExternalPosterCollector;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ExternalPosterCollectorTest extends TestCase
{
    private ArtworkRepository $art;
    private string $url;

    protected function setUp(): void
    {
        $this->url = 'https://' . bin2hex(random_bytes(8)) . '.invalid';
        $this->art = new ArtworkRepository(Container::db(), ThemePlaybackExclusions::serverKey($this->url));
        $this->art->anchor('synthetic-server');
        $this->art->confirmLegacy();
        $this->art->remember(['item_id' => 'gone', 'item_type' => 'Movie', 'title' => 'Synthetic movie',
            'series_name' => '', 'library' => 'Movies', 'poster_id' => 'gone', 'observed_at' => 0,
            'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
        $this->art->enqueue('gone', 'Movie', null, 1);
    }

    protected function tearDown(): void
    {
        foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings',
            'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            Container::db()->getDibi()->delete($table)->where('source_key = %s', $this->art->scope)->execute();
        }
        foreach (['movie_identity_items', 'movie_identity_state', 'movie_replacements'] as $table) {
            Container::db()->getDibi()->delete($table)->where('server_key = %s', $this->art->scope)->execute();
        }
    }

    public function testPreviouslyCapturedIdentityCanRecoverAfterEntireMovieDeletion(): void
    {
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->notePosterMetadata('gone', ['Id' => 'gone', 'Type' => 'Movie', 'Name' => 'Synthetic movie',
            'ProviderIds' => ['Tmdb' => '348'], 'ImageTags' => ['Primary' => 'custom']], $lease, 100);
        $this->art->release($lease);
        $queries = 0;
        $client = $this->client($queries);
        $result = (new ExternalPosterCollector(Container::db(), $client, $this->art, fetcher: self::image(...)))->runBatch(101);
        self::assertSame(1, $result['saved']);
        self::assertSame(1, $queries);
        self::assertSame(self::image()['body'], $this->art->externalImage('gone')['body']);
        self::assertSame('gone', $this->art->item('gone')['poster_id']);
        self::assertNull($this->art->image('gone'));
    }

    public function testLegacyTitleSearchPreparesReviewWithoutAutomaticallyChoosingAnIdentity(): void
    {
        $queries = 0;
        (new ExternalPosterCollector(Container::db(), $this->client($queries), $this->art, fetcher: self::image(...)))->runBatch(101);
        self::assertSame(1, $queries);
        self::assertSame('review', $this->art->externalBinding('gone')['state']);
        self::assertNull($this->art->externalBinding('gone')['tmdb_id']);
        self::assertNull($this->art->externalBinding('gone')['verified_id']);
    }

    private function client(int &$queries): JellyfinClient
    {
        return new JellyfinClient($this->url, 'synthetic', true, static function ($path, $method, $payload) use (&$queries): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'synthetic-server'];
            }
            if ($method === 'POST') {
                ++$queries;
                if (isset($payload['SearchInfo']['ProviderIds'])) self::assertArrayNotHasKey('Name', $payload['SearchInfo']);
                return [['Name' => 'Synthetic movie', 'ProviderIds' => ['Tmdb' => '348'],
                    'ImageUrl' => 'https://image.tmdb.org/t/p/w500/a.jpg']];
            }
            return ['Items' => [], 'TotalRecordCount' => 0];
        });
    }

    public function testMetadataCannotBeAppliedAfterItsHistoricalTargetChanges(): void
    {
        $queries = 0;
        $client = new JellyfinClient($this->url, 'synthetic', true, function ($path, $method) use (&$queries): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'synthetic-server'];
            }
            if ($method === 'POST') {
                ++$queries;
                return [];
            }
            $item = $this->art->item('gone');
            $item['poster_id'] = 'new-target';
            $this->art->remember($item);
            return ['Items' => [['Id' => 'gone', 'Name' => 'Synthetic movie', 'Type' => 'Movie',
                'ProviderIds' => ['Tmdb' => '348'], 'ImageTags' => []]], 'TotalRecordCount' => 1];
        });
        $result = (new ExternalPosterCollector(Container::db(), $client, $this->art, fetcher: self::image(...)))->runBatch(101);
        self::assertSame(0, $result['saved']);
        self::assertSame(0, $queries);
        self::assertNull($this->art->externalBinding('gone')['verified_id']);
        self::assertSame('new-target', $this->art->item('gone')['poster_id']);
    }

    public function testAProviderCallbackCannotOverwriteANewerManagerChoice(): void
    {
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->notePosterMetadata('gone', ['Id' => 'gone', 'Type' => 'Movie', 'ProviderIds' => ['Tmdb' => '348']], $lease, 100, 0);
        $this->art->release($lease);
        $downloads = 0;
        $client = new JellyfinClient($this->url, 'synthetic', true, function ($path, $method): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'synthetic-server'];
            }
            if ($method === 'POST') {
                $this->art->externalSelect(
                    ['gone'],
                    $this->art->externalFingerprint(['gone']),
                    ['id' => '999', 'kind' => 'Movie', 'path' => 'b.jpg', 'title' => 'Manager choice', 'year' => 2001]
                );
                return [['Name' => 'Synthetic movie', 'ProviderIds' => ['Tmdb' => '348'], 'ImageUrl' => 'https://image.tmdb.org/t/p/w500/a.jpg']];
            }
            return ['Items' => [], 'TotalRecordCount' => 0];
        });
        (new ExternalPosterCollector(Container::db(), $client, $this->art, fetcher: function () use (&$downloads): array {
            ++$downloads;
            return self::image();
        }))->runBatch(101);
        self::assertSame('999', $this->art->externalBinding('gone')['tmdb_id']);
        self::assertSame('manual', $this->art->externalBinding('gone')['origin']);
        self::assertSame(0, $downloads);
    }

    public function testSourceVerificationKeepsALongerRetryHint(): void
    {
        $client = new JellyfinClient($this->url, 'synthetic', true, static function (): never {
            throw new Mk\Framework\Http\RemoteRequestException('Unavailable', 503, 7200);
        });
        (new ExternalPosterCollector(Container::db(), $client, $this->art))->runBatch(101);
        self::assertSame(7301, (int) $this->art->source()['external_next_attempt']);
    }

    private static function image(): array
    {
        return ['body' => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 'contentType' => 'image/gif'];
    }

    public function testSharedDeletedSeriesNeedsOnlyOneProviderRequestAndDownload(): void
    {
        Container::db()->getDibi()->delete('artwork_items')->where('source_key = %s', $this->art->scope)->execute();
        foreach (['ep-a', 'ep-b'] as $id) {
            $this->art->remember(['item_id' => $id, 'item_type' => 'Episode', 'title' => 'Pilot', 'series_name' => 'Synthetic show',
                'library' => 'TV', 'poster_id' => 'series', 'observed_at' => 0, 'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
        }
        $this->art->enqueue('series', 'Series', null, 1);
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->notePosterMetadata('series', ['Id' => 'series', 'Type' => 'Series', 'ProviderIds' => ['Tmdb' => '1399']], $lease, 100, 0);
        $this->art->release($lease);
        $queries = $downloads = 0;
        $client = new JellyfinClient($this->url, 'synthetic', true, static function ($path, $method) use (&$queries): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'synthetic-server'];
            }
            if ($method === 'POST') {
                ++$queries;
                return [['Name' => 'Synthetic show', 'ProviderIds' => ['Tmdb' => '1399'], 'ImageUrl' => 'https://image.tmdb.org/t/p/w500/a.jpg']];
            }
            return ['Items' => [], 'TotalRecordCount' => 0];
        });
        $result = (new ExternalPosterCollector(Container::db(), $client, $this->art, fetcher: static function () use (&$downloads): array {
            ++$downloads;
            return self::image();
        }))->runBatch(101);
        self::assertSame(2, $result['saved']);
        self::assertSame(1, $queries);
        self::assertSame(1, $downloads);
    }

    public function testDiscoveredParentIsPreservedWhenOnlyTheEpisodeIsLaterDeleted(): void
    {
        Container::db()->getDibi()->delete('artwork_items')->where('source_key = %s', $this->art->scope)->execute();
        $this->art->remember(['item_id' => 'ep', 'item_type' => 'Episode', 'title' => 'Pilot', 'series_name' => 'Synthetic show',
            'library' => 'TV', 'poster_id' => null, 'observed_at' => 0, 'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
        $episodeExists = true;
        $queries = 0;
        $client = new JellyfinClient($this->url, 'synthetic', true, static function ($path, $method) use (&$episodeExists, &$queries): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'synthetic-server'];
            }
            if ($method === 'POST') {
                ++$queries;
                return [];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $id = $query['Ids'] ?? '';
            $item = $id === 'series' ? ['Id' => 'series', 'Type' => 'Series', 'Name' => 'Synthetic show',
                'ProviderIds' => ['Tmdb' => '1399'], 'ImageTags' => ['Primary' => 'custom']]
                : ($episodeExists && $id === 'ep' ? ['Id' => 'ep', 'Type' => 'Episode', 'Name' => 'Pilot',
                    'SeriesName' => 'Synthetic show', 'SeriesId' => 'series'] : null);
            return ['Items' => $item === null ? [] : [$item], 'TotalRecordCount' => $item === null ? 0 : 1];
        });
        $collector = new ExternalPosterCollector(Container::db(), $client, $this->art);
        $collector->runBatch(101);
        self::assertSame('series', $this->art->item('ep')['poster_id']);
        $episodeExists = false;
        $collector->runBatch(90000);
        self::assertSame(0, $queries);
        self::assertSame(1, (int) $this->art->externalBinding('ep')['presence']);
    }

    public function testAReusedLegacyIdWithADifferentTitleCannotBeAutomaticallyMatched(): void
    {
        $queries = 0;
        $client = new JellyfinClient($this->url, 'synthetic', true, static function ($path, $method) use (&$queries): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'synthetic-server'];
            }
            if ($method === 'POST') {
                ++$queries;
                return [];
            }
            return ['Items' => [['Id' => 'gone', 'Type' => 'Movie', 'Name' => 'Another movie',
                'ProviderIds' => ['Tmdb' => '999'], 'ImageTags' => []]], 'TotalRecordCount' => 1];
        });
        (new ExternalPosterCollector(Container::db(), $client, $this->art))->runBatch(101);
        self::assertSame(0, $queries);
        self::assertSame(1, (int) $this->art->externalBinding('gone')['identity_conflict']);
        self::assertNull($this->art->externalBinding('gone')['verified_id']);
    }
}
