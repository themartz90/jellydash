<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;

final class LibraryOverviewService
{
    /** @var array<string, array<string, string>> */
    private const TARGETS = [
        'TV Shows' => ['display' => 'TV Shows', 'kind' => 'tv', 'accent' => '#f7b955', 'glyph' => 'TV'],
        'Movies' => ['display' => 'Movies', 'kind' => 'movies', 'accent' => '#46e0b0', 'glyph' => 'M'],
        'Stand-Up Comedy' => ['display' => 'Stand-Up', 'kind' => 'standup', 'accent' => '#f0913a', 'glyph' => 'SU'],
        'Anime' => ['display' => 'Anime', 'kind' => 'anime', 'accent' => '#c8a2ff', 'glyph' => 'A'],
        'PPV & Events' => ['display' => 'PPV & Events', 'kind' => 'events', 'accent' => '#ff8fc4', 'glyph' => 'PPV'],
    ];

    /** @var array<string, string> */
    private const BANNERS = [
        'movies' => 'radial-gradient(130% 120% at 30% 12%, #1f6e5f 0%, #123c38 50%, #0a1a1b 100%)',
        'tv' => 'radial-gradient(130% 120% at 70% 10%, #6e4a1e 0%, #3a2812 52%, #160e07 100%)',
        'standup' => 'radial-gradient(130% 120% at 25% 12%, #6e351f 0%, #351a12 52%, #150a07 100%)',
        'anime' => 'radial-gradient(130% 120% at 25% 12%, #5a2a6e 0%, #2e1640 52%, #140a1c 100%)',
        'events' => 'radial-gradient(130% 120% at 50% 10%, #5a2a55 0%, #2e1430 52%, #140a16 100%)',
        'music' => 'radial-gradient(130% 120% at 30% 12%, #3a2a6e 0%, #1e1640 52%, #0c0a1c 100%)',
        'videos' => 'radial-gradient(130% 120% at 70% 10%, #2a4a6e 0%, #16283a 52%, #0a0f1c 100%)',
        'mixed' => 'radial-gradient(130% 120% at 40% 12%, #2a5a55 0%, #16302e 52%, #0a1614 100%)',
    ];

    /** @var array<int, string> accent palette for auto-discovered libraries */
    private const ACCENTS = ['#7c5cff', '#46e0b0', '#3b9eff', '#f7b955', '#ff6b9d', '#f0913a', '#6f7bff', '#c8a2ff', '#ff8fc4'];

    // Jellyfin collection types that aren't shown as library stat cards.
    private const SKIP_COLLECTION_TYPES = ['playlists', 'boxsets', 'books', 'livetv'];

    public function __construct(
        private ?JellyfinClient $client = null,
        private ?PlayHistoryRepository $history = null,
    ) {
    }

    /**
     * @return array{summary: array<int, array<string, string>>, libraries: array<int, array<string, mixed>>, refreshedLabel: string}
     */
    public function data(): array
    {
        try {
            $client = $this->client ?? new JellyfinClient();
            $folders = $this->targetFolders($client->mediaFolders());
        } catch (\Throwable) {
            return [
                'summary' => $this->summary([]),
                'libraries' => [],
                'refreshedLabel' => 'Jellyfin unavailable',
            ];
        }

        $historyRows = $this->historyRows();
        $scanned = [];
        $itemLibrary = [];

        foreach ($folders as $folder) {
            try {
                $meta = is_array($folder['DashboardMeta'] ?? null) ? $folder['DashboardMeta'] : [];
                $id = (string) ($folder['Id'] ?? '');
                $kind = (string) ($meta['kind'] ?? 'mixed');
                $items = $this->mediaItems($client, $id, $kind);
                $actualName = (string) ($folder['DashboardName'] ?? ($meta['display'] ?? ''));
                foreach ($items as $item) {
                    $itemId = $this->normalizedItemId((string) ($item['Id'] ?? ''));
                    if ($itemId !== '') {
                        $itemLibrary[$itemId] = $actualName;
                    }
                }
                $scanned[] = ['folder' => $folder, 'items' => $items];
            } catch (\Throwable) {
                continue;
            }
        }

        $libraries = [];
        foreach ($scanned as $entry) {
            $libraries[] = $this->libraryCard($client, $entry['folder'], $entry['items'], $historyRows, $itemLibrary);
        }

        return [
            'summary' => $this->summary($libraries),
            'libraries' => $libraries,
            'refreshedLabel' => 'Live from Jellyfin',
        ];
    }

