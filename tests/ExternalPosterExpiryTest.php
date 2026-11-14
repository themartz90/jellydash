<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ExternalPosterExpiryTest extends TestCase
{
    private ArtworkRepository $art;
    private string $url;

    protected function setUp(): void
    {
        $this->url = 'https://' . bin2hex(random_bytes(8)) . '.invalid';
        $this->art = new ArtworkRepository(Container::db(), ThemePlaybackExclusions::serverKey($this->url));
        self::assertTrue($this->art->anchor('expiry-test-server'));
    }

    protected function tearDown(): void
    {
        foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings',
            'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            Container::db()->getDibi()->delete($table)->where('source_key = %s', $this->art->scope)->execute();
        }
    }

    public function testFreshSelectionAndSavedAssetExpireAfterOneHundredEightyDaysAndSharedAttachKeepsDeadline(): void
    {
        $this->addItem('movie-a', 'Expiry movie');
        $this->art->externalObserve('movie-a', 0);
        $start = time();
        $this->selectAndPublish('movie-a', $this->image('first image'));
        $afterFirst = time();
        $first = $this->art->externalBinding('movie-a');
        $key = (string) $first['asset_key'];
        $assetDeadline = (int) Container::db()->getDibi()->select('expires_at')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->fetchSingle();

        self::assertGreaterThanOrEqual($start + ArtworkRepository::EXTERNAL_LIFETIME, (int) $first['expires_at']);
        self::assertLessThanOrEqual($afterFirst + ArtworkRepository::EXTERNAL_LIFETIME, (int) $first['expires_at']);
        self::assertSame((int) $first['expires_at'], $assetDeadline);

        $this->addItem('movie-b', 'Expiry movie');
        $this->art->externalObserve('movie-b', 0);
        $this->selectAndPublish('movie-b', $this->image('ignored duplicate body'));

        self::assertSame($assetDeadline, (int) $this->art->externalBinding('movie-b')['expires_at']);
        self::assertSame($assetDeadline, (int) Container::db()->getDibi()->select('expires_at')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->fetchSingle());
        self::assertGreaterThan(0, (int) $this->art->source()['used_bytes']);
    }

    public function testUnknownExpiryIsTreatedAsExpiredForBindingImageAndReusableAssetReads(): void
    {
        $this->addItem('movie-unknown', 'Unknown expiry movie');
        $this->art->externalObserve('movie-unknown', 0);
        $this->selectAndPublish('movie-unknown', $this->image('unknown expiry image'));
        $binding = $this->art->externalBinding('movie-unknown');
        $key = (string) $binding['asset_key'];
        Container::db()->getDibi()->update('artwork_external_bindings', ['expires_at' => 0])
            ->where('source_key = %s AND item_id = %s', $this->art->scope, 'movie-unknown')->execute();
        Container::db()->getDibi()->update('artwork_external_assets', ['expires_at' => 0])
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->execute();

        $read = $this->art->externalBinding('movie-unknown');
        self::assertNull($this->art->externalImage('movie-unknown'));
        self::assertNull($this->art->externalAsset($key));
        self::assertNull($this->art->reusableExternalAsset('Movie', '101', 'poster.jpg'));
        self::assertNull($read['tmdb_id']);
        self::assertNull($read['asset_key']);
        self::assertSame('', $read['selected_title']);
    }

    public function testPurgeRemovesExpiredProviderDataButKeepsHistoryJellyfinIdentityAndManualSuppression(): void
    {
        $this->addItem('movie-expired', 'History stays here');
        $this->art->externalObserve('movie-expired', 0, '2048');
        $this->selectAndPublish('movie-expired', $this->image('expiring saved body'));
        $binding = $this->art->externalBinding('movie-expired');
        $key = (string) $binding['asset_key'];
        Container::db()->getDibi()->update('artwork_external_bindings', ['expires_at' => time() - 1,
            'suppressed' => 1, 'suppressed_by' => 'selected'])
            ->where('source_key = %s AND item_id = %s', $this->art->scope, 'movie-expired')->execute();
        Container::db()->getDibi()->update('artwork_external_assets', ['expires_at' => time() - 1])
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->execute();

        $purged = $this->art->purgeExpiredExternal(time(), 50);

        self::assertGreaterThanOrEqual(1, $purged);
        self::assertSame('History stays here', $this->art->item('movie-expired')['title']);
        self::assertSame('2048', $this->art->externalBinding('movie-expired')['verified_id']);
        self::assertSame('manual', $this->art->externalBinding('movie-expired')['origin']);
        self::assertSame(1, (int) $this->art->externalBinding('movie-expired')['suppressed']);
        self::assertSame('suppressed', $this->art->externalBinding('movie-expired')['state']);
        self::assertNull($this->art->externalBinding('movie-expired')['tmdb_id']);
        self::assertNull($this->art->externalBinding('movie-expired')['poster_path']);
        self::assertNull($this->art->externalBinding('movie-expired')['asset_key']);
        self::assertSame('', $this->art->externalBinding('movie-expired')['selected_title']);
        self::assertNull(Container::db()->getDibi()->select('asset_key')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->fetchSingle());
        self::assertSame(0, (int) $this->art->source()['used_bytes']);
    }

    public function testProactiveRenewalReplacesAgedSharedAssetAndUpdatesAllBindings(): void
    {
        foreach (['movie-a', 'movie-b'] as $id) {
            $this->addItem($id, 'Shared expiry movie');
            $this->art->externalObserve($id, 0);
        }
        $this->selectAndPublish('movie-a', $this->image('old body'));
        $oldKey = (string) $this->art->externalBinding('movie-a')['asset_key'];
        $this->art->externalSelect(['movie-b'], $this->art->externalFingerprint(['movie-b']), $this->candidate());
        $this->publish('movie-b', $this->image('duplicate body'));
        $oldDeadline = time() + 30 * 86400;
        Container::db()->getDibi()->update('artwork_external_bindings', ['expires_at' => $oldDeadline])
            ->where('source_key = %s AND item_id IN %in', $this->art->scope, ['movie-a', 'movie-b'])->execute();
        Container::db()->getDibi()->update('artwork_external_assets', ['expires_at' => $oldDeadline])
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $oldKey)->execute();
        $oldJob = $this->art->externalBinding('movie-a');

        $now = time();
        $lease = $this->art->claim($now);
        self::assertNotNull($lease);
        $freshImage = $this->image('renewed body');
        $freshCandidate = $this->candidate('101', 'renewed.jpg', 'Fresh title');
        self::assertTrue($this->art->renewExternal(
            $oldJob,
            $freshCandidate,
            $freshImage,
            $this->art->externalAssetFingerprint($oldKey),
            $lease,
            $now
        ));
        $this->art->release($lease);

        $freshKey = (string) $this->art->externalBinding('movie-a')['asset_key'];
        self::assertNotSame($oldKey, $freshKey);
        self::assertSame($freshKey, $this->art->externalBinding('movie-b')['asset_key']);
        self::assertSame($freshImage['body'], $this->art->externalImage('movie-a')['body']);
        self::assertSame($freshImage['body'], $this->art->externalImage('movie-b')['body']);
        self::assertSame('Fresh title', $this->art->externalAsset($freshKey)['title']);
        self::assertSame(strlen($freshImage['body']), (int) $this->art->source()['used_bytes']);
        self::assertGreaterThanOrEqual($now + ArtworkRepository::EXTERNAL_LIFETIME, (int) $this->art->externalBinding('movie-a')['expires_at']);
    }

    public function testRejectedAndExpiredRenewalsDoNotExtendOrReplaceTheAsset(): void
    {
        $this->addItem('movie-stale', 'Stale expiry movie');
        $this->art->externalObserve('movie-stale', 0);
        $this->selectAndPublish('movie-stale', $this->image('old body'));
        $key = (string) $this->art->externalBinding('movie-stale')['asset_key'];
        $job = $this->art->externalBinding('movie-stale');
        $deadline = (int) $job['expires_at'];
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);

        self::assertFalse($this->art->renewExternal(
            $job,
            $this->candidate('999', 'wrong.jpg'),
            $this->image('bad renewal'),
            $this->art->externalAssetFingerprint($key),
            $lease,
            time()
        ));
        $this->art->release($lease);
        self::assertSame($deadline, (int) Container::db()->getDibi()->select('expires_at')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->fetchSingle());

        $expiredAt = time() - 1;
        Container::db()->getDibi()->update('artwork_external_bindings', ['expires_at' => $expiredAt])
            ->where('source_key = %s AND item_id = %s', $this->art->scope, 'movie-stale')->execute();
        Container::db()->getDibi()->update('artwork_external_assets', ['expires_at' => $expiredAt])
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->execute();
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertFalse($this->art->renewExternal(
            $job,
            $this->candidate(),
            $this->image('expired renewal'),
            $this->art->externalAssetFingerprint($key),
            $lease,
            time()
        ));
        $this->art->release($lease);
        self::assertSame($expiredAt, (int) Container::db()->getDibi()->select('expires_at')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $this->art->scope, $key)->fetchSingle());
    }

    private function addItem(string $id, string $title): void
    {
        $this->art->remember(['item_id' => $id, 'item_type' => 'Movie', 'title' => $title,
            'series_name' => '', 'library' => 'Movies', 'poster_id' => null, 'observed_at' => 1,
            'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
    }

    private function selectAndPublish(string $id, array $image): void
    {
        $this->art->externalSelect([$id], $this->art->externalFingerprint([$id]), $this->candidate());
        $this->publish($id, $image);
    }

    private function publish(string $id, array $image): void
    {
        $job = $this->art->externalBinding($id);
        $lease = $this->art->claim(time());
        self::assertNotNull($lease);
        self::assertTrue($this->art->publishExternal($job, $image, $lease, time()));
        $this->art->release($lease);
    }

    private function candidate(string $id = '101', string $path = 'poster.jpg', string $title = 'Selected title'): array
    {
        return ['id' => $id, 'kind' => 'Movie', 'title' => $title, 'year' => 2001, 'path' => $path];
    }

    private function image(string $body): array
    {
        $image = imagecreatetruecolor(2, 2);
        $hash = hash('sha256', $body);
        $color = imagecolorallocate($image, hexdec(substr($hash, 0, 2)), hexdec(substr($hash, 2, 2)), hexdec(substr($hash, 4, 2)));
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        self::assertIsString($png);
        return ['body' => $png, 'contentType' => 'image/png'];
    }
}
