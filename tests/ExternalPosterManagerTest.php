<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ExternalPosterCache;
use Mk\Framework\Jellyfin\ExternalPosterManager;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ExternalPosterManagerTest extends TestCase
{
    private ArtworkRepository $art;
    private ExternalPosterManager $manager;
    private JellyfinClient $client;
    private string $cacheRoot;
    private string $url;
    private int $searches = 0;
    private string $identity = 'synthetic-server';

    protected function setUp(): void
    {
        $this->url = 'https://' . bin2hex(random_bytes(8)) . '.invalid';
        $this->art = new ArtworkRepository(Container::db(), ThemePlaybackExclusions::serverKey($this->url));
        $this->cacheRoot = ROOT_DIR . '/internal/artwork-external-execution/manager-tests/' . bin2hex(random_bytes(12));
        $this->client = new JellyfinClient($this->url, 'synthetic-token', true, function (string $path, string $method, ?array $payload): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => $this->identity];
            }
            if (str_starts_with($path, '/Items/RemoteSearch/')) {
                ++$this->searches;
                $name = (string) ($payload['SearchInfo']['Name'] ?? 'Synthetic title');
                return [['Name' => 'Selected ' . $name, 'ProductionYear' => 2001,
                    'ProviderIds' => ['Tmdb' => '101'],
                    'ImageUrl' => 'https://image.tmdb.org/t/p/w500/shared.jpg']];
            }
            throw new RuntimeException('Unexpected synthetic Jellyfin request.');
        });
        $this->manager = $this->makeManager();
    }

    protected function tearDown(): void
    {
        if (isset($this->art)) {
            foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings',
                'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
                Container::db()->getDibi()->delete($table)->where('source_key = %s', $this->art->scope)->execute();
            }
        }
        if (isset($this->cacheRoot) && is_dir($this->cacheRoot)) {
            $this->removeOwnedDirectory($this->cacheRoot);
        }
    }

    public function testFreshScopeHasDefaultRevisionAndCanBeDisabledWithoutPriorAnchor(): void
    {
        self::assertSame(1, $this->manager->status()['configRevision']);
        self::assertTrue($this->manager->status()['enabled']);
        self::assertSame(0, (int) Container::db()->getDibi()->select('COUNT(*)')->from('artwork_sources')
            ->where('source_key = %s', $this->art->scope)->fetchSingle());

        $this->art->configureExternal(false, 1);

        self::assertFalse($this->manager->status()['enabled']);
        self::assertSame(2, $this->manager->status()['configRevision']);
    }

    public function testSnoozingRotatesTheHundredItemWindowAndIsSharedAcrossManagers(): void
    {
        $this->art->anchor($this->identity);
        for ($index = 1; $index <= 101; ++$index) {
            $id = sprintf('movie-%03d', $index);
            $this->addItem($id, 'Same title');
            $this->art->externalObserve($id, 0);
        }

        $first = $this->manager->status()['review'][0];
        self::assertSame(100, $first['count']);
        self::assertSame(1, $this->manager->status()['noticeCount']);
        $this->manager->act($first['ids'], $first['fingerprint'], 'snooze');

        $otherManager = $this->makeManager();
        $rotated = $otherManager->status()['review'][0];
        self::assertSame(1, $rotated['count']);
        self::assertContains('movie-101', $rotated['ids']);
        self::assertNotContains('movie-001', $rotated['ids']);
        $otherManager->act($rotated['ids'], $rotated['fingerprint'], 'snooze');

        self::assertSame(0, $this->manager->status()['noticeCount']);
        self::assertSame(0, $otherManager->status()['noticeCount']);
    }

    public function testSearchTokenIsBoundToExactSubsetAndInvalidatedByConfigOrIdentity(): void
    {
        $this->seedGroup(['movie-a', 'movie-b']);
        $group = $this->manager->status()['review'][0];
        $subset = [$group['ids'][0]];
        $search = $this->manager->search($subset, $group['fingerprint'], 'Same title', 'Movie');
        $token = $search['candidates'][0]['token'];
        $otherId = $group['ids'][1];

        try {
            $this->manager->select([$otherId], $group['fingerprint'], $token);
            self::fail('A search token must not select a different subset.');
        } catch (RuntimeException) {
            self::assertNull($this->art->externalBinding($otherId)['tmdb_id']);
        }

        $this->art->configureExternal(false, (int) $this->manager->status()['configRevision']);
        $this->art->configureExternal(true, (int) $this->manager->status()['configRevision']);
        try {
            $this->manager->select($subset, $group['fingerprint'], $token);
            self::fail('A token from an earlier configuration revision must expire.');
        } catch (RuntimeException) {
            self::assertNull($this->art->externalBinding($subset[0])['tmdb_id']);
        }

        $freshGroup = $this->manager->status()['review'][0];
        $newSearch = $this->manager->search($subset, $freshGroup['fingerprint'], 'Same title', 'Movie');
        $this->art->anchor('replacement-server');
        try {
            $this->manager->select($subset, $freshGroup['fingerprint'], $newSearch['candidates'][0]['token']);
            self::fail('A token must not cross Jellyfin identities.');
        } catch (RuntimeException) {
            self::assertTrue((bool) $this->art->source()['blocked']);
        }
    }

    public function testCachedCandidateCanBeSelectedDuringTemporarySourceCooldown(): void
    {
        $this->seedGroup(['movie-a']);
        $group = $this->manager->status()['review'][0];
        $result = $this->manager->search($group['ids'], $group['fingerprint'], 'Same title', 'Movie');
        Container::db()->getDibi()->update('artwork_sources', ['external_next_attempt' => time() + 3600])
            ->where('source_key = %s', $this->art->scope)->execute();

        $this->manager->select($group['ids'], $group['fingerprint'], $result['candidates'][0]['token']);

        self::assertSame('pending', $this->art->externalBinding('movie-a')['state']);
        self::assertSame(1, $this->searches);
    }

    public function testClearCoversPendingSameIdentityAndStaleJobCannotRestoreBody(): void
    {
        $this->seedGroup(['movie-saved']);
        $this->selectDirect(['movie-saved']);
        $this->publish('movie-saved', 'saved image bytes');
        $saved = $this->art->externalBinding('movie-saved');
        $assetKey = (string) $saved['asset_key'];

        $this->addItem('movie-pending', 'Same title');
        $this->art->externalObserve('movie-pending', 0);
        $this->selectDirect(['movie-pending']);
        $staleJob = $this->art->externalBinding('movie-pending');
        $asset = $this->art->externalAsset($assetKey);
        self::assertNotNull($asset);

        $this->art->clearExternalAsset($assetKey, $this->art->externalAssetFingerprint($assetKey));

        self::assertSame('suppressed', $this->art->externalBinding('movie-pending')['state']);
        self::assertNull($this->art->externalAsset($assetKey));
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertFalse($this->art->publishExternal($staleJob, ['body' => 'late bytes', 'contentType' => 'image/jpeg'], $lease, time()));
        $this->art->release($lease);
        self::assertSame(0, (int) $this->art->source()['used_bytes']);
    }

    public function testConcurrentAttachInvalidatesClearFingerprint(): void
    {
        $this->seedGroup(['movie-a', 'movie-b']);
        $this->selectDirect(['movie-a']);
        $this->publish('movie-a', 'shared poster');
        $key = (string) $this->art->externalBinding('movie-a')['asset_key'];
        $oldFingerprint = $this->art->externalAssetFingerprint($key);

        $this->selectDirect(['movie-b']);
        $this->publish('movie-b', 'shared poster');

        try {
            $this->art->clearExternalAsset($key, $oldFingerprint);
            self::fail('A newly attached History item must invalidate the old clear fingerprint.');
        } catch (RuntimeException) {
            self::assertNotNull($this->art->externalAsset($key));
        }
        $this->art->clearExternalAsset($key, $this->art->externalAssetFingerprint($key));
        self::assertNull($this->art->externalAsset($key));
        self::assertSame(0, (int) $this->art->source()['used_bytes']);
    }

    public function testKeepPlaceholderSurvivesUnlinkAndExternalOffOn(): void
    {
        $this->art->anchor($this->identity);
        $this->addItem('movie-placeholder', 'Placeholder title', 'legacy-poster');
        $this->art->externalObserve('movie-placeholder', 0);
        $this->selectDirect(['movie-placeholder']);
        $binding = $this->art->externalBinding('movie-placeholder');
        $this->art->externalSuppress(['movie-placeholder'], $this->art->externalFingerprint(['movie-placeholder']), true);
        $this->art->manageExternal(['movie-placeholder'], $this->art->externalFingerprint(['movie-placeholder']), 'unlink');
        $revision = (int) $this->art->source()['external_revision'];
        $this->art->configureExternal(false, $revision);
        $this->art->configureExternal(true, $revision + 1);

        self::assertSame('legacy-poster', $this->art->item('movie-placeholder')['poster_id']);
        self::assertSame('suppressed', $this->art->externalBinding('movie-placeholder')['state']);
        self::assertSame(1, (int) $this->art->externalBinding('movie-placeholder')['suppressed']);
        self::assertSame('manual', $binding['origin']);
    }

    public function testWrongJellyfinUuidBlocksAndQuarantinesTheScope(): void
    {
        $this->seedGroup(['movie-a']);
        $this->identity = 'wrong-server';
        $group = $this->manager->status()['review'][0];

        try {
            $this->manager->search($group['ids'], $group['fingerprint'], 'Same title', 'Movie');
            self::fail('Search must stop when the configured Jellyfin UUID changes.');
        } catch (RuntimeException) {
            self::assertTrue((bool) $this->art->source()['blocked']);
            self::assertSame(0, $this->searches);
            self::assertNull($this->art->externalBinding('movie-a')['tmdb_id']);
        }
    }

    private function makeManager(): ExternalPosterManager
    {
        return new ExternalPosterManager(
            Container::db(),
            $this->art,
            $this->client,
            new ExternalPosterCache($this->art->scope, $this->cacheRoot)
        );
    }

    /** @param list<string> $ids */
    private function seedGroup(array $ids): void
    {
        $this->art->anchor($this->identity);
        foreach ($ids as $id) {
            $this->addItem($id, 'Same title');
            $this->art->externalObserve($id, 0);
        }
    }

    private function addItem(string $id, string $title, ?string $posterId = null, string $type = 'Movie'): void
    {
        $this->art->remember(['item_id' => $id, 'item_type' => $type, 'title' => $title,
            'series_name' => $type === 'Episode' ? $title : '', 'library' => $type === 'Episode' ? 'TV' : 'Movies',
            'poster_id' => $posterId, 'observed_at' => 1, 'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
    }

    /** @param list<string> $ids */
    private function selectDirect(array $ids): void
    {
        $this->art->externalSelect(
            $ids,
            $this->art->externalFingerprint($ids),
            ['id' => '101', 'kind' => 'Movie', 'title' => 'Selected title', 'year' => 2001, 'path' => 'shared.jpg']
        );
    }

    private function publish(string $id, string $body): void
    {
        $job = $this->art->externalBinding($id);
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertTrue($this->art->publishExternal($job, ['body' => $body, 'contentType' => 'image/jpeg'], $lease, time()));
        $this->art->release($lease);
    }

    private function removeOwnedDirectory(string $path): void
    {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeOwnedDirectory($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
