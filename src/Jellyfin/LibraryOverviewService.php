<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Cache\AtomicJsonFile;
use Mk\Framework\Config;
use Mk\Framework\Log;

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
        private ?LibraryOverviewClient $client = null,
        private ?LibraryHistorySource $history = null,
        private ?string $cachePath = null,
        private ?MonitoringExclusions $exclusions = null,
        private ?\Closure $clock = null,
        private int $failureBackoff = 30,
    ) {
        $this->failureBackoff = max(1, $this->failureBackoff);
    }

    /**
     * @return array{summary: array<int, array<string, string>>, libraries: array<int, array<string, mixed>>, refreshedLabel: string, complete: bool, historyAvailable: bool}
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
                'complete' => false,
                'historyAvailable' => false,
            ];
        }

        $history = $this->historySnapshot();
        $historyRows = $history['rows'];
        $historyAvailable = $history['available'];
        $libraries = [];
        $complete = $historyAvailable;

        foreach ($folders as $folder) {
            try {
                $meta = is_array($folder['DashboardMeta'] ?? null) ? $folder['DashboardMeta'] : [];
                $id = (string) ($folder['Id'] ?? '');
                $kind = (string) ($meta['kind'] ?? 'mixed');
                $counts = $this->countSnapshot($client, $id, $kind, (string) ($meta['accent'] ?? '#7c5cff'));
                $libraries[] = $this->libraryCard($folder, $counts, $historyRows, $historyAvailable);
            } catch (\Throwable $e) {
                $complete = false;
                $name = (string) ($folder['DashboardName'] ?? $folder['Name'] ?? 'Unknown library');
                Log::logException(new \RuntimeException(
                    'Could not load Jellyfin library "' . $name . '": ' . $e->getMessage(),
                    previous: $e,
                ));
                $libraries[] = $this->unavailableLibraryCard($folder, $historyRows, $historyAvailable);
            }
        }

        $refreshedLabel = $complete ? 'Live from Jellyfin' : 'Some library details unavailable';
        if (!$historyAvailable) {
            $refreshedLabel = 'Playback history unavailable';
        }

        return [
            'summary' => $this->summary($libraries),
            'libraries' => $libraries,
            'refreshedLabel' => $refreshedLabel,
            'complete' => $complete,
            'historyAvailable' => $historyAvailable,
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

        if ($cached !== null && ($this->now() - (int) ($cached['generated_at'] ?? 0)) < $this->ttl()) {
            $cached['cached'] = true;

            return $cached;
        }

        $backoff = $this->backoffPayload($cached);
        if ($backoff !== null) {
            return $backoff;
        }

        try {
            return $this->cache()->withExclusiveLock(function (): array {
                $cached = $this->readCache();
                if ($cached !== null && ($this->now() - (int) ($cached['generated_at'] ?? 0)) < $this->ttl()) {
                    $cached['cached'] = true;

                    return $cached;
                }

                $backoff = $this->backoffPayload($cached);
                if ($backoff !== null) {
                    return $backoff;
                }

                return $this->rebuildCachedPayload($cached);
            });
        } catch (\Throwable $e) {
            if ($cached !== null) {
                return $this->stalePayload($cached);
            }

            throw $e;
        }
    }

    /** @param array<string, mixed>|null $cached @return array<string, mixed> */
    private function rebuildCachedPayload(?array $cached): array
    {
        $data = $this->data();
        if ($data['refreshedLabel'] === 'Jellyfin unavailable') {
            $this->rememberFailure(null, 'Jellyfin unavailable; no library cache is available.');
            if ($cached === null) {
                throw new \RuntimeException('Jellyfin unavailable; no library cache is available.');
            }

            return $this->retryingPayload($this->stalePayload($cached));
        }

        if (!$data['complete']) {
            $partial = $this->payload($data);
            if ($cached !== null && $this->cacheCoversLibraries($cached, $data)) {
                $this->rememberFailure(null, 'The library refresh was incomplete.');

                return $this->retryingPayload($this->stalePayload($cached, 'Showing cached stats after an incomplete refresh'));
            }

            $this->rememberFailure($partial, 'The library refresh was incomplete.');

            return $partial;
        }

        $payload = $this->payload($data);
        $this->writeCache($payload);
        $this->clearFailure();

        return $payload;
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
        return $this->cache()->withExclusiveLock(fn (): array => $this->refreshCacheUnlocked());
    }

    /** @return array<string, mixed> */
    private function refreshCacheUnlocked(): array
    {
        $data = $this->data();

        if ($data['refreshedLabel'] === 'Jellyfin unavailable' || !$data['complete']) {
            $this->rememberFailure(null, 'One or more library details are unavailable.');
            throw new \RuntimeException('One or more Jellyfin libraries are unavailable; keeping the existing library cache.');
        }

        $payload = $this->payload($data);

        $this->writeCache($payload);
        $this->clearFailure();

        return $payload;
    }

    /**
     * @param array{summary: array<int, array<string, string>>, libraries: array<int, array<string, mixed>>, refreshedLabel: string, complete: bool} $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'summary' => $data['summary'],
            'libraries' => $data['libraries'],
            'refreshedLabel' => $data['refreshedLabel'],
            'generated_at' => $this->now(),
            'cached' => false,
            'partial' => !$data['complete'],
            'monitoring_context' => ($this->exclusions ?? new MonitoringExclusions())->fingerprint(),
        ];
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>
     */
    private function stalePayload(array $cached, string $label = 'Showing cached library stats'): array
    {
        $cached['cached'] = true;
        $cached['stale'] = true;
        $cached['refreshedLabel'] = $label;

        return $cached;
    }

    /** @param array<string, mixed>|null $cached @return array<string, mixed>|null */
    private function backoffPayload(?array $cached): ?array
    {
        $failure = $this->readFailure();
        if ($failure === null || (int) ($failure['retry_after'] ?? 0) <= $this->now()) {
            return null;
        }

        if ($cached !== null) {
            return $this->retryingPayload($this->stalePayload($cached, 'Showing cached stats while refresh waits to retry'));
        }

        if (is_array($failure['payload'] ?? null)) {
            $payload = $failure['payload'];
            $payload['cached'] = true;

            return $this->retryingPayload($payload);
        }

        throw new \RuntimeException((string) ($failure['message'] ?? 'Library refresh is waiting to retry.'));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function retryingPayload(array $payload): array
    {
        $payload['retrying'] = true;

        return $payload;
    }

    /** @param array<string, mixed>|null $payload */
    private function rememberFailure(?array $payload, string $message): void
    {
        try {
            $this->failureCache()->write([
                'retry_after' => $this->now() + $this->failureBackoff,
                'monitoring_context' => ($this->exclusions ?? new MonitoringExclusions())->fingerprint(),
                'message' => $message,
                'payload' => $payload,
            ]);
        } catch (\Throwable) {
            // A failed retry marker must never hide the usable partial or stale response.
        }
    }

    /** @return array<string, mixed>|null */
    private function readFailure(): ?array
    {
        $failure = $this->failureCache()->read();
        if ($failure === null) {
            return null;
        }

        $context = (string) ($failure['monitoring_context'] ?? '');
        $expected = ($this->exclusions ?? new MonitoringExclusions())->fingerprint();

        return $context !== '' && hash_equals($expected, $context) ? $failure : null;
    }

    private function clearFailure(): void
    {
        @unlink($this->cacheFile() . '.retry');
    }

    private function failureCache(): AtomicJsonFile
    {
        return new AtomicJsonFile($this->cacheFile() . '.retry');
    }

    private function now(): int
    {
        return ($this->clock ?? static fn (): int => time())();
    }

    /**
     * A cache created before partial refreshes were tracked may already be
     * missing libraries. Only use it as a fallback when it covers every
     * library Jellyfin returned during the current refresh.
     *
     * @param array<string, mixed> $cached
     * @param array{summary: array<int, array<string, string>>, libraries: array<int, array<string, mixed>>, refreshedLabel: string, complete: bool} $data
     */
    private function cacheCoversLibraries(array $cached, array $data): bool
    {
        $cachedLibraries = is_array($cached['libraries'] ?? null) ? $cached['libraries'] : [];
        $cachedNames = [];
        foreach ($cachedLibraries as $library) {
            if (is_array($library)) {
                $cachedNames[] = mb_strtolower((string) ($library['name'] ?? ''));
            }
        }

        foreach ($data['libraries'] as $library) {
            $name = mb_strtolower((string) ($library['name'] ?? ''));
            if ($name !== '' && !in_array($name, $cachedNames, true)) {
                return false;
            }
        }

        return $data['libraries'] !== [];
    }

    private function ttl(): int
    {
        return max(30, (int) (Config::get('LIBRARIES_CACHE_TTL', '300') ?? '300'));
    }

    private function cacheFile(): string
    {
        return $this->cachePath ?? dirname(__DIR__, 2) . '/var/cache/libraries.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $cached = $this->cache()->read();
        $policy = $this->exclusions ?? new MonitoringExclusions();
        // Old cache files predate monitoring exclusions and represent an empty
        // rule set. Never reuse their viewing summaries with active exclusions.
        $context = $cached['monitoring_context'] ?? (new MonitoringExclusions([]))->fingerprint();

        return $context === $policy->fingerprint() ? $cached : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        $this->cache()->write($payload);
    }

    private function cache(): AtomicJsonFile
    {
        return new AtomicJsonFile($this->cacheFile());
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

    /** @return array{rows: array<int, \Dibi\Row>, available: bool} */
    private function historySnapshot(): array
    {
        try {
            return [
                'rows' => ($this->history ?? new PlayHistoryRepository())->itemPlaySummaries(),
                'available' => true,
            ];
        } catch (\Throwable $e) {
            Log::logException(new \RuntimeException('Could not read library playback history.', previous: $e));

            return ['rows' => [], 'available' => false];
        }
    }

    /**
     * @param array<string, mixed> $folder
     * @param array<int, \Dibi\Row> $historyRows
     * @return array<string, mixed>
     */
    private function libraryCard(array $folder, array $counts, array $historyRows, bool $historyAvailable): array
    {
        /** @var array<string, string> $meta */
        $meta = $folder['DashboardMeta'];
        $id = (string) ($folder['Id'] ?? '');
        $name = (string) $meta['display'];
        $kind = (string) $meta['kind'];
        $accent = (string) $meta['accent'];
        $actualName = (string) ($folder['DashboardName'] ?? $name);
        $libraryHistory = $historyAvailable
            ? $this->libraryHistory($historyRows, $name, $actualName)
            : $this->unavailableHistory();
        $totalFiles = (int) ($counts['total'] ?? 0);

        return [
            'name' => $name,
            'type' => $this->typeLabel($kind),
            'kind' => $kind,
            'glyph' => (string) $meta['glyph'],
            'accent' => $accent,
            'chipBg' => $this->alpha($accent, .15),
            'chipBorder' => $this->alpha($accent, .3),
            'banner' => $this->banner($id, $kind),
            'totalFiles' => $this->comma($totalFiles),
            'totalFilesRaw' => $totalFiles,
            'totalPlays' => $historyAvailable ? $this->comma((int) $libraryHistory['plays']) : 'Unavailable',
            'totalPlaysRaw' => (int) $libraryHistory['plays'],
            'playback' => $historyAvailable ? $this->longDuration((int) $libraryHistory['watch_sec']) : 'Unavailable',
            'playbackRaw' => (int) $libraryHistory['watch_sec'],
            'playbackEstimated' => $libraryHistory['estimated'],
            'playbackAvailable' => $historyAvailable,
            'lastActivity' => (string) $libraryHistory['last_activity'],
            'lastPlayed' => (string) $libraryHistory['last_played'],
            'lastUser' => (string) $libraryHistory['last_user'],
            'breakdown' => $counts['breakdown'] ?? [],
            'available' => true,
            'isMovies' => $kind === 'movies' || $kind === 'standup' || $kind === 'events',
            'isTv' => $kind === 'tv',
            'isAnime' => $kind === 'anime',
            'isEvent' => $kind === 'events',
        ];
    }

    /**
     * @param array<string, mixed> $folder
     * @param array<int, \Dibi\Row> $historyRows
     * @return array<string, mixed>
     */
    private function unavailableLibraryCard(array $folder, array $historyRows, bool $historyAvailable): array
    {
        /** @var array<string, string> $meta */
        $meta = is_array($folder['DashboardMeta'] ?? null) ? $folder['DashboardMeta'] : $this->metaFor(
            (string) ($folder['Name'] ?? 'Library'),
            strtolower((string) ($folder['CollectionType'] ?? '')),
        );
        $id = (string) ($folder['Id'] ?? '');
        $name = (string) ($meta['display'] ?? $folder['Name'] ?? 'Library');
        $kind = (string) ($meta['kind'] ?? 'mixed');
        $accent = (string) ($meta['accent'] ?? '#7c5cff');
        $actualName = (string) ($folder['DashboardName'] ?? $folder['Name'] ?? $name);
        $libraryHistory = $historyAvailable
            ? $this->libraryHistory($historyRows, $name, $actualName)
            : $this->unavailableHistory();

        return [
            'name' => $name,
            'type' => $this->typeLabel($kind),
            'kind' => $kind,
            'glyph' => (string) ($meta['glyph'] ?? $this->glyphFor($name)),
            'accent' => $accent,
            'chipBg' => $this->alpha($accent, .15),
            'chipBorder' => $this->alpha($accent, .3),
            'banner' => $this->banner($id, $kind),
            'totalFiles' => 'Unavailable',
            'totalFilesRaw' => 0,
            'totalPlays' => $historyAvailable ? $this->comma((int) $libraryHistory['plays']) : 'Unavailable',
            'totalPlaysRaw' => (int) $libraryHistory['plays'],
            'playback' => $historyAvailable ? $this->longDuration((int) $libraryHistory['watch_sec']) : 'Unavailable',
            'playbackRaw' => (int) $libraryHistory['watch_sec'],
            'playbackEstimated' => $libraryHistory['estimated'],
            'playbackAvailable' => $historyAvailable,
            'lastActivity' => (string) $libraryHistory['last_activity'],
            'lastPlayed' => (string) $libraryHistory['last_played'],
            'lastUser' => (string) $libraryHistory['last_user'],
            'breakdown' => [],
            'available' => false,
            'isMovies' => $kind === 'movies' || $kind === 'standup' || $kind === 'events',
            'isTv' => $kind === 'tv',
            'isAnime' => $kind === 'anime',
            'isEvent' => $kind === 'events',
        ];
    }

    /**
     * @return array{total: int, breakdown: array<int, array<string, string>>}
     */
    private function countSnapshot(LibraryOverviewClient $client, string $parentId, string $kind, string $accent): array
    {
        if (in_array($kind, ['tv', 'anime'], true)) {
            $series = $this->countItems($client, $parentId, 'Series');
            $seasons = $this->countItems($client, $parentId, 'Season');
            $episodes = $this->countItems($client, $parentId, 'Episode');

            return [
                'total' => $episodes,
                'breakdown' => [
                    ['value' => $this->comma($series), 'label' => 'Series', 'color' => $accent],
                    ['value' => $this->comma($seasons), 'label' => 'Seasons', 'color' => '#f0c46b'],
                    ['value' => $this->comma($episodes), 'label' => 'Episodes', 'color' => '#3b9eff'],
                ],
            ];
        }

        if ($kind === 'music') {
            $artists = $this->countItems($client, $parentId, 'MusicArtist');
            $albums = $this->countItems($client, $parentId, 'MusicAlbum');
            $songs = $this->countItems($client, $parentId, 'Audio');

            return [
                'total' => $songs,
                'breakdown' => [
                    ['value' => $this->comma($artists), 'label' => 'Artists', 'color' => $accent],
                    ['value' => $this->comma($albums), 'label' => 'Albums', 'color' => '#f0c46b'],
                    ['value' => $this->comma($songs), 'label' => 'Songs', 'color' => '#3b9eff'],
                ],
            ];
        }

        if ($kind === 'videos') {
            $videos = $this->countItems($client, $parentId, 'Video');

            return [
                'total' => $videos,
                'breakdown' => [
                    ['value' => $this->comma($videos), 'label' => 'Videos', 'color' => $accent],
                ],
            ];
        }

        if ($kind === 'mixed') {
            $movies = $this->countItems($client, $parentId, 'Movie');
            $series = $this->countItems($client, $parentId, 'Series');
            $videos = $this->countItems($client, $parentId, 'Video');
            $episodes = $this->countItems($client, $parentId, 'Episode');

            return [
                'total' => $movies + $videos + $episodes,
                'breakdown' => [
                    ['value' => $this->comma($movies), 'label' => 'Movies', 'color' => $accent],
                    ['value' => $this->comma($series), 'label' => 'Series', 'color' => '#f0c46b'],
                    ['value' => $this->comma($videos), 'label' => 'Videos', 'color' => '#6fb6ff'],
                ],
            ];
        }

        $label = match ($kind) {
            'standup' => 'Specials',
            'events' => 'Events',
            default => 'Movies',
        };

        $movies = $this->countItems($client, $parentId, 'Movie');
        $videos = $this->countItems($client, $parentId, 'Video');

        return [
            'total' => $movies + $videos,
            'breakdown' => [
                ['value' => $this->comma($movies), 'label' => $label, 'color' => $accent],
                ['value' => $this->comma($videos), 'label' => 'Videos', 'color' => '#6fb6ff'],
            ],
        ];
    }

    private function countItems(LibraryOverviewClient $client, string $parentId, string $types): int
    {
        return $client->itemCount([
            'ParentId' => $parentId,
            'Recursive' => 'true',
            'IncludeItemTypes' => $types,
        ]);
    }

    /**
     * Plays whose stored real library name matches this library.
     * $rows are per-item summaries (plays / watch_sec) from itemPlaySummaries().
     *
     * @param array<int, \Dibi\Row|array<string, mixed>> $rows
     * @return array{plays: int, watch_sec: int, estimated: bool, last_activity: string, last_played: string, last_user: string}
     */
    private function libraryHistory(array $rows, string $displayName, string $actualName): array
    {
        $plays = 0;
        $watchSec = 0;
        $estimated = false;
        $last = null;
        $wanted = array_map(
            static fn (string $name): string => mb_strtolower($name),
            array_values(array_filter([$displayName, $actualName], static fn (string $name): bool => $name !== ''))
        );

        foreach ($rows as $row) {
            $resolved = (string) ($row['library'] ?? '');
            if (!in_array(mb_strtolower($resolved), $wanted, true)) {
                continue;
            }

            $plays += (int) ($row['plays'] ?? 1);
            $watchSec += (int) ($row['watch_sec'] ?? $row['watched_sec'] ?? 0);
            $estimated = $estimated || (int) ($row['estimated_plays'] ?? 1) > 0;
            if ($last === null || (string) $row['started_at'] > (string) $last['started_at']) {
                $last = $row;
            }
        }

        if ($last === null) {
            return [
                'plays' => 0,
                'watch_sec' => 0,
                'estimated' => false,
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
            'estimated' => $estimated,
            'last_activity' => $this->relativeTime(new \DateTimeImmutable((string) $last['started_at'])),
            'last_played' => $episode !== '' ? $title . ' - ' . $episode : $title,
            'last_user' => (string) ($last['user_name'] ?? 'Unknown user'),
        ];
    }

    /** @return array{plays: int, watch_sec: int, estimated: bool, last_activity: string, last_played: string, last_user: string} */
    private function unavailableHistory(): array
    {
        return [
            'plays' => 0,
            'watch_sec' => 0,
            'estimated' => false,
            'last_activity' => 'History unavailable',
            'last_played' => 'Playback history could not be read',
            'last_user' => 'Unavailable',
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
        $playback = 0;
        $estimated = false;
        $complete = true;
        $historyAvailable = true;

        foreach ($libraries as $library) {
            $items += (int) ($library['totalFilesRaw'] ?? 0);
            $plays += (int) ($library['totalPlaysRaw'] ?? 0);
            $playback += (int) ($library['playbackRaw'] ?? 0);
            $estimated = $estimated || ($library['playbackEstimated'] ?? false);
            $complete = $complete && ($library['available'] ?? true) === true;
            $historyAvailable = $historyAvailable && ($library['playbackAvailable'] ?? true) === true;
        }

        return [
            ['label' => 'Libraries', 'color' => '#7c5cff', 'value' => $this->comma(count($libraries)), 'sub' => 'selected media libraries'],
            ['label' => 'Total Items', 'color' => '#3b9eff', 'value' => $complete ? $this->comma($items) : 'Unavailable', 'sub' => $complete ? 'movies - episodes - songs - videos' : 'one or more libraries could not be counted'],
            ['label' => $estimated ? 'Estimated Playback' : 'Total Playback', 'color' => '#f7b955', 'value' => $historyAvailable ? $this->longDuration($playback) : 'Unavailable', 'sub' => $historyAvailable ? 'recorded by dashboard' : 'playback history could not be read'],
            ['label' => 'Total Plays', 'color' => '#34d8a6', 'value' => $historyAvailable ? $this->comma($plays) : 'Unavailable', 'sub' => $historyAvailable ? 'recorded by dashboard' : 'playback history could not be read'],
        ];
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
        $diff = max(0, $this->now() - $date->getTimestamp());
        if ($diff < 3600) {
            $minutes = max(1, intdiv($diff, 60));

            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' hours ago';
        }

        return intdiv($diff, 86400) . ' days ago';
    }

    private function alpha(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
    }

    private function comma(int $value): string
    {
        return number_format($value);
    }

}
