<?php

declare(strict_types=1);

use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestBrowser;
use Mk\Framework\Jellyseerr\RequestMapper;
use PHPUnit\Framework\TestCase;

final class SeerrBrowserTest extends TestCase
{
    public function testTabTotalsReuseActivePageAndShareBoundedCountsAcrossViews(): void
    {
        $root = sys_get_temp_dir() . '/jellydash-seerr-browse-' . bin2hex(random_bytes(8));
        $client = new BrowseFixtureSeerrClient('fixture-one');
        try {
            $browser = new RequestBrowser($client, $root);
            self::assertSame(137, $browser->page(5, 'all')['total']);
            self::assertSame([], $client->countCalls);
            self::assertSame(['pending' => 5, 'failed' => 3], $browser->otherTotals('all'));
            self::assertSame([[1, 'pending', 1], [1, 'failed', 1]], $client->countCalls);
            self::assertSame(['all' => 137, 'pending' => 5], (new RequestBrowser($client, $root))->otherTotals('failed'));
            self::assertCount(2, $client->countCalls);
            self::assertSame([[5, 'all']], $client->pages);
        } finally {
            $this->removeCache($root);
        }
    }

    public function testUnknownCountStaysUnknownAndCanRecoverAsKnownZero(): void
    {
        $root = sys_get_temp_dir() . '/jellydash-seerr-browse-' . bin2hex(random_bytes(8));
        $client = new BrowseFixtureSeerrClient('fixture-one');
        $client->counts['failed'] = null;
        $now = 1_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        try {
            $browser = new RequestBrowser($client, $root, $clock);
            $page = $browser->page(1, 'all');
            self::assertSame(['pending' => 5, 'failed' => null], $browser->otherTotals('all'));
            self::assertSame($page, $browser->page(1, 'all'));
            $client->counts['failed'] = 0;
            self::assertSame(['pending' => 5, 'failed' => null], $browser->otherTotals('all'));
            self::assertCount(2, $client->countCalls);
            $now += 26;
            self::assertSame(['pending' => 5, 'failed' => 0], $browser->otherTotals('all'));
            self::assertCount(4, $client->countCalls);

            $other = new BrowseFixtureSeerrClient('fixture-two');
            $other->counts['pending'] = 9;
            self::assertSame(['pending' => 9, 'failed' => 3], (new RequestBrowser($other, $root, $clock))->otherTotals('all'));
        } finally {
            $this->removeCache($root);
        }
    }

    public function testCollidingMediaMetadataSurvivesOneEnrichmentBatchAndWarmReads(): void
    {
        $root = sys_get_temp_dir() . '/jellydash-seerr-browse-' . bin2hex(random_bytes(8));
        $client = new BrowseFixtureSeerrClient('fixture-one');
        // These media IDs share the same disk bucket for the fixture connection.
        $client->mediaIds = [13, 30];
        try {
            $browser = new RequestBrowser($client, $root);
            $page = $browser->page(1, 'all');
            self::assertSame(['Unknown title', 'Unknown title'], array_column($page['requests'], 'title'));
            self::assertSame([], $client->movieCalls);
            $enriched = $browser->enrich(1, 'all');
            self::assertFalse($enriched['remaining']);
            self::assertSame(['Fixture Movie 13', 'Fixture Movie 30'], array_column($enriched['requests'], 'title'));
            self::assertSame([13, 30], $client->movieCalls);
            self::assertSame(['Fixture Movie 13', 'Fixture Movie 30'], array_column($browser->page(1, 'all')['requests'], 'title'));
            self::assertFalse($browser->enrich(1, 'all')['remaining']);
            self::assertSame([13, 30], $client->movieCalls);
        } finally {
            $this->removeCache($root);
        }
    }

    public function testCompletedEditionCanBeDeletedWhileTheOtherRemainsAvailable(): void
    {
        $request = ['id' => 81, 'status' => 5, 'type' => 'tv', 'is4k' => true,
            'media' => ['status' => 5, 'status4k' => 7], 'seasons' => [['seasonNumber' => 2], ['seasonNumber' => 4]]];
        $deleted = RequestMapper::display($request);
        self::assertSame('Deleted', $deleted['statusLabel']);
        self::assertSame(7, $deleted['mediaStatus']);
        self::assertSame([2, 4], $deleted['seasonNumbers']);
        $request['is4k'] = false;
        self::assertSame('Available', RequestMapper::display($request)['statusLabel']);
        $request['media']['status'] = 6;
        self::assertSame('Blocklisted', RequestMapper::display($request)['statusLabel']);
    }