    /**
     * Library overview with file caching (TTL via LIBRARIES_CACHE_TTL). Returns
     * the cached payload while fresh, otherwise regenerates it. If a refresh
     * fails but a stale cache exists, the stale copy is served.
     *
     * @return array<string, mixed>
     */
    public function cachedPayload(): array
    {
        $cached = $this->readCache();

        if ($cached !== null && (time() - (int) ($cached['generated_at'] ?? 0)) < $this->ttl()) {
            $cached['cached'] = true;

            return $cached;
        }

        try {
            return $this->refreshCache();
        } catch (\Throwable $e) {
            if ($cached !== null) {
                $cached['cached'] = true;
                $cached['stale'] = true;
                $cached['refreshedLabel'] = 'Showing cached library stats';

                return $cached;
            }

            throw $e;
        }
    }

    /**
     * Force a fresh scan and write it to the cache. Used by the background
     * warmer (bin/console.php libraries:warm) so a visitor never triggers a cold
     * scan inside their request. Throws (leaving any existing cache intact) when
     * Jellyfin is unavailable, so a transient outage never overwrites good data.
     *
     * @return array<string, mixed>
     */
    public function refreshCache(): array
    {
        $data = $this->data();

        if ($data['refreshedLabel'] === 'Jellyfin unavailable') {
            throw new \RuntimeException('Jellyfin unavailable; keeping the existing library cache.');
        }

        $payload = [
            'summary' => $data['summary'],
            'libraries' => $data['libraries'],
            'refreshedLabel' => $data['refreshedLabel'],
            'generated_at' => time(),
            'cached' => false,
        ];

        $this->writeCache($payload);

        return $payload;
    }

    private function ttl(): int
    {
        return max(30, (int) (Config::get('LIBRARIES_CACHE_TTL', '300') ?? '300'));
    }

