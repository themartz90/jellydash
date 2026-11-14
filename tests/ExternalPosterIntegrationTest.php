<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ArtworkResolver;
use Mk\Framework\Jellyfin\ExternalPosterCollector;
use Mk\Framework\Jellyfin\ExternalPosterManager;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\MovieIdentityRepository;
use Mk\Framework\Jellyfin\MovieReplacements;
use PHPUnit\Framework\TestCase;

final class ExternalPosterIntegrationTest extends TestCase
{
    private ArtworkRepository $art;

    protected function setUp(): void
    {
        $this->art = new ArtworkRepository(Container::db(), hash('sha256', random_bytes(16)));
        $this->art->anchor('synthetic-integration');
    }

    protected function tearDown(): void
    {
        $db = Container::db()->getDibi();
        foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings', 'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            $db->delete($table)->where('source_key = %s', $this->art->scope)->execute();
        }
        foreach (['movie_replacements', 'movie_identity_items', 'movie_identity_state'] as $table) {
            if ($db->getDatabaseInfo()->hasTable($table)) {
                $db->delete($table)->where('server_key = %s', $this->art->scope)->execute();
            }
        }
    }

    public function testExternalMovieBorrowingFollowsUndoAndJellyfinArtKeepsPriority(): void
    {
        foreach (['old', 'new'] as $id) {
            $this->item($id);
            $this->art->externalObserve($id, 0, '123');
        }
        $this->art->externalSelect(['old'], $this->art->externalFingerprint(['old']), $this->candidate(), true);
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertTrue($this->art->publishExternal($this->art->externalBinding('old'), $this->image(), $lease, time()));
        $movies = new MovieReplacements(Container::db(), $this->art->scope);
        $movies->link('old', 'new');
        $resolver = new ArtworkResolver(Container::db(), $this->art, $movies);
        self::assertSame($this->image()['body'], $resolver->image('new', false)['body']);
        $movies->unlink('old');
        self::assertNull($resolver->image('new', false));
        $this->art->enqueue('old', 'Movie', null, time());
        self::assertTrue($this->art->publish($this->art->poster('old'), 'custom-jellyfin', 'image/png', $lease, time()));
        self::assertSame('custom-jellyfin', $resolver->image('old', false)['body']);
        self::assertNull($resolver->image('new', false, true));
    }

    public function testReturningParentPrimaryCanBeUsedAfterTheEpisodeWasDeleted(): void
    {
        $this->item('episode', 'Episode', 'parent', 'observed');
        $this->art->enqueue('parent', 'Series', null, time());
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        $this->art->notePosterMetadata('parent', ['Id' => 'parent', 'Type' => 'Series', 'ImageTags' => ['Primary' => 'custom']], $lease, time());
        $resolver = new ArtworkResolver(Container::db(), $this->art);
        self::assertSame('parent', $resolver->target('episode', true));
        self::assertNull($resolver->image('episode', true));
        $this->item('unconfirmed', 'Episode', 'parent', 'provisional');
        self::assertNull($resolver->target('unconfirmed', true));
    }

    public function testObservationAcceptsEquivalentGuidsAndRejectsUnequalOpaqueIds(): void
    {
        $id = '12345678-1234-1234-1234-1234567890ab';
        $this->item($id);
        $this->art->externalObserve($id, 0);
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertTrue($this->art->acceptExternalObservation(
            $id,
            $this->art->externalFingerprint([$id]),
            $lease,
            time(),
            0,
            null,
            false,
            $id,
            ['Id' => str_replace('-', '', $id), 'Type' => 'Movie', 'ImageTags' => []]
        ));
        $this->item('opaque');
        $this->art->externalObserve('opaque', 0);
        self::assertFalse($this->art->acceptExternalObservation(
            'opaque',
            $this->art->externalFingerprint(['opaque']),
            $lease,
            time(),
            0,
            null,
            false,
            'opaque',
            ['Id' => 'other-opaque', 'Type' => 'Movie', 'ImageTags' => []]
        ));
        self::assertSame($id, $this->art->item($id)['item_id']);
    }

    public function testConflictingParentPrimaryStillPreventsManualExternalDisplay(): void
    {
        $this->item('child', 'Episode', 'parent', 'observed');
        $this->art->enqueue('parent', 'Series', null, time());
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        $this->art->notePosterMetadata('parent', ['Id' => 'parent', 'Type' => 'Series', 'ProviderIds' => ['Tmdb' => '111'], 'ImageTags' => []], $lease, time());
        $this->art->externalObserve('child', 0, '111');
        $candidate = ['id' => '111', 'kind' => 'Series', 'path' => 'series.jpg', 'title' => 'Synthetic series'];
        $this->art->externalSelect(['child'], $this->art->externalFingerprint(['child']), $candidate);
        self::assertTrue($this->art->publishExternal($this->art->externalBinding('child'), $this->image(), $lease, time()));
        $this->art->notePosterMetadata('parent', ['Id' => 'parent', 'Type' => 'Series', 'ProviderIds' => ['Tmdb' => '222'], 'ImageTags' => ['Primary' => 'returned']], $lease, time());
        $resolver = new ArtworkResolver(Container::db(), $this->art);
        self::assertNull($resolver->target('child', true));
        self::assertNull($resolver->image('child', true));
    }

    public function testSharedRenewalNeverReactivatesExpiredManualReferencesAtFullQuota(): void
    {
        foreach (['poster.jpg', 'other.jpg'] as $index => $path) {
            $first = 'fresh-' . $index;
            $second = 'expired-' . $index;
            foreach ([$first, $second] as $id) {
                $this->item($id);
                $this->art->externalObserve($id, 0);
                $candidate = $this->candidate();
                if ($id === $second) {
                    $candidate['path'] = $path;
                }
                $this->art->externalSelect([$id], $this->art->externalFingerprint([$id]), $candidate);
                $lease = $this->art->claim(time());
                self::assertNotNull($lease);
                self::assertTrue($this->art->publishExternal($this->art->externalBinding($id), $this->image(), $lease, time()));
                $this->art->release($lease);
            }
            $db = Container::db()->getDibi();
            $expiredKey = $this->art->externalBinding($second)['asset_key'];
            $db->update('artwork_external_bindings', ['expires_at' => time() - 1])->where('source_key = %s AND item_id = %s', $this->art->scope, $second)->execute();
            if ($path !== 'poster.jpg') {
                $db->update('artwork_external_assets', ['expires_at' => time() - 1])->where('source_key = %s AND asset_key = %s', $this->art->scope, $expiredKey)->execute();
            }
            $job = $this->art->externalBinding($first);
            $candidate = $this->candidate();
            $candidate['path'] = $path;
            $lease = $this->art->claim(time());
            self::assertNotNull($lease);
            self::assertTrue($this->art->renewExternal($job, $candidate, $this->image(), $this->art->externalAssetFingerprint($job['asset_key']), $lease, time(), strlen($this->image()['body'])));
            $this->art->release($lease);
            self::assertNull($this->art->externalImage($second));
            self::assertSame('manual', $this->art->externalBinding($second)['origin']);
            self::assertSame($this->image()['body'], $this->art->externalImage($first)['body']);
            $assetKey = $this->art->externalBinding($first)['asset_key'];
            $this->art->clearExternalAsset($assetKey, $this->art->externalAssetFingerprint($assetKey));
        }
    }

    public function testVerifiedAutomaticRetryHasOperationalStatusWithoutMatchNotice(): void
    {
        $this->item('retry');
        $this->art->externalObserve('retry', 0, '123');
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        $this->art->retryExternal($this->art->externalBinding('retry'), 'throttled', $lease, time(), 7200);
        $status = $this->manager()->status();
        self::assertSame(0, $status['noticeCount']);
        self::assertSame([], $status['review']);
        self::assertSame('throttled', $status['jobs'][0]['error']);
        self::assertSame('waiting', $status['jobs'][0]['state']);
    }

    public function testDetachedExpiredProviderMetadataIsNeitherDisplayedNorRetained(): void
    {
        $this->item('detached');
        $this->art->externalObserve('detached', 0, '123');
        $this->art->externalSelect(['detached'], $this->art->externalFingerprint(['detached']), $this->candidate(), true);
        $this->art->externalObserve('detached', 0, null, true);
        $db = Container::db()->getDibi();
        self::assertSame('', $db->select('selected_title')->from('artwork_external_bindings')->where('source_key = %s', $this->art->scope)->fetchSingle());
        $db->update('artwork_external_bindings', ['selected_title' => 'Expired provider title', 'selected_year' => 1999, 'expires_at' => time() - 1, 'suppressed' => 1, 'state' => 'suppressed'])
            ->where('source_key = %s', $this->art->scope)->execute();
        self::assertSame('', $this->manager()->status()['jobs'][0]['selectedTitle']);
        $this->art->purgeExpiredExternal(time());
        self::assertSame('', $db->select('selected_title')->from('artwork_external_bindings')->where('source_key = %s', $this->art->scope)->fetchSingle());
    }

    public function testIndependentMovieQuarantineFencesPreviewsNetworkAndInflightWrites(): void
    {
        foreach (['saved', 'pending'] as $id) {
            $this->item($id);
            $this->art->externalObserve($id, 0, '123');
            $this->art->externalSelect([$id], $this->art->externalFingerprint([$id]), $this->candidate(), true);
        }
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertTrue($this->art->publishExternal($this->art->externalBinding('saved'), $this->image(), $lease, time()));
        $savedJob = $this->art->externalBinding('saved');
        $pendingJob = $this->art->externalBinding('pending');
        $key = $savedJob['asset_key'];
        $assetFingerprint = $this->art->externalAssetFingerprint($key);
        $this->art->release($lease);
        $movies = new MovieIdentityRepository(Container::db(), $this->art->scope);
        $requests = 0;
        $client = new JellyfinClient('https://synthetic.invalid', 'synthetic', true, static function () use (&$requests): array {
            ++$requests;
            return ['Id' => 'synthetic-integration'];
        });
        $manager = new ExternalPosterManager(Container::db(), $this->art, $client);
        foreach ([['serverId' => 'synthetic-integration', 'serverMismatch' => true], ['serverId' => 'other-server', 'serverMismatch' => false]] as $state) {
            $movieLease = $movies->acquireLease(time());
            self::assertNotNull($movieLease);
            $movies->saveState($state);
            $movies->releaseLease($movieLease);
            self::assertTrue($manager->status()['source']['blocked']);
            self::assertSame([], $manager->status()['assets']);
            self::assertSame(0, $manager->noticeCount());
            self::assertNull($this->art->externalAsset($key));
            self::assertNull($this->art->externalImage('saved'));
            try {
                $manager->preview(str_repeat('a', 64));
                self::fail('A quarantined preview must be rejected before reading its token.');
            } catch (RuntimeException) {
                self::assertSame(0, $requests);
            }
            (new ExternalPosterCollector(Container::db(), $client, $this->art))->runBatch();
            self::assertSame(0, $requests);
            $lease = $this->art->claim(time());
            self::assertNotNull($lease);
            self::assertFalse($this->art->publishExternal($pendingJob, $this->image(), $lease, time()));
            self::assertFalse($this->art->renewExternal($savedJob, $this->candidate(), $this->image(), $assetFingerprint, $lease, time()));
            $this->art->release($lease);
        }
    }

    public function testPlaceholderEscapesMarkupAndRemovesXmlControlCharacters(): void
    {
        $this->item('placeholder');
        $db = Container::db()->getDibi();
        $db->update('artwork_items', ['title' => '<script>&"' . chr(1)])->where('source_key = %s', $this->art->scope)->execute();
        $svg = (new ArtworkResolver(Container::db(), $this->art))->placeholder('placeholder');
        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringNotContainsString(chr(1), $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        self::assertStringContainsString('Poster unavailable', $svg);
    }

    private function manager(): ExternalPosterManager
    {
        return new ExternalPosterManager(Container::db(), $this->art, new JellyfinClient(
            'https://synthetic.invalid',
            'synthetic',
            true,
            static fn () => throw new RuntimeException('Local status must not request network data.')
        ));
    }

    private function item(string $id, string $type = 'Movie', ?string $parent = null, string $evidence = 'legacy'): void
    {
        $this->art->remember(['item_id' => $id, 'item_type' => $type, 'title' => 'Synthetic title', 'series_name' => $type === 'Episode' ? 'Synthetic series' : '',
            'library' => 'Synthetic', 'poster_id' => $parent, 'evidence' => $evidence, 'observed_at' => 1, 'status' => 'review', 'next_attempt' => 0]);
    }

    private function candidate(): array
    {
        return ['id' => '123', 'kind' => 'Movie', 'path' => 'poster.jpg', 'title' => 'Synthetic provider title', 'year' => 2000];
    }

    private function image(): array
    {
        return ['body' => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 'contentType' => 'image/gif'];
    }
}
