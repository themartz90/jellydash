<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkCollector;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ArtworkCollectorTest extends TestCase
{
    private ArtworkRepository $repository;
    private PlayHistoryRepository $history;
    private string $session;
    private string $base;
    /** @var array<string, array<string, mixed>> */
    private array $items = [];
    private string $tag = 'custom-a';
    private int $downloads = 0;

    protected function setUp(): void
    {
        $this->session = 'art-test-' . bin2hex(random_bytes(6));
        $this->base = 'https://' . $this->session . '.invalid';
        $this->history = new PlayHistoryRepository(Container::db(), new MonitoringExclusions([]));
        $this->repository = new ArtworkRepository(Container::db(), ThemePlaybackExclusions::serverKey($this->base));
        $this->items = ['series-one' => ['Id' => 'series-one', 'Type' => 'Series', 'Name' => 'Custom Show', 'ImageTags' => ['Primary' => $this->tag]]];
    }

    protected function tearDown(): void
    {
        $db = Container::db()->getDibi();
        $db->delete('play_history')->where('session_key = %s', $this->session)->execute();
        foreach (['artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            $db->delete($table)->where('source_key = %s', $this->repository->scope)->execute();
        }
    }

    public function testBackgroundCaptureUsesSavedSeriesIdentityAndSurvivesWholeSeriesDeletion(): void
    {
        $this->record(true);
        self::assertSame(1, $this->collector()->runBatch(1001)['saved']);
        self::assertSame('series-one', $this->repository->item('deleted-episode')['poster_id']);
        self::assertSame(1, $this->downloads);
        $this->items = [];
        $this->collector()->runBatch(90000);
        self::assertSame($this->gif(), $this->repository->image('series-one')['body']);
        self::assertSame(1, $this->downloads);
        self::assertSame('missing_item', $this->repository->poster('series-one')['last_error']);
    }

    public function testOldHistoryRequiresSourceConfirmationAndNeverGuessesMissingEpisodeByName(): void
    {
        $this->record(false);
        $this->collector()->runBatch(1001);
        self::assertSame('review', $this->repository->item('deleted-episode')['status']);
        self::assertSame(0, $this->downloads);
        $this->repository->confirmLegacy();
        $this->collector()->runBatch(1002);
        self::assertSame('review', $this->repository->item('deleted-episode')['status']);
        self::assertNull($this->repository->item('deleted-episode')['poster_id']);
        self::assertSame(0, $this->downloads);
    }

    public function testConfirmedExistingLegacyEpisodeCanRecoverItsCurrentCustomPoster(): void
    {
        $this->record(false);
        $this->collector()->runBatch(1001);
        $this->repository->confirmLegacy();
        $this->items['deleted-episode'] = ['Id' => 'deleted-episode', 'Type' => 'Episode',
            'Name' => 'Pilot', 'SeriesName' => 'Custom Show', 'SeriesId' => 'series-one'];
        self::assertSame(1, $this->collector()->runBatch(1002)['saved']);
        self::assertSame('series-one', $this->repository->item('deleted-episode')['poster_id']);
        self::assertSame('deleted-episode', Container::db()->getDibi()->select('item_id')->from('play_history')->where('session_key = %s', $this->session)->fetchSingle());
    }

    public function testUnwatchedPosterRefreshAndDeliberateRemovalKeepLastGoodImage(): void
    {
        $this->record(true);
        $this->collector()->runBatch(1001);
        $this->tag = 'custom-b';
        $this->items['series-one']['ImageTags'] = ['Primary' => $this->tag];
        self::assertSame(1, $this->collector()->runBatch(90000)['saved']);
        self::assertSame('custom-b', $this->repository->image('series-one')['tag']);
        $this->items['series-one']['ImageTags'] = [];
        $this->collector()->runBatch(180000);
        self::assertSame('custom-b', $this->repository->image('series-one')['tag']);
        self::assertSame(2, $this->downloads);
    }

    public function testKnownSeriesLostBeforeCaptureRemainsAvailableForManualRepair(): void
    {
        $this->record(true);
        $this->items = [];
        $this->collector()->runBatch(1001);
        self::assertSame('review', $this->repository->item('deleted-episode')['status']);
        $this->record(true, 1002);
        $this->collector()->runBatch(1003);
        self::assertSame('review', $this->repository->item('deleted-episode')['status']);
        $this->items['series-one'] = ['Id' => 'series-one', 'Type' => 'Series', 'Name' => 'Custom Show', 'ImageTags' => ['Primary' => $this->tag]];
        $this->collector()->runBatch(90000);
        self::assertSame('ready', $this->repository->item('deleted-episode')['status']);
    }

    public function testConflictingProviderIdentityCannotReplaceTheLastGoodCustomPoster(): void
    {
        $this->record(true);
        $this->items['series-one']['ProviderIds'] = ['Tmdb' => '111'];
        self::assertSame(1, $this->collector()->runBatch(1001)['saved']);
        $this->items['series-one']['ProviderIds'] = ['Tmdb' => '222'];
        $this->items['series-one']['ImageTags'] = ['Primary' => 'conflicting-tag'];
        self::assertSame(0, $this->collector()->runBatch(90000)['saved']);
        self::assertSame(1, $this->downloads);
        self::assertSame('custom-a', $this->repository->image('series-one')['tag']);
    }

    private function record(bool $identity, int $epoch = 1000): void
    {
        $this->history->logActiveStreams([['id' => $this->session, 'itemId' => 'deleted-episode',
            'itemType' => 'Episode', 'itemName' => 'Pilot', 'seriesName' => 'Custom Show',
            'userId' => 'synthetic', 'user' => 'Synthetic', 'playMethod' => 'DirectPlay', 'watchedSec' => 10,
            'artworkSource' => $identity ? $this->repository->scope : '', 'artworkServerId' => 'server-one',
            'seriesId' => 'series-one', 'posterTag' => $this->tag]], new DateTimeImmutable('@' . $epoch));
    }

    private function collector(): ArtworkCollector
    {
        $client = new JellyfinClient($this->base, 'synthetic', true, function (string $path, string $method): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'server-one'];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $item = $this->items[$query['Ids'] ?? ''] ?? null;
            return ['Items' => $item === null ? [] : [$item], 'TotalRecordCount' => $item === null ? 0 : 1];
        });
        return new ArtworkCollector(Container::db(), $client, $this->repository, function (string $id): array {
            ++$this->downloads;
            return ['body' => $this->gif(), 'contentType' => 'image/gif'];
        }, new MonitoringExclusions([]));
    }

    private function gif(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    }
}