    private function cacheFile(): string
    {
        return dirname(__DIR__, 2) . '/var/cache/libraries.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();
        if (!is_file($file)) {
            return null;
        }

        try {
            $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        $dir = dirname($this->cacheFile());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->cacheFile(), json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
        // The cache may be written by both the web user (www-data) and the
        // background warmer; keep it writable by either so neither blocks the
        // other from refreshing it.
        @chmod($this->cacheFile(), 0666);
    }

    /**
     * @param array<int, array<string, mixed>> $folders
     * @return array<int, array<string, mixed>>
     */
    private function targetFolders(array $folders): array
    {
        $result = [];
        foreach ($folders as $folder) {
            $name = (string) ($folder['Name'] ?? '');
            if ($name === '') {
                continue;
            }
            $collectionType = strtolower((string) ($folder['CollectionType'] ?? ''));
            if (in_array($collectionType, self::SKIP_COLLECTION_TYPES, true)) {
                continue;
            }

            $result[] = array_merge($folder, [
                'DashboardMeta' => $this->metaFor($name, $collectionType),
                'DashboardName' => $name,
            ]);
        }

        return $result;
    }

    /**
     * Curated metadata for a known library, or derived metadata (kind from the
     * collection type, an accent from the palette, a glyph from the name) for
     * any other library, so new Jellyfin libraries appear automatically.
     *
     * @return array<string, string>
     */
    private function metaFor(string $name, string $collectionType): array
    {
        if (isset(self::TARGETS[$name])) {
            return self::TARGETS[$name];
        }

        return [
            'display' => $name,
            'kind' => $this->deriveKind($collectionType),
            'accent' => self::ACCENTS[abs(crc32($name)) % count(self::ACCENTS)],
            'glyph' => $this->glyphFor($name),
        ];
    }

    private function deriveKind(string $collectionType): string
    {
        return match ($collectionType) {
            'tvshows' => 'tv',
            'movies' => 'movies',
            'music' => 'music',
            'homevideos', 'photos' => 'videos',
            default => 'mixed',
        };
    }

    private function glyphFor(string $name): string
    {
        $letters = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
            if ($part !== '') {
                $letters .= strtoupper(mb_substr($part, 0, 1));
            }
        }

        return mb_substr($letters !== '' ? $letters : strtoupper(mb_substr($name, 0, 1)), 0, 3);
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    private function historyRows(): array
    {
        try {
            return ($this->history ?? new PlayHistoryRepository())->itemPlaySummaries();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $folder
     * @param array<int, array<string, mixed>> $items
     * @param array<int, \Dibi\Row> $historyRows
     * @param array<string, string> $itemLibrary
     * @return array<string, mixed>
     */
    private function libraryCard(JellyfinClient $client, array $folder, array $items, array $historyRows, array $itemLibrary): array
    {
        /** @var array<string, string> $meta */
        $meta = $folder['DashboardMeta'];
        $id = (string) ($folder['Id'] ?? '');
        $name = (string) $meta['display'];
        $kind = (string) $meta['kind'];
        $accent = (string) $meta['accent'];
        $actualName = (string) ($folder['DashboardName'] ?? $name);
        $breakdown = $this->breakdown($client, $id, $kind, $accent);
        $libraryHistory = $this->libraryHistory($historyRows, $name, $actualName, $itemLibrary);
        $totalSeconds = 0;
        $totalBytes = 0;
        $totalFiles = count($items);

        foreach ($items as $item) {
            $totalSeconds += $this->ticksToSeconds((int) ($item['RunTimeTicks'] ?? 0));
            $totalBytes += $this->itemBytes($item);
        }

        return [
            'name' => $name,
            'type' => $this->typeLabel($kind),
            'kind' => $kind,
            'glyph' => (string) $meta['glyph'],
            'accent' => $accent,
            'chipBg' => $this->alpha($accent, .15),
            'chipBorder' => $this->alpha($accent, .3),
            'banner' => $this->banner($id, $kind),
            'totalTime' => $this->longDuration($totalSeconds),
            'totalFiles' => $this->comma($totalFiles),
            'totalFilesRaw' => $totalFiles,
            'sizeBytes' => $totalBytes,
            'size' => $totalBytes > 0 ? $this->bytes($totalBytes) : 'N/A',
            'totalPlays' => $this->comma((int) $libraryHistory['plays']),
            'totalPlaysRaw' => (int) $libraryHistory['plays'],
            'playback' => $this->longDuration((int) $libraryHistory['watch_sec']),
            'lastActivity' => (string) $libraryHistory['last_activity'],
            'lastPlayed' => (string) $libraryHistory['last_played'],
            'lastUser' => (string) $libraryHistory['last_user'],
            'breakdown' => $breakdown,
            'isMovies' => $kind === 'movies' || $kind === 'standup' || $kind === 'events',
            'isTv' => $kind === 'tv',
            'isAnime' => $kind === 'anime',
            'isEvent' => $kind === 'events',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mediaItems(JellyfinClient $client, string $parentId, string $kind): array
    {
        $types = match ($kind) {
            'tv', 'anime' => 'Episode',
            'music' => 'Audio',
            'mixed' => 'Movie,Video,Episode',
            default => 'Movie,Video',
        };

        return $client->items([
            'ParentId' => $parentId,
            'Recursive' => 'true',
            'IncludeItemTypes' => $types,
            'Fields' => 'MediaSources,RunTimeTicks,DateCreated',
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function breakdown(JellyfinClient $client, string $parentId, string $kind, string $accent): array
    {
        if (in_array($kind, ['tv', 'anime'], true)) {
            return [
                ['value' => $this->comma($this->countItems($client, $parentId, 'Series')), 'label' => 'Series', 'color' => $accent],
                ['value' => $this->comma($this->countItems($client, $parentId, 'Season')), 'label' => 'Seasons', 'color' => '#f0c46b'],
                ['value' => $this->comma($this->countItems($client, $parentId, 'Episode')), 'label' => 'Episodes', 'color' => '#3b9eff'],
            ];
        }

        if ($kind === 'music') {
            return [
                ['value' => $this->comma($this->countItems($client, $parentId, 'MusicArtist')), 'label' => 'Artists', 'color' => $accent],
                ['value' => $this->comma($this->countItems($client, $parentId, 'MusicAlbum')), 'label' => 'Albums', 'color' => '#f0c46b'],
                ['value' => $this->comma($this->countItems($client, $parentId, 'Audio')), 'label' => 'Songs', 'color' => '#3b9eff'],
            ];
        }

        if ($kind === 'videos') {
            return [
                ['value' => $this->comma($this->countItems($client, $parentId, 'Video')), 'label' => 'Videos', 'color' => $accent],
            ];
        }

        if ($kind === 'mixed') {
            return [
                ['value' => $this->comma($this->countItems($client, $parentId, 'Movie')), 'label' => 'Movies', 'color' => $accent],
                ['value' => $this->comma($this->countItems($client, $parentId, 'Series')), 'label' => 'Series', 'color' => '#f0c46b'],
                ['value' => $this->comma($this->countItems($client, $parentId, 'Video')), 'label' => 'Videos', 'color' => '#6fb6ff'],
            ];
        }

        $label = match ($kind) {
            'standup' => 'Specials',
            'events' => 'Events',
            default => 'Movies',
        };

        return [
            ['value' => $this->comma($this->countItems($client, $parentId, 'Movie')), 'label' => $label, 'color' => $accent],
            ['value' => $this->comma($this->countItems($client, $parentId, 'Video')), 'label' => 'Videos', 'color' => '#6fb6ff'],
        ];
    }

    private function countItems(JellyfinClient $client, string $parentId, string $types): int
    {
        return $client->itemCount([
            'ParentId' => $parentId,
            'Recursive' => 'true',
            'IncludeItemTypes' => $types,
        ]);
    }

    /**
     * Plays whose item still lives in this library, or whose stored library
     * name matches when the item is gone (deleted / no longer in Jellyfin).
     * $rows are per-item summaries (plays / watch_sec) from itemPlaySummaries().
     *
     * @param array<int, \Dibi\Row|array<string, mixed>> $rows
     * @param array<string, string> $itemLibrary normalized item id => library name
     * @return array{plays: int, watch_sec: int, last_activity: string, last_played: string, last_user: string}
     */
    private function libraryHistory(array $rows, string $displayName, string $actualName, array $itemLibrary = []): array
    {
        $plays = 0;
        $watchSec = 0;
        $last = null;
        $wanted = array_map(
            static fn (string $name): string => mb_strtolower($name),
            array_values(array_filter([$displayName, $actualName], static fn (string $name): bool => $name !== ''))
        );

        foreach ($rows as $row) {
            $resolved = $this->resolvedLibraryName(
                (string) ($row['item_id'] ?? ''),
                (string) ($row['library'] ?? ''),
                $itemLibrary,
            );
            if (!in_array(mb_strtolower($resolved), $wanted, true)) {
                continue;
            }

            $plays += (int) ($row['plays'] ?? 1);
            $watchSec += (int) ($row['watch_sec'] ?? $row['watched_sec'] ?? 0);
            if ($last === null || (string) $row['started_at'] > (string) $last['started_at']) {
                $last = $row;
            }
        }

        if ($last === null) {
            return [
                'plays' => 0,
                'watch_sec' => 0,
                'last_activity' => 'No plays yet',
                'last_played' => 'No playback recorded',
                'last_user' => 'Unknown user',
            ];
        }

        $title = (string) ($last['series_name'] ?: $last['item_name'] ?: 'Unknown title');
        $episode = (string) ($last['series_name'] ? ($last['season_ep'] ? $last['season_ep'] . ' - ' : '') . $last['item_name'] : '');

        return [
            'plays' => $plays,
            'watch_sec' => $watchSec,
            'last_activity' => $this->relativeTime(new \DateTimeImmutable((string) $last['started_at'])),
            'last_played' => $episode !== '' ? $title . ' - ' . $episode : $title,
            'last_user' => (string) ($last['user_name'] ?? 'Unknown user'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $libraries
     * @return array<int, array<string, string>>
     */
    private function summary(array $libraries): array
    {
        $items = 0;
        $plays = 0;

        foreach ($libraries as $library) {
            $items += (int) ($library['totalFilesRaw'] ?? 0);
            $plays += (int) ($library['totalPlaysRaw'] ?? 0);
        }

        return [
            ['label' => 'Libraries', 'color' => '#7c5cff', 'value' => $this->comma(count($libraries)), 'sub' => 'selected media libraries'],
            ['label' => 'Total Items', 'color' => '#3b9eff', 'value' => $this->comma($items), 'sub' => 'movies - episodes - videos'],
            ['label' => 'Storage Used', 'color' => '#f7b955', 'value' => $this->summarySize($libraries), 'sub' => 'reported by Jellyfin'],
            ['label' => 'Total Plays', 'color' => '#34d8a6', 'value' => $this->comma($plays), 'sub' => 'recorded by dashboard'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $libraries
     */
    private function summarySize(array $libraries): string
    {
        $bytes = 0;
        foreach ($libraries as $library) {
            $bytes += (int) ($library['sizeBytes'] ?? 0);
        }

        return $bytes > 0 ? $this->bytes($bytes) : 'N/A';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemBytes(array $item): int
    {
        $sources = $item['MediaSources'] ?? [];
        if (!is_array($sources)) {
            return 0;
        }

        $bytes = 0;
        foreach ($sources as $source) {
            if (is_array($source)) {
                $bytes += (int) ($source['Size'] ?? 0);
            }
        }

        return $bytes;
    }

    private function banner(string $id, string $kind): string
    {
        $fallback = self::BANNERS[$kind] ?? self::BANNERS['movies'];

        if ($id === '') {
            return $fallback;
        }

        return 'url("/api/image.php?item=' . rawurlencode($id) . '&type=Primary&maxWidth=900"), ' . $fallback;
    }

    private function typeLabel(string $kind): string
    {
        return match ($kind) {
            'tv' => 'TV',
            'anime' => 'Anime',
            'standup' => 'Stand-Up',
            'events' => 'Events',
            'music' => 'Music',
            'videos' => 'Videos',
            'mixed' => 'Library',
            default => 'Movies',
        };
    }

    private function longDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $days = intdiv($hours, 24);
        $months = intdiv($days, 30);
        $years = intdiv($months, 12);

        if ($years > 0) {
            return $years . 'y ' . ($months % 12) . 'mo';
        }

        if ($months > 0) {
            return $months . 'mo ' . ($days % 30) . 'd';
        }

        if ($days > 0) {
            return $days . 'd ' . ($hours % 24) . 'h';
        }

        return $hours > 0 ? $hours . 'h ' . intdiv($seconds % 3600, 60) . 'm' : intdiv($seconds, 60) . 'm';
    }

    private function relativeTime(\DateTimeImmutable $date): string
    {
        $diff = max(0, time() - $date->getTimestamp());
        if ($diff < 3600) {
            return max(1, intdiv($diff, 60)) . ' minutes ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' hours ago';
        }

        return intdiv($diff, 86400) . ' days ago';
    }

    private function bytes(int $bytes): string
    {
        if ($bytes >= 1099511627776) {
            return number_format($bytes / 1099511627776, 2) . ' TB';
        }

        return number_format($bytes / 1073741824, 1) . ' GB';
    }

    private function alpha(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
    }

    private function ticksToSeconds(int $ticks): int
    {
        return (int) floor($ticks / 10000000);
    }

    private function comma(int $value): string
    {
        return number_format($value);
    }

    /**
     * Prefer the library that currently owns the item; fall back to the name
     * stored on the play (type-based import labels, deleted items).
     *
     * @param array<string, string> $itemLibrary
     */
    private function resolvedLibraryName(string $itemId, string $storedLibrary, array $itemLibrary): string
    {
        $key = $this->normalizedItemId($itemId);
        if ($key !== '' && isset($itemLibrary[$key])) {
            return $itemLibrary[$key];
        }

        return $storedLibrary;
    }

    private function normalizedItemId(string $id): string
    {
        return strtolower(str_replace('-', '', trim($id)));
    }
}
