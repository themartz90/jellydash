<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Cache\AtomicJsonFile;

final class RecentlyAddedService
{
    private const WINDOW_DAYS = 14;
    private const MAX_CARDS = 12;
    private const INITIAL_LIBRARY_LIMIT = 4;
    private const PAGE_SIZE = 100;
    private const MAX_RAW_ITEMS = 300;
    private const CACHE_TTL = 300;
    private const CACHE_SCHEMA_VERSION = 2;
    private const ALLOWED_TYPES = ['Movie', 'Series', 'Episode'];

    public function __construct(
        private ?JellyfinClient $client = null,
        private ?\DateTimeImmutable $now = null,
        private ?string $cachePath = null,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, windowDays: int, generated_at: int, cached: bool, schemaVersion: int, stale?: bool}
     */
    public function cachedPayload(): array
    {
        $cached = $this->readCache();
        $cacheSchemaIsCurrent = $cached !== null
            && (int) ($cached['schemaVersion'] ?? 0) === self::CACHE_SCHEMA_VERSION;
        if ($cached !== null) {
            $cached = $this->pruneCachedPayload($cached);
        }

        if ($cacheSchemaIsCurrent && $cached !== null && (time() - (int) ($cached['generated_at'] ?? 0)) < self::CACHE_TTL) {
            $cached['cached'] = true;

            return $cached;
        }

        try {
            return $this->cache()->withExclusiveLock(function (): array {
                $cached = $this->readCache();
                $cacheSchemaIsCurrent = $cached !== null
                    && (int) ($cached['schemaVersion'] ?? 0) === self::CACHE_SCHEMA_VERSION;
                if ($cached !== null) {
                    $cached = $this->pruneCachedPayload($cached);
                }
                if ($cacheSchemaIsCurrent && $cached !== null && (time() - (int) ($cached['generated_at'] ?? 0)) < self::CACHE_TTL) {
                    $cached['cached'] = true;

                    return $cached;
                }

                try {
                    return $this->refreshCacheUnlocked();
                } catch (\Throwable $e) {
                    if ($cached !== null) {
                        $cached['cached'] = true;
                        $cached['stale'] = true;

                        return $cached;
                    }

                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            if ($cached !== null) {
                $cached['cached'] = true;
                $cached['stale'] = true;

                return $cached;
            }

            throw $e;
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, windowDays: int, generated_at: int, cached: bool, schemaVersion: int}
     */
    public function refreshCache(): array
    {
        return $this->cache()->withExclusiveLock(fn (): array => $this->refreshCacheUnlocked());
    }

    /** @return array{items: array<int, array<string, mixed>>, windowDays: int, generated_at: int, cached: bool, schemaVersion: int} */
    private function refreshCacheUnlocked(): array
    {
        $payload = $this->data();
        $this->writeCache($payload);

        return $payload;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, windowDays: int, generated_at: int, cached: bool, schemaVersion: int}
     */
    private function data(): array
    {
        $client = $this->client ?? new JellyfinClient();
        $rawItems = $this->rawItems($client);
        $serverId = $this->serverId($client);

        try {
            $locations = $client->libraryLocations();
            $names = $client->libraryNames();
        } catch (\Throwable) {
            // Library labels are useful, but a permissions or mapping failure
            // should not hide otherwise valid recent media.
            $locations = [];
            $names = [];
        }

        return [
            'items' => $this->cards($rawItems, $locations, $names, $serverId),
            'windowDays' => self::WINDOW_DAYS,
            'generated_at' => time(),
            'cached' => false,
            'schemaVersion' => self::CACHE_SCHEMA_VERSION,
        ];
    }

    /**
     * Fetch newest visual media in bounded pages. Jellyfin has no DateCreated
     * lower-bound filter, so stop as soon as its descending result crosses the
     * two-week cutoff. The hard cap protects large libraries with unusual data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rawItems(JellyfinClient $client): array
    {
        $items = [];
        $cutoff = $this->clock()->modify('-' . self::WINDOW_DAYS . ' days');

        for ($start = 0; $start < self::MAX_RAW_ITEMS; $start += self::PAGE_SIZE) {
            $payload = $client->getJson('/Items?' . http_build_query(
                [
                    'Recursive' => 'true',
                    'SortBy' => 'DateCreated',
                    'SortOrder' => 'Descending',
                    'IncludeItemTypes' => implode(',', self::ALLOWED_TYPES),
                    'StartIndex' => $start,
                    'Limit' => self::PAGE_SIZE,
                    'EnableTotalRecordCount' => 'false',
                    'Fields' => 'DateCreated,Path,ParentId',
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            ), 15);

            $page = is_array($payload) && is_array($payload['Items'] ?? null)
                ? array_values(array_filter($payload['Items'], 'is_array'))
                : [];
            array_push($items, ...$page);

            if (count($page) < self::PAGE_SIZE || $this->pageCrossedCutoff($page, $cutoff)) {
                break;
            }
        }

        return $items;
    }

    /**
     * Convert Jellyfin rows into display cards. Episodes from one season share
     * a card, and a newly added Series row absorbs its episode groups.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, array<int, string>> $locations
     * @param array<int, string> $names
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $items, array $locations, array $names, ?string $serverId = null): array
    {
        $client = $this->client ?? new JellyfinClient();
        $cutoff = $this->clock()->modify('-' . self::WINDOW_DAYS . ' days');
        $regular = [];
        $seriesIndexes = [];
        $episodeGroups = [];

        foreach ($items as $item) {
            $type = (string) ($item['Type'] ?? '');
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                continue;
            }

            $created = $this->createdAt((string) ($item['DateCreated'] ?? ''));
            if ($created === null || $created < $cutoff) {
                continue;
            }

            $id = trim((string) ($item['Id'] ?? ''));
            $title = trim((string) ($item['Name'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }

            $path = (string) ($item['Path'] ?? '');
            $library = $client->libraryNameForPath($path, $locations, $names) ?? 'Media';

            if ($type === 'Episode') {
                $seriesId = trim((string) ($item['SeriesId'] ?? ''));
                $seriesName = trim((string) ($item['SeriesName'] ?? ''));
                $seriesKey = $seriesId !== ''
                    ? mb_strtolower($seriesId)
                    : ($seriesName !== '' ? 'name:' . mb_strtolower($seriesName) : 'item:' . mb_strtolower($id));
                $seasonId = trim((string) ($item['SeasonId'] ?? ''));
                $seasonName = trim((string) ($item['SeasonName'] ?? ''));
                $seasonNumber = (int) ($item['ParentIndexNumber'] ?? 0);
                $seasonKey = $seasonId !== '' ? mb_strtolower($seasonId) : 'season:' . ($seasonNumber > 0 ? $seasonNumber : mb_strtolower($seasonName));
                $groupKey = $seriesKey . ':' . $seasonKey;

                if (!isset($episodeGroups[$groupKey])) {
                    $episodeGroups[$groupKey] = [
                        'seriesKey' => $seriesKey,
                        'seriesId' => $seriesId,
                        'title' => $seriesName !== '' ? $seriesName : $title,
                        'seasonName' => $seasonName,
                        'seasonNumber' => $seasonNumber,
                        'episodeName' => $title,
                        'episodeNumber' => (int) ($item['IndexNumber'] ?? 0),
                        'count' => 0,
                        'created' => $created,
                        'library' => $library,
                        'posterId' => $seriesId !== '' ? $seriesId : $id,
                        'seed' => $seriesId !== '' ? $seriesId : $id,
                    ];
                }

                ++$episodeGroups[$groupKey]['count'];
                if ($created > $episodeGroups[$groupKey]['created']) {
                    $episodeGroups[$groupKey]['created'] = $created;
                    $episodeGroups[$groupKey]['episodeName'] = $title;
                    $episodeGroups[$groupKey]['episodeNumber'] = (int) ($item['IndexNumber'] ?? 0);
                }

                continue;
            }

            $year = (int) ($item['ProductionYear'] ?? 0);
            $card = $this->baseCard(
                $id,
                $title,
                $type === 'Movie' ? 'Movie' . ($year > 0 ? ' · ' . $year : '') : 'New series',
                $library,
                $created,
                $id,
                $id,
                $serverId,
            );
            $card['seriesKey'] = $type === 'Series' ? mb_strtolower($id) : '';
            $card['episodeCount'] = 0;

            if ($type === 'Series') {
                $seriesIndexes[mb_strtolower($id)] = count($regular);
                $seriesIndexes['name:' . mb_strtolower($title)] = count($regular);
            }
            $regular[] = $card;
        }

        foreach ($episodeGroups as $group) {
            $seriesKey = (string) $group['seriesKey'];
            if (isset($seriesIndexes[$seriesKey])) {
                $index = $seriesIndexes[$seriesKey];
                $regular[$index]['episodeCount'] += (int) $group['count'];
                if ($group['created'] > $regular[$index]['created']) {
                    $regular[$index]['created'] = $group['created'];
                    $regular[$index]['addedAt'] = $group['created']->format(\DateTimeInterface::ATOM);
                    $regular[$index]['dateLabel'] = $this->dateLabel($group['created']);
                }
                continue;
            }

            $count = (int) $group['count'];
            $season = (int) $group['seasonNumber'];
            $seasonLabel = $season > 0 ? 'Season ' . $season : trim((string) $group['seasonName']);
            if ($count > 1) {
                $meta = ($seasonLabel !== '' ? $seasonLabel . ' · ' : '') . $count . ' episodes';
            } else {
                $episode = (int) $group['episodeNumber'];
                $number = ($season > 0 ? 'S' . $season . ' ' : '') . ($episode > 0 ? 'E' . $episode : 'Episode');
                $meta = trim($number . ' · ' . (string) $group['episodeName'], ' ·');
            }

            $regular[] = $this->baseCard(
                (string) $group['seed'],
                (string) $group['title'],
                $meta,
                (string) $group['library'],
                $group['created'],
                (string) $group['posterId'],
                (string) $group['seed'],
                $serverId,
            );
        }

        foreach ($regular as &$card) {
            $episodeCount = (int) ($card['episodeCount'] ?? 0);
            if ($episodeCount > 0) {
                $card['meta'] = 'New series · ' . $episodeCount . ($episodeCount === 1 ? ' episode' : ' episodes');
            }
        }
        unset($card);

        usort($regular, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);
        $regular = $this->balancedCards($regular);

        return array_map(static function (array $card): array {
            unset($card['created'], $card['seriesKey'], $card['episodeCount']);

            return $card;
        }, $regular);
    }

    /**
     * Give every represented library four chronological slots first. Remaining
     * slots go to the least-represented library with the newest deferred item.
     *
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private function balancedCards(array $cards): array
    {
        $libraries = array_values(array_unique(array_map(static fn (array $card): string => (string) $card['library'], $cards)));
        if (count($libraries) < 2) {
            return array_slice($cards, 0, self::MAX_CARDS);
        }

        $selected = [];
        $counts = [];
        $deferred = [];
        foreach ($cards as $card) {
            $library = (string) $card['library'];
            $counts[$library] ??= 0;
            if ($counts[$library] < self::INITIAL_LIBRARY_LIMIT && count($selected) < self::MAX_CARDS) {
                $selected[] = $card;
                ++$counts[$library];
            } else {
                $deferred[$library][] = $card;
            }
        }

        while (count($selected) < self::MAX_CARDS) {
            $candidateLibrary = null;
            $candidate = null;
            foreach ($deferred as $library => $libraryCards) {
                if ($libraryCards === []) {
                    continue;
                }
                $next = $libraryCards[0];
                if (
                    $candidate === null
                    || ($counts[$library] ?? 0) < ($counts[$candidateLibrary] ?? 0)
                    || (($counts[$library] ?? 0) === ($counts[$candidateLibrary] ?? 0) && $next['created'] > $candidate['created'])
                ) {
                    $candidateLibrary = $library;
                    $candidate = $next;
                }
            }

            if ($candidateLibrary === null || $candidate === null) {
                break;
            }

            $selected[] = array_shift($deferred[$candidateLibrary]);
            ++$counts[$candidateLibrary];
        }

        usort($selected, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);

        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseCard(
        string $seed,
        string $title,
        string $meta,
        string $library,
        \DateTimeImmutable $created,
        string $posterId,
        string $detailsId,
        ?string $serverId,
    ): array {
        return [
            'title' => $title,
            'meta' => $meta,
            'library' => $library,
            'dateLabel' => $this->dateLabel($created),
            'addedAt' => $created->format(\DateTimeInterface::ATOM),
            'poster' => $this->posterUrl($posterId),
            'jellyfinUrl' => $this->detailsUrl($detailsId, $serverId),
            'tone' => (abs(crc32($seed)) % 5) + 1,
            'created' => $created,
        ];
    }

    private function serverId(JellyfinClient $client): ?string
    {
        try {
            $payload = $client->getJson('/System/Info/Public', 4);
            $serverId = is_array($payload) ? trim((string) ($payload['Id'] ?? '')) : '';

            return $this->safeIdentifier($serverId) ? $serverId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function detailsUrl(string $itemId, ?string $serverId): string
    {
        $baseUrl = rtrim(trim(($this->client ?? new JellyfinClient())->baseUrl()), '/');
        $parts = parse_url($baseUrl);
        if (
            !$this->safeIdentifier($itemId)
            || !$this->safeIdentifier((string) $serverId)
            || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return '';
        }

        return $baseUrl . '/web/#/details?id=' . rawurlencode($itemId) . '&serverId=' . rawurlencode((string) $serverId);
    }

    private function safeIdentifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;
    }

    private function dateLabel(\DateTimeImmutable $created): string
    {
        $timezone = $this->clock()->getTimezone();
        $today = $this->clock()->setTimezone($timezone)->setTime(0, 0);
        $added = $created->setTimezone($timezone)->setTime(0, 0);
        if ($added >= $today) {
            return 'Today';
        }

        if ($added == $today->modify('-1 day')) {
            return 'Yesterday';
        }

        $days = (int) $added->diff($today)->format('%a');

        return $days . ' days ago';
    }

    private function posterUrl(string $itemId): string
    {
        if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
            return '';
        }

        return '/api/image.php?item=' . rawurlencode($itemId) . '&type=Primary&maxWidth=320';
    }

    private function clock(): \DateTimeImmutable
    {
        return $this->now ?? new \DateTimeImmutable('now');
    }

    private function createdAt(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $page
     */
    private function pageCrossedCutoff(array $page, \DateTimeImmutable $cutoff): bool
    {
        for ($i = count($page) - 1; $i >= 0; --$i) {
            $created = $this->createdAt((string) ($page[$i]['DateCreated'] ?? ''));
            if ($created !== null) {
                return $created < $cutoff;
            }
        }

        return false;
    }

    private function cacheFile(): string
    {
        return $this->cachePath ?? dirname(__DIR__, 2) . '/var/cache/recently-added.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        return $this->cache()->read();
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pruneCachedPayload(array $payload): array
    {
        $cutoff = $this->clock()->modify('-' . self::WINDOW_DAYS . ' days');
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $payload['items'] = array_values(array_filter($items, function (mixed $item) use ($cutoff): bool {
            if (!is_array($item)) {
                return false;
            }
            $created = $this->createdAt((string) ($item['addedAt'] ?? ''));

            return $created !== null && $created >= $cutoff;
        }));
        $payload['windowDays'] = self::WINDOW_DAYS;
        $payload['schemaVersion'] = (int) ($payload['schemaVersion'] ?? 0);

        return $payload;
    }
}
