<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\RecentlyAddedService;
use PHPUnit\Framework\TestCase;

final class RecentlyAddedServiceTest extends TestCase
{
    private JellyfinClient $client;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->client = new JellyfinClient('http://jellyfin.test', 'token');
        $this->now = new DateTimeImmutable('2026-08-18 12:00:00', new DateTimeZone('UTC'));
    }

    public function testOnlyVisualMediaFromTheLastFourteenDaysIsShown(): void
    {
        $cards = $this->cards([
            $this->movie('new-movie', 'New Movie', '2026-08-18T08:00:00.0000000Z', '/media/movies/New Movie.mkv', 2026),
            $this->movie('boundary-movie', 'Boundary Movie', '2026-08-04T12:00:00.0000000Z', '/media/movies/Boundary.mkv', 2024),
            $this->movie('old-movie', 'Old Movie', '2026-08-04T11:59:59.0000000Z', '/media/movies/Old.mkv', 2020),
            [
                'Id' => 'album-1',
                'Name' => 'An Album',
                'Type' => 'MusicAlbum',
                'DateCreated' => '2026-08-18T07:00:00.0000000Z',
                'Path' => '/media/music/Album',
            ],
        ]);

        $this->assertSame(['New Movie', 'Boundary Movie'], array_column($cards, 'title'));
        $this->assertSame('Today', $cards[0]['dateLabel']);
        $this->assertSame('Movies', $cards[0]['library']);
        $this->assertSame('Movie · 2026', $cards[0]['meta']);
        $this->assertSame(
            'http://jellyfin.test/web/#/details?id=new-movie&serverId=server-1',
            $cards[0]['jellyfinUrl'],
        );
    }

    public function testEpisodesFromTheSameSeasonBecomeOneSeriesCard(): void
    {
        $cards = $this->cards([
            $this->episode('episode-2', 'Second', '2026-08-18T08:00:00Z', 2),
            $this->episode('episode-1', 'First', '2026-08-17T08:00:00Z', 1),
        ]);

        $this->assertCount(1, $cards);
        $this->assertSame('Example Show', $cards[0]['title']);
        $this->assertSame('Season 2 · 2 episodes', $cards[0]['meta']);
        $this->assertSame('/api/image.php?item=series-1&type=Primary&maxWidth=320', $cards[0]['poster']);
        $this->assertSame(
            'http://jellyfin.test/web/#/details?id=series-1&serverId=server-1',
            $cards[0]['jellyfinUrl'],
        );
    }

    public function testNewSeriesAbsorbsItsNewEpisodeGroups(): void
    {
        $cards = $this->cards([
            [
                'Id' => 'series-1',
                'Name' => 'Example Show',
                'Type' => 'Series',
                'DateCreated' => '2026-08-18T09:00:00Z',
                'Path' => '/media/tv/Example Show',
                'ProductionYear' => 2026,
            ],
            $this->episode('episode-2', 'Second', '2026-08-18T08:00:00Z', 2),
            $this->episode('episode-1', 'First', '2026-08-17T08:00:00Z', 1),
        ]);

        $this->assertCount(1, $cards);
        $this->assertSame('Example Show', $cards[0]['title']);
        $this->assertSame('New series · 2 episodes', $cards[0]['meta']);
    }

    public function testEpisodeWithoutSeriesIdUsesOnlyAnUnambiguousName(): void
    {
        $first = ['Id' => 'series-1', 'Name' => 'Example Show', 'Type' => 'Series', 'DateCreated' => '2026-08-18T09:00:00Z'];
        $second = array_replace($first, ['Id' => 'series-2']);
        $episode = $this->episode('episode-unassigned', 'Local episode', '2026-08-18T08:00:00Z', 1);
        $exact = $episode;
        unset($episode['SeriesId']);
        foreach ([[$first, $second], [$second, $first]] as $series) {
            $cards = $this->cards([...$series, $episode]);
            self::assertCount(3, $cards);
            self::assertSame(2, count(array_keys(array_column($cards, 'meta'), 'New series', true)));
            self::assertStringContainsString('Local episode', $cards[2]['meta']);
            $cards = $this->cards([...$series, $exact]);
            self::assertCount(2, $cards);
            foreach ($cards as $card) {
                self::assertSame(str_contains($card['jellyfinUrl'], 'id=series-1') ? 'New series · 1 episode' : 'New series', $card['meta']);
            }
        }
        self::assertSame(['New series · 1 episode'], array_column($this->cards([$second, $episode]), 'meta'));
    }

    public function testSeveralLibrariesShareTheShelfBeforeOneCanDominateIt(): void
    {
        $items = [];
        for ($i = 0; $i < 10; ++$i) {
            $items[] = $this->movie(
                'movie-' . $i,
                'Movie ' . $i,
                sprintf('2026-08-%02dT10:00:00Z', 18 - $i),
                '/media/movies/Movie ' . $i . '.mkv',
                2026,
            );
        }
        for ($i = 0; $i < 10; ++$i) {
            $items[] = $this->movie(
                'anime-' . $i,
                'Anime ' . $i,
                sprintf('2026-08-%02dT09:00:00Z', 18 - $i),
                '/media/anime/Anime ' . $i . '.mkv',
                2026,
            );
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['DateCreated'], (string) $a['DateCreated']));
        $cards = $this->cards($items);

        $this->assertCount(12, $cards);
        $counts = array_count_values(array_column($cards, 'library'));
        $this->assertSame(6, $counts['Movies'] ?? 0);
        $this->assertSame(6, $counts['Anime'] ?? 0);
    }

    public function testExpiredCardsAreRemovedFromAStaleCache(): void
    {
        $service = new RecentlyAddedService($this->client, $this->now);
        $method = new ReflectionMethod($service, 'pruneCachedPayload');

        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($service, [
            'items' => [
                ['title' => 'Fresh', 'addedAt' => '2026-08-18T08:00:00Z'],
                ['title' => 'Expired', 'addedAt' => '2026-08-03T08:00:00Z'],
            ],
            'windowDays' => 30,
            'generated_at' => 1,
            'cached' => false,
            'schemaVersion' => 2,
        ]);

        $this->assertSame(['Fresh'], array_column($payload['items'], 'title'));
        $this->assertSame(14, $payload['windowDays']);
        $this->assertSame(2, $payload['schemaVersion']);
    }

    public function testInvalidJellyfinDetailsTargetsStayUnlinked(): void
    {
        $service = new RecentlyAddedService(new JellyfinClient('javascript:alert(1)', 'token'), $this->now);
        $method = new ReflectionMethod($service, 'detailsUrl');

        $this->assertSame('', $method->invoke($service, 'movie-1', 'server-1'));
        $this->assertSame('', $method->invoke($service, 'movie/1', 'server-1'));
        $this->assertSame('', $method->invoke($service, 'movie-1', null));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $items): array
    {
        $service = new RecentlyAddedService($this->client, $this->now);
        $method = new ReflectionMethod($service, 'cards');

        /** @var array<int, array<string, mixed>> $cards */
        $cards = $method->invoke(
            $service,
            $items,
            [
                'movies' => ['/media/movies'],
                'tv shows' => ['/media/tv'],
                'anime' => ['/media/anime'],
            ],
            ['Movies', 'TV Shows', 'Anime'],
            'server-1',
        );

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    private function movie(string $id, string $name, string $created, string $path, int $year): array
    {
        return [
            'Id' => $id,
            'Name' => $name,
            'Type' => 'Movie',
            'DateCreated' => $created,
            'Path' => $path,
            'ProductionYear' => $year,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function episode(string $id, string $name, string $created, int $episodeNumber): array
    {
        return [
            'Id' => $id,
            'Name' => $name,
            'Type' => 'Episode',
            'DateCreated' => $created,
            'Path' => '/media/tv/Example Show/Season 2/' . $id . '.mkv',
            'SeriesId' => 'series-1',
            'SeriesName' => 'Example Show',
            'SeasonId' => 'season-2',
            'SeasonName' => 'Season 2',
            'ParentIndexNumber' => 2,
            'IndexNumber' => $episodeNumber,
        ];
    }
}