    public function testEnrichmentCanFinishAfterTheListFreshnessWindow(): void
    {
        $root = sys_get_temp_dir() . '/jellydash-seerr-browse-' . bin2hex(random_bytes(8));
        $client = new BrowseFixtureSeerrClient('fixture-one');
        $client->mediaIds = [13, 30];
        $now = 1_000_000;
        $browser = new RequestBrowser($client, $root, static function () use (&$now): int {
            return $now;
        });

        try {
            $browser->page(1, 'all');
            $now += 24;
            self::assertSame('current', $browser->page(1, 'all')['state']);
            self::assertSame([[1, 'all']], $client->pages);
            $now += 2;
            $enriched = $browser->enrich(1, 'all');
            self::assertFalse($enriched['remaining']);
            self::assertSame([13, 30], $client->movieCalls);
            self::assertSame(['Fixture Movie 13', 'Fixture Movie 30'], array_column($enriched['requests'], 'title'));
            self::assertSame([[1, 'all']], $client->pages);

            $now += 120;
            self::assertSame(['requests' => [], 'remaining' => false], $browser->enrich(1, 'all'));
            self::assertSame([13, 30], $client->movieCalls);
        } finally {
            $this->removeCache($root);
        }
    }

    public function testDirectDeepPageUsesOneQueryAndOnlyItsOwnOfflineSnapshot(): void
    {
        $root = sys_get_temp_dir() . '/jellydash-seerr-browse-' . bin2hex(random_bytes(8));
        $client = new BrowseFixtureSeerrClient('fixture-one');
        $now = 1_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        try {
            $browser = new RequestBrowser($client, $root, $clock);
            $first = $browser->page(5, 'all');
            self::assertSame([[5, 'all']], $client->pages);
            self::assertSame(137, $first['total']);
            self::assertSame(41, $first['requests'][0]['requestId']);
            self::assertSame('current', $first['state']);
            self::assertSame($first, $browser->page(5, 'all'));
            self::assertSame([[5, 'all']], $client->pages);

            $now += 60;
            $client->offline = true;
            self::assertSame('cached', $browser->page(5, 'all')['state']);
            self::assertSame('unavailable', $browser->page(4, 'all')['state']);
            self::assertSame('unavailable', $browser->page(5, 'pending')['state']);
            $other = new BrowseFixtureSeerrClient('fixture-two');
            $other->offline = true;
            self::assertSame('unavailable', (new RequestBrowser($other, $root, $clock))->page(5, 'all')['state']);

            $client->offline = false;
            self::assertSame('current', $browser->page(5, 'all')['state']);
            $last = $browser->page(19, 'all');
            self::assertSame(6, $last['page']);
            self::assertCount(17, $last['requests']);
            self::assertSame([19, 'all'], $client->pages[count($client->pages) - 2]);
            self::assertSame([6, 'all'], $client->pages[count($client->pages) - 1]);
        } finally {
            $this->removeCache($root);
        }
    }

    private function removeCache(string $root): void
    {
        foreach (glob($root . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
}

final class BrowseFixtureSeerrClient extends JellyseerrClient
{
    public array $pages = [];
    public array $countCalls = [];
    public array $counts = ['all' => 137, 'pending' => 5, 'failed' => 3];
    public bool $offline = false;
    public array $mediaIds = [];
    public array $movieCalls = [];

    public function __construct(string $key)
    {
        parent::__construct('http://127.0.0.1:1', $key);
    }

    public function requestPageWithMetadata(int $page, string $filter = 'all', int $pageSize = 24): array
    {
        if ($pageSize === 1) {
            $this->countCalls[] = [$page, $filter, $pageSize];
            $total = $this->counts[$filter];
            if ($total === null) {
                throw new RuntimeException('Synthetic count failure.');
            }
            return ['pageInfo' => ['page' => 1, 'pages' => $total, 'pageSize' => 1, 'results' => $total], 'results' => []];
        }
        $this->pages[] = [$page, $filter];
        if ($this->offline) {
            throw new RuntimeException('Synthetic Seerr outage.');
        }
        if ($this->mediaIds !== []) {
            $rows = array_map(static fn (int $id): array => ['id' => $id + 100, 'type' => 'movie', 'status' => 2, 'is4k' => false,
                'media' => ['tmdbId' => $id, 'status' => 3]], $this->mediaIds);
            return ['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => $pageSize, 'results' => count($rows)], 'results' => $rows];
        }
        $rows = [];
        for ($id = 137 - (($page - 1) * $pageSize); $id >= 1 && count($rows) < $pageSize; --$id) {
            $rows[] = ['id' => $id, 'type' => 'movie', 'is4k' => false, 'status' => 1,
                'media' => ['id' => $id + 200, 'tmdbId' => $id + 300, 'status' => 2]];
        }

        return ['pageInfo' => ['page' => $page, 'pages' => 6, 'pageSize' => $pageSize, 'results' => 137], 'results' => $rows];
    }

    public function movie(int $tmdbId): array
    {
        $this->movieCalls[] = $tmdbId;
        return ['title' => 'Fixture Movie ' . $tmdbId, 'posterPath' => '/' . $tmdbId . '.jpg'];
    }
}
