<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use PHPUnit\Framework\TestCase;

final class ArtworkExternalStorageTest extends TestCase
{
    private ArtworkRepository $art;

    protected function setUp(): void
    {
        $this->art = new ArtworkRepository(Container::db(), hash('sha256', random_bytes(16)));
        $this->art->anchor('synthetic-server');
        $this->art->remember(['item_id' => 'gone', 'item_type' => 'Movie', 'title' => 'Synthetic movie',
            'series_name' => '', 'library' => 'Movies', 'poster_id' => null, 'observed_at' => 0,
            'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
        $this->art->confirmLegacy();
    }

    protected function tearDown(): void
    {
        foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings',
            'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            Container::db()->getDibi()->delete($table)->where('source_key = %s', $this->art->scope)->execute();
        }
    }

    public function testExternalCopiesUseSeparateSlotsAndSharedBudget(): void
    {
        $this->art->externalObserve('gone', 0, '348');
        $binding = $this->art->externalBinding('gone');
        self::assertNotNull($binding);
        $this->art->externalSelect(
            ['gone'],
            $this->art->externalFingerprint(['gone']),
            ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000],
            true
        );
        $job = $this->art->externalBinding('gone');
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        self::assertTrue($this->art->publishExternal($job, ['body' => 'saved', 'contentType' => 'image/png'], $lease, 101, 5));
        self::assertSame('saved', $this->art->externalImage('gone')['body']);
        self::assertNull($this->art->image('gone'));
        self::assertSame(5, (int) $this->art->source()['used_bytes']);
        self::assertNull($this->art->item('gone')['poster_id']);
    }

