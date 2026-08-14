<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\LibraryOverviewService;
use PHPUnit\Framework\TestCase;

final class LibraryOverviewServiceTest extends TestCase
{
    public function testLibraryHistoryUsesItemOwnershipOverStoredTypeLabel(): void
    {
        $service = new LibraryOverviewService();
        $rows = [
            $this->play('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'TV Shows', 300, '2024-01-02 12:00:00', 'Naruto', 'Pilot'),
            $this->play('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'TV Shows', 120, '2024-01-01 12:00:00', 'The Expanse', 'Dulcinea'),
        ];
        $itemLibrary = [
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 'Anime',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => 'TV Shows',
        ];

        $anime = $service->libraryHistory($rows, 'Anime', 'Anime', $itemLibrary);
        $tv = $service->libraryHistory($rows, 'TV Shows', 'TV Shows', $itemLibrary);

        $this->assertSame(1, $anime['plays']);
        $this->assertSame(300, $anime['watch_sec']);
        $this->assertSame('Naruto - S1 E1 - Pilot', $anime['last_played']);
        $this->assertSame(1, $tv['plays']);
        $this->assertSame(120, $tv['watch_sec']);
        $this->assertSame('The Expanse - S1 E1 - Dulcinea', $tv['last_played']);
    }

    public function testLibraryHistoryFallsBackToStoredNameWhenItemIsGone(): void
    {
        $service = new LibraryOverviewService();
        $rows = [
            $this->play('cccccccccccccccccccccccccccccccc', 'Movies', 90, '2024-01-03 12:00:00', null, 'Deleted Film'),
        ];

        $movies = $service->libraryHistory($rows, 'Movies', 'Movies', []);
        $this->assertSame(1, $movies['plays']);
        $this->assertSame('Deleted Film', $movies['last_played']);
    }

    public function testResolvedLibraryNameNormalizesDashedIds(): void
    {
        $service = new LibraryOverviewService();

        $this->assertSame(
            'Anime',
            $service->resolvedLibraryName(
                'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'TV Shows',
                ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 'Anime'],
            )
        );
        $this->assertSame(
            'TV Shows',
            $service->resolvedLibraryName('missing', 'TV Shows', [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function play(string $itemId, string $library, int $watchedSec, string $startedAt, ?string $series, string $itemName): array
    {
        return [
            'item_id' => $itemId,
            'library' => $library,
            'watched_sec' => $watchedSec,
            'started_at' => $startedAt,
            'series_name' => $series,
            'item_name' => $itemName,
            'season_ep' => $series !== null ? 'S1 E1' : null,
            'user_name' => 'Maya',
        ];
    }
}