    public function testDisableAndSuppressionFenceOldDownloads(): void
    {
        $this->art->externalObserve('gone', 0, '348');
        $this->art->externalSelect(
            ['gone'],
            $this->art->externalFingerprint(['gone']),
            ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000],
            true
        );
        $job = $this->art->externalBinding('gone');
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->configureExternal(false);
        self::assertFalse($this->art->publishExternal($job, ['body' => 'late', 'contentType' => 'image/png'], $lease, 101));
        $this->art->configureExternal(true);
        self::assertFalse($this->art->publishExternal($job, ['body' => 'late', 'contentType' => 'image/png'], $lease, 101));
        $this->art->externalSuppress(['gone'], $this->art->externalFingerprint(['gone']), true);
        self::assertNull($this->art->externalImage('gone'));
        self::assertTrue((bool) $this->art->externalBinding('gone')['suppressed']);
    }

    public function testReturningJellyfinImageStopsExternalDisplayBeforeCapture(): void
    {
        $this->art->externalObserve('gone', 0, '348');
        $this->art->externalSelect(
            ['gone'],
            $this->art->externalFingerprint(['gone']),
            ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000],
            true
        );
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->publishExternal($this->art->externalBinding('gone'), ['body' => 'saved', 'contentType' => 'image/png'], $lease, 101);
        $this->art->externalObserve('gone', 1, '348');
        self::assertNull($this->art->externalImage('gone'));
        self::assertSame(5, (int) $this->art->source()['used_bytes']);
    }

    public function testSharedAssetsCountOnceAndFullBudgetPreservesTheFirstCopy(): void
    {
        $item = $this->art->item('gone');
        $item['item_id'] = 'second';
        $this->art->remember($item);
        foreach (['gone', 'second'] as $id) {
            $this->art->externalObserve($id, 0, '348');
            $this->art->externalSelect(
                [$id],
                $this->art->externalFingerprint([$id]),
                ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000],
                true
            );
        }
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        foreach (['gone', 'second'] as $id) {
            self::assertTrue($this->art->publishExternal($this->art->externalBinding($id), ['body' => 'saved', 'contentType' => 'image/png'], $lease, 101, 5));
        }
        self::assertSame(5, (int) $this->art->source()['used_bytes']);
        $this->art->externalSelect(
            ['second'],
            $this->art->externalFingerprint(['second']),
            ['id' => '348', 'kind' => 'Movie', 'path' => 'b.jpg', 'title' => 'Synthetic movie', 'year' => 2000]
        );
        self::assertFalse($this->art->publishExternal($this->art->externalBinding('second'), ['body' => 'other', 'contentType' => 'image/png'], $lease, 102, 5));
        self::assertSame('saved', $this->art->externalImage('gone')['body']);
        self::assertSame('capacity', $this->art->externalBinding('second')['last_error']);
    }

    public function testConflictInvalidatesAutomaticSelectionButAnExplicitChoiceCanResolveIt(): void
    {
        $candidate = ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000];
        $this->art->externalObserve('gone', 0, '348');
        $this->art->externalSelect(['gone'], $this->art->externalFingerprint(['gone']), $candidate, true);
        $staleJob = $this->art->externalBinding('gone');
        $this->art->externalObserve('gone', 0, null, true);
        self::assertNull($this->art->externalBinding('gone')['verified_id']);
        self::assertNull($this->art->externalBinding('gone')['tmdb_id']);
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        self::assertFalse($this->art->publishExternal($staleJob, ['body' => 'bad', 'contentType' => 'image/png'], $lease, 101));
        $this->art->externalSelect(['gone'], $this->art->externalFingerprint(['gone']), $candidate);
        $this->art->externalObserve('gone', 0, null, true);
        self::assertSame('348', $this->art->externalBinding('gone')['tmdb_id']);
        self::assertTrue($this->art->publishExternal($this->art->externalBinding('gone'), ['body' => 'chosen', 'contentType' => 'image/png'], $lease, 101));
        self::assertSame('chosen', $this->art->externalImage('gone')['body']);
    }

    public function testClearingAndResumingJellyfinDoesNotUndoExplicitExternalSuppression(): void
    {
        $item = $this->art->item('gone');
        $item['poster_id'] = 'gone';
        $this->art->remember($item);
        $this->art->enqueue('gone', 'Movie', null, 1);
        $this->art->externalObserve('gone', 0, '348');
        $this->art->externalSuppress(['gone'], $this->art->externalFingerprint(['gone']), true);
        $this->art->clear('gone');
        $this->art->resume('gone');
        self::assertSame(1, (int) $this->art->externalBinding('gone')['suppressed']);
        self::assertSame('selected', $this->art->externalBinding('gone')['suppressed_by']);
    }

    public function testConfirmedPrimaryReappearanceWinsEvenWhenNoBytesWereSaved(): void
    {
        $item = $this->art->item('gone');
        $item['poster_id'] = 'gone';
        $this->art->remember($item);
        $this->art->enqueue('gone', 'Movie', null, 1);
        $this->art->externalObserve('gone', 0, '348');
        $this->art->externalSelect(
            ['gone'],
            $this->art->externalFingerprint(['gone']),
            ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000],
            true
        );
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        self::assertTrue($this->art->publishExternal($this->art->externalBinding('gone'), ['body' => 'saved', 'contentType' => 'image/png'], $lease, 101, 5));
        $this->art->notePosterMetadata('gone', ['Id' => 'gone', 'Type' => 'Movie', 'ProviderIds' => ['Tmdb' => '348'],
            'ImageTags' => ['Primary' => 'returned-custom']], $lease, 102);
        self::assertNull($this->art->externalImage('gone'));
        self::assertNull($this->art->image('gone'));
        self::assertSame(5, (int) $this->art->source()['used_bytes']);
        self::assertSame(1, (int) $this->art->poster('gone')['presence']);
    }

    public function testNormalJellyfinMetadataConflictInvalidatesAnAlreadySavedAutomaticPoster(): void
    {
        $item = $this->art->item('gone');
        $item['poster_id'] = 'gone';
        $this->art->remember($item);
        $this->art->enqueue('gone', 'Movie', null, 1);
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->notePosterMetadata('gone', ['Id' => 'gone', 'Type' => 'Movie', 'ProviderIds' => ['Tmdb' => '348']], $lease, 100, 0);
        $this->art->externalObserve('gone', 0, '348');
        $this->art->externalSelect(
            ['gone'],
            $this->art->externalFingerprint(['gone']),
            ['id' => '348', 'kind' => 'Movie', 'path' => 'a.jpg', 'title' => 'Synthetic movie', 'year' => 2000],
            true
        );
        self::assertTrue($this->art->publishExternal($this->art->externalBinding('gone'), ['body' => 'saved', 'contentType' => 'image/png'], $lease, 101));
        $this->art->notePosterMetadata('gone', ['Id' => 'gone', 'Type' => 'Movie', 'ProviderIds' => ['Tmdb' => '999']], $lease, 102, 0);
        self::assertNull($this->art->externalImage('gone'));
        self::assertNull($this->art->externalBinding('gone')['tmdb_id']);
        self::assertSame(1, (int) $this->art->externalBinding('gone')['identity_conflict']);
        self::assertSame(5, (int) $this->art->source()['used_bytes']);
    }

    public function testDueRefreshHasASlotEvenWithANewBindingBacklog(): void
    {
        $this->art->externalObserve('gone', 0);
        $lease = $this->art->claim(100);
        self::assertNotNull($lease);
        $this->art->retryExternal($this->art->externalBinding('gone'), null, $lease, 100, 10);
        $item = $this->art->item('gone');
        foreach (['a-new', 'b-new', 'c-new'] as $id) {
            $item['item_id'] = $id;
            $this->art->remember($item);
            $this->art->externalObserve($id, 0);
        }
        self::assertSame(['gone', 'a-new'], array_column($this->art->externalDue(120), 'item_id'));
    }
}
