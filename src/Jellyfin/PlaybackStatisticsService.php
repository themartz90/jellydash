<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;

final class PlaybackStatisticsService
{
    private const RANGES = [
        'week' => ['label' => 'Week', 'sub' => 'Last 7 days'],
        'month' => ['label' => 'Month', 'sub' => 'Last 30 days'],
        'year' => ['label' => 'Year', 'sub' => 'Last 12 months'],
        'all' => ['label' => 'All time', 'sub' => 'All recorded history'],
    ];

    private const COLORS = ['#7c5cff', '#34d8a6', '#3b9eff', '#f7b955', '#ff6b9d', '#f0913a', '#6f7bff', '#c44fff'];

    public function __construct(
        private ?PlayHistoryRepository $repository = null,
        private ?JellyfinClient $client = null,
        private ?StatisticsPayloadCache $payloadCache = null,
    ) {
    }

    private ?JellyfinUserAvatars $avatars = null;

    /**
     * Return the completed Statistics payload through its short per-range
     * cache. The calculation itself stays in data() so accuracy tests and
     * callers that need an explicit snapshot remain deterministic.
     *
     * @return array<string, mixed>
     */
    public function cachedData(string $range, ?\DateTimeImmutable $now = null): array
    {
        $range = StatisticsPeriod::normalizeRange($range);
        $now ??= new \DateTimeImmutable('now');

        return ($this->payloadCache ?? new StatisticsPayloadCache())->remember(
            $range,
            $now,
            $this->cacheContextFingerprint(),
            fn (): array => $this->data($range, $now),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $range, ?\DateTimeImmutable $now = null): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : 'week';
        $now ??= new \DateTimeImmutable('now');
        $repository = $this->repository ?? new PlayHistoryRepository();
        $periodStart = StatisticsPeriod::currentStart($range, $now);
        $periodEnd = $periodStart === null ? null : $now->setTime(0, 0)->modify('+1 day');
        $rows = $repository->statisticsRowsForPeriod($periodStart, $periodEnd);
        $previousRows = $this->previousRows($repository, $range, $now);

        $users = array_map(function (array $user) use ($range, $periodStart, $periodEnd): array {
            if ((bool) $user['filterable']) {
                $user['href'] = $this->historyUrl($range, $periodStart, $periodEnd, (string) $user['user']);
            }

            unset($user['filterable']);

            return $user;
        }, $this->users($rows));
        $clients = $this->clientHistoryLinks(
            $this->clients($rows),
            $range,
            $periodStart,
            $periodEnd,
        );
        $directness = $this->directness($rows);
        foreach ($directness['legend'] as $index => $item) {
            $directness['legend'][$index]['href'] = $this->historyUrl(
                $range,
                $periodStart,
                $periodEnd,
                method: (string) $item['method'],
            );
            unset($directness['legend'][$index]['method']);
        }
        $codecCounts = $this->counts($rows, 'source_video_codec');
        $reasonCounts = $this->reasonCounts($rows);
        $codecs = $this->bars($codecCounts, 'Other codecs');
        $reasons = $this->bars($reasonCounts, 'Other reasons');
        $watchSeconds = $this->sumViewingSeconds($rows);
        $previousWatchSeconds = $this->sumViewingSeconds($previousRows);
        $watchTimeEstimated = $this->hasEstimatedViewingTime($rows);
        $plays = count($rows);
        $previousPlays = count($previousRows);
        $transcodeRate = self::standalonePercentage($directness['transcode_count'], $plays);
        $previousDirectness = $this->directness($previousRows);
        $previousTranscodeRate = count($previousRows) > 0
            ? self::standalonePercentage($previousDirectness['transcode_count'], count($previousRows))
            : null;
        $allTimeTitleGroups = $range === 'all'
            ? $this->groupTitles($this->titleRowsWithoutExcludedLibraries($rows))
            : null;
        $trending = $this->trending($rows, $range, $periodStart, $periodEnd, $allTimeTitleGroups);
        $mostWatched = $this->mostWatched($repository, $range, $rows, $allTimeTitleGroups);

        return [
            'range' => $range,
            'rangeLabel' => self::RANGES[$range]['label'],
            'ranges' => $this->ranges($range),
            'subLabel' => self::RANGES[$range]['sub'] . ' - all libraries',
            'trending' => $trending,
            'hasTrending' => $trending !== [],
            'mostWatched' => $mostWatched,
            'hasMostWatched' => $mostWatched['series'] !== [] || $mostWatched['movies'] !== [],
            'kpis' => [
                $this->kpi($watchTimeEstimated ? 'Estimated Watch Time' : 'Total Watch Time', '#7c5cff', $this->duration($watchSeconds), $this->delta($watchSeconds, $previousWatchSeconds, $range, 'watch time')),
                $this->kpi(
                    'Total Plays',
                    '#3b9eff',
                    $this->comma($plays),
                    $this->delta($plays, $previousPlays, $range, 'plays'),
                    $this->historyUrl($range, $periodStart, $periodEnd),
                ),
                $this->kpi('Active Users', '#34d8a6', (string) count($users), ['text' => 'unique viewers', 'color' => 'rgba(255,255,255,0.42)']),
                $this->kpi(
                    'Transcode Rate',
                    '#f7b955',
                    $transcodeRate . '%',
                    $this->rateDelta($transcodeRate, $previousTranscodeRate, $range),
                    $this->historyUrl($range, $periodStart, $periodEnd, method: 'transcode'),
                ),
            ],
            'totalWatch' => $this->duration($watchSeconds),
            'watchTimeEstimated' => $watchTimeEstimated,
            'totalWatchDelta' => $this->delta($watchSeconds, $previousWatchSeconds, $range, 'previous period')['text'],
            'totalWatchDeltaColor' => $this->delta($watchSeconds, $previousWatchSeconds, $range, 'previous period')['color'],
            'trend' => $this->trend($rows, $range, $now),
            'trendUnit' => $this->trendUnit($range),
            'topUsers' => array_slice($users, 0, 6),
            'directnessConic' => $directness['conic'],
            'directVal' => $directness['direct_pct'] . '%',
            'directnessLegend' => $directness['legend'],
            'codecs' => $codecs,
            'hasCodecData' => $codecs !== [],
            'codecCoverage' => $this->codecCoverage(array_sum($codecCounts), $plays),
            'reasons' => $reasons,
            'hasReasonData' => $reasons !== [],
            'reasonCoverage' => $this->reasonCoverage($this->reasonSessionCount($rows), $directness['transcode_count']),
            'clientsConic' => $clients['conic'],
            'totalSessionsVal' => $this->comma($plays),
            'clientBreakdown' => $clients['breakdown'],
            'clientsRanked' => $clients['ranked'],
            'clientsTranscode' => $clients['transcode'],
            'clientsUsage' => $clients['usage'],
            'usersTable' => $users,
            'isEmpty' => $plays === 0,
        ];
    }

    /**
     * Most-watched titles in the period, grouped by series (episodes) or title
     * (movies) and ranked by distinct viewers then play count. Episodes carry
     * their series poster.
     *
     * @param array<int, \Dibi\Row> $rows
     * @param array<string, array<string, mixed>>|null $preparedGroups
     * @return array<int, array<string, mixed>>
     */
    private function trending(
        array $rows,
        string $range,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
        ?array $preparedGroups = null,
    ): array {
        $items = $this->titleCards(
            $preparedGroups ?? $this->groupTitles($this->titleRowsWithoutExcludedLibraries($rows)),
            $range,
            $periodStart,
            $periodEnd,
        );

        usort($items, static fn (array $a, array $b): int => [$b['users'], $b['plays']] <=> [$a['users'], $a['plays']]);

        return $this->visibleTitleCards(array_slice($items, 0, 6));
    }

    /**
     * All-time favourites: Trending's lifetime complement. Ranked by play
     * count (viewers as tiebreak, the reverse of Trending's ordering) and split
     * into series and movies, so both media kinds get their own podium. Always
     * computed over all recorded history regardless of the selected range.
     *
     * @param array<int, \Dibi\Row> $rangeRows rows already fetched for the page's range
     * @param array<string, array<string, mixed>>|null $preparedAllTimeGroups
     * @return array{series: array<int, array<string, mixed>>, movies: array<int, array<string, mixed>>}
     */
    private function mostWatched(
        PlayHistoryRepository $repository,
        string $range,
        array $rangeRows,
        ?array $preparedAllTimeGroups = null,
    ): array {
        // The 'all' range already fetched the full table; don't fetch it twice.
        if ($preparedAllTimeGroups !== null) {
            $groups = $preparedAllTimeGroups;
        } else {
            $rows = $range === 'all' ? $rangeRows : $repository->statisticsRowsForPeriod(null, null);
            $groups = $this->groupTitles($this->titleRowsWithoutExcludedLibraries($rows));
        }

        $series = $this->titleCards(
            array_filter($groups, static fn (array $g): bool => (bool) $g['isEpisode']),
            'all',
        );
        $movies = $this->titleCards(
            array_filter(
                $groups,
                static fn (array $g): bool => !$g['isEpisode'] && (string) $g['type'] === 'Movie'
            ),
            'all',
        );

        $byPlays = static fn (array $a, array $b): int => [$b['plays'], $b['users']] <=> [$a['plays'], $a['users']];
        usort($series, $byPlays);
        usort($movies, $byPlays);

        return [
            'series' => $this->visibleTitleCards(array_slice($series, 0, 6)),
            'movies' => $this->visibleTitleCards(array_slice($movies, 0, 6)),
        ];
    }

    /**
     * Group history rows by series (episodes) or title (everything else),
     * accumulating plays, distinct viewers, and the most recent item id for
     * representative artwork.
     *
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, array<string, mixed>>
     */
    private function groupTitles(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $type = (string) $row['item_type'];

            // Live TV viewings count toward watch time and user stats, but a
            // channel isn't a title, so keep it out of Trending and Most Watched.
            if ($type === 'TvChannel') {
                continue;
            }

            $series = (string) ($row['series_name'] ?? '');
            $name = (string) ($row['item_name'] ?? '');
            $isEpisode = $type === 'Episode' && $series !== '';
            $title = $isEpisode ? $series : ($name !== '' ? $name : 'Unknown title');
            $itemId = trim((string) ($row['item_id'] ?? ''));
            $library = trim((string) ($row['library'] ?? ''));
            $resolvedAt = trim((string) ($row['library_resolved_at'] ?? ''));
            $libraryConfirmed = $library !== '' && $resolvedAt !== '';
            if ($isEpisode) {
                $libraryKey = $libraryConfirmed ? mb_strtolower($library) . ':' : '';
                $key = 'series:' . $libraryKey . mb_strtolower($series);
            } else {
                $identity = $itemId !== '' ? mb_strtolower($itemId) : 'title:' . mb_strtolower($title);
                $key = 'item:' . mb_strtolower($type) . ':' . $identity;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'title' => $title,
                    'isEpisode' => $isEpisode,
                    'type' => $type,
                    'plays' => 0,
                    'users' => [],
                    'watched' => 0,
                    'latest' => '',
                    'itemId' => '',
                    'library' => '',
                    'libraryConfirmed' => false,
                ];
            }

            $groups[$key]['plays']++;
            $groups[$key]['watched'] += $this->viewingSeconds($row);

            $user = (string) ($row['user_name'] ?? '');
            if ($user !== '') {
                $groups[$key]['users'][$user] = true;
            }

            // Representative artwork = the most recently played item in the group.
            $startedAt = (string) $row['started_at'];
            if ($startedAt > (string) $groups[$key]['latest']) {
                $groups[$key]['latest'] = $startedAt;
                $groups[$key]['itemId'] = (string) $row['item_id'];
                $groups[$key]['type'] = $type;
                $library = trim((string) ($row['library'] ?? ''));
                $resolvedAt = trim((string) ($row['library_resolved_at'] ?? ''));
                $groups[$key]['library'] = $library;
                $groups[$key]['libraryConfirmed'] = $library !== '' && $resolvedAt !== '';
            }
        }

        return $groups;
    }

    /**
     * Build unranked strip cards from title groups; callers sort to taste.
     *
     * @param array<string, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function titleCards(
        array $groups,
        string $range,
        ?\DateTimeImmutable $periodStart = null,
        ?\DateTimeImmutable $periodEnd = null,
    ): array {
        $items = [];

        foreach ($groups as $group) {
            /** @var array<string, bool> $groupUsers */
            $groupUsers = $group['users'];
            $userCount = count($groupUsers);
            $plays = (int) $group['plays'];

            $items[] = [
                'title' => (string) $group['title'],
                'itemId' => (string) $group['itemId'],
                'plays' => $plays,
                'users' => $userCount,
                'multi' => $userCount > 1,
                'meta' => $plays . ($plays === 1 ? ' play' : ' plays')
                    . ' · ' . $userCount . ($userCount === 1 ? ' viewer' : ' viewers'),
                'poster' => $this->poster((string) $group['itemId'], (bool) $group['isEpisode']),
                'href' => $this->historyUrl(
                    $range,
                    $periodStart,
                    $periodEnd,
                    search: (string) $group['title'],
                ),
                '_library' => (string) $group['library'],
                '_libraryConfirmed' => (bool) $group['libraryConfirmed'],
            ];
        }

        return $items;
    }

    /**
     * Remove excluded and deleted title rows before grouping, so a blocked
     * play cannot contribute counts or representative metadata to an included
     * title. Confirmed History metadata avoids Jellyfin calls. Older rows use
     * batched item metadata to resolve their library before grouping.
     *
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, \Dibi\Row>
     */
    private function titleRowsWithoutExcludedLibraries(array $rows): array
    {
        $excluded = $this->excludedLibraries();
        if ($excluded === []) {
            return $rows;
        }

        $client = $this->client ?? new JellyfinClient();

        return $this->withoutExcludedLibraryRows(
            $rows,
            $excluded,
            static fn (array $itemIds): array => $client->itemImportMeta($itemIds),
        );
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @param array<int, string> $excluded lowercased library names
     * @param callable(array<int, string>): array<string, array{runtime_sec: int, library: string}> $loadMeta
     * @return array<int, \Dibi\Row>
     */
    private function withoutExcludedLibraryRows(
        array $rows,
        array $excluded,
        callable $loadMeta,
    ): array {
        $unresolvedIds = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['library'] ?? '')) === ''
                || trim((string) ($row['library_resolved_at'] ?? '')) === '') {
                $itemId = trim((string) ($row['item_id'] ?? ''));
                if ($itemId !== '') {
                    $unresolvedIds[$this->normalizedItemId($itemId)] = $itemId;
                }
            }
        }

        $meta = [];
        $lookupFailed = false;
        if ($unresolvedIds !== []) {
            try {
                foreach ($loadMeta(array_values($unresolvedIds)) as $itemId => $itemMeta) {
                    $normalized = $this->normalizedItemId((string) $itemId);
                    if ($normalized !== '') {
                        $meta[$normalized] = $itemMeta;
                    }
                }
            } catch (\Throwable) {
                $lookupFailed = true;
            }
        }

        $kept = [];
        foreach ($rows as $row) {
            $library = trim((string) ($row['library'] ?? ''));
            $libraryConfirmed = $library !== '' && trim((string) ($row['library_resolved_at'] ?? '')) !== '';
            if ($libraryConfirmed) {
                if (!in_array(mb_strtolower($library), $excluded, true)) {
                    $kept[] = $row;
                }
                continue;
            }

            if ($lookupFailed) {
                $kept[] = $row;
                continue;
            }
            $itemId = $this->normalizedItemId((string) ($row['item_id'] ?? ''));
            if ($itemId === '') {
                $kept[] = $row;
                continue;
            }
            $itemMeta = $meta[$itemId] ?? null;
            if ($itemMeta !== null
                && !in_array(mb_strtolower(trim($itemMeta['library'])), $excluded, true)) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    private function normalizedItemId(string $itemId): string
    {
        return strtolower(str_replace('-', '', trim($itemId)));
    }

    /**
     * Remove internal library metadata before cards reach the page payload.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function visibleTitleCard(array $item): array
    {
        unset($item['_library'], $item['_libraryConfirmed']);

        return $item;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function visibleTitleCards(array $items): array
    {
        return array_map($this->visibleTitleCard(...), $items);
    }

    /**
     * @return array<int, string>
     */
    private function excludedLibraries(): array
    {
        // Settings-page value wins; a never-saved setting falls back to the
        // legacy TRENDING_EXCLUDE_LIBRARIES env var.
        $raw = AppSettings::get('trending_exclude_libraries')
            ?? (string) Config::get('TRENDING_EXCLUDE_LIBRARIES', '');
        if (trim($raw) === '') {
            return [];
        }

        // Strip surrounding quotes too; Docker Compose can pass a quoted .env
        // value through to the container with the quotes intact.
        return array_values(array_filter(array_map(
            static fn (string $name): string => mb_strtolower(trim($name, " \t\n\r\0\x0B\"'")),
            explode(',', $raw)
        )));
    }

    private function cacheContextFingerprint(): string
    {
        $repository = $this->repository ?? new PlayHistoryRepository();

        return hash('sha256', json_encode([
            'timezone' => date_default_timezone_get(),
            'excludedLibraries' => $this->excludedLibraries(),
            'excludedUsers' => (new MonitoringExclusions())->fingerprint(),
            'themeClassificationContext' => $repository->themePlaybackExclusions()->fingerprint(),
        ], JSON_THROW_ON_ERROR));
    }

    private function poster(string $itemId, bool $isEpisode): string
    {
        $gradient = 'linear-gradient(160deg,#241b3d,#0c0b13)';

        if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
            return $gradient;
        }

        $url = '/api/image.php?item=' . rawurlencode($itemId) . '&type=Primary&maxWidth=320';
        if ($isEpisode) {
            $url .= '&kind=series';
        }

        return 'url("' . $url . '"), ' . $gradient;
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    private function previousRows(PlayHistoryRepository $repository, string $range, \DateTimeImmutable $now): array
    {
        $period = StatisticsPeriod::previous($range, $now);
        if ($period === null) {
            return [];
        }

        return $repository->statisticsRowsForPeriod($period['start'], $period['end']);
    }

    private function historyUrl(
        string $range,
        ?\DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        ?string $user = null,
        ?string $search = null,
        ?string $client = null,
        ?string $method = null,
    ): string {
        $query = [];

        if ($search !== null) {
            $query['search'] = $search;
        }

        if ($user !== null) {
            $query['user'] = $user;
        }

        if ($client !== null) {
            $query['client'] = $client;
        }

        if ($method !== null) {
            $query['method'] = $method;
        }

        if ($range === 'all') {
            $query['range'] = 'all';
        } elseif ($start !== null && $end !== null) {
            $query['range'] = 'custom';
            $query['start'] = $start->format('Y-m-d');
            $query['end'] = $end->format('Y-m-d');
        }

        return '/history?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $clients
     * @return array<string, mixed>
     */
    private function clientHistoryLinks(
        array $clients,
        string $range,
        ?\DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
    ): array {
        foreach (['breakdown', 'ranked', 'usage'] as $group) {
            foreach ($clients[$group] as $index => $item) {
                $clients[$group][$index]['href'] = $this->historyUrl(
                    $range,
                    $start,
                    $end,
                    client: (string) $item['name'],
                );
            }
        }

        foreach ($clients['transcode'] as $index => $item) {
            $client = (string) $item['name'];
            $clients['transcode'][$index]['href'] = $this->historyUrl(
                $range,
                $start,
                $end,
                client: $client,
            );
            $clients['transcode'][$index]['directHref'] = $this->historyUrl(
                $range,
                $start,
                $end,
                client: $client,
                method: 'direct',
            );
            $clients['transcode'][$index]['transcodeHref'] = $this->historyUrl(
                $range,
                $start,
                $end,
                client: $client,
                method: 'transcode',
            );
        }

        return $clients;
    }

    /**
     * @return array<int, array{key: string, label: string, href: string, active: bool}>
     */
    private function ranges(string $active): array
    {
        $ranges = [];
        foreach (self::RANGES as $key => $range) {
            $ranges[] = [
                'key' => $key,
                'label' => (string) $range['label'],
                'href' => '/statistics?range=' . $key,
                'active' => $key === $active,
            ];
        }

        return $ranges;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, mixed>>
     */
    private function users(array $rows): array
    {
        $users = [];

        foreach ($rows as $row) {
            $name = (string) ($row['user_name'] ?? '');
            $key = $name !== '' ? 'named:' . $name : 'anonymous:';
            $users[$key] ??= [
                'user' => $name !== '' ? $name : 'Unknown user',
                'user_id' => trim((string) ($row['user_id'] ?? '')),
                'sec' => 0,
                'plays' => 0,
                'filterable' => $name !== '',
            ];
            $users[$key]['filterable'] = (bool) $users[$key]['filterable'] && $name !== '';
            if ($users[$key]['user_id'] === '') {
                $users[$key]['user_id'] = trim((string) ($row['user_id'] ?? ''));
            }
            $users[$key]['sec'] += $this->viewingSeconds($row);
            $users[$key]['plays']++;
        }

        uasort($users, static fn (array $a, array $b): int => $b['sec'] <=> $a['sec']);
        $max = $this->maxInt(array_map(static fn (array $user): int => (int) $user['sec'], $users));
        $sharePercentages = $this->wholePercentages(array_map(
            static fn (array $user): int => (int) $user['sec'],
            $users,
        ));
        $index = 0;

        $result = [];
        foreach ($users as $groupKey => $user) {
            $color = self::COLORS[$index % count(self::COLORS)];
            $index++;
            $seconds = (int) $user['sec'];
            $avgMinutes = (int) round(($seconds / max(1, (int) $user['plays'])) / 60);
            $avatarBg = 'linear-gradient(135deg,' . $color . ',#3b9eff)';

            $result[] = [
                'user' => $user['user'],
                'initials' => $this->initials((string) $user['user']),
                'avatarBg' => $avatarBg,
                'avatarUrl' => $this->avatars()->url((string) $user['user_id']) ?? '',
                'color' => $color,
                'watch' => $this->duration($seconds),
                'plays' => $this->comma((int) $user['plays']),
                'avg' => $this->duration($avgMinutes * 60),
                'w' => (int) round(($seconds / $max) * 100) . '%',
                'share' => ($sharePercentages[$groupKey] ?? 0) . '%',
                'filterable' => (bool) $user['filterable'],
            ];
        }

        return $result;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, mixed>
     */
    private function clients(array $rows): array
    {
        $clients = [];

        foreach ($rows as $row) {
            $name = (string) ($row['client'] ?? 'Unknown client');
            $key = $name !== '' ? $name : 'Unknown client';
            $clients[$key] ??= ['name' => $key, 'sessions' => 0, 'transcodes' => 0, 'sec' => 0];
            $clients[$key]['sessions']++;
            $clients[$key]['sec'] += $this->viewingSeconds($row);
            if ((string) $row['play_method'] === 'Transcode') {
                $clients[$key]['transcodes']++;
            }
        }

        uasort($clients, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
        $maxSessions = $this->maxInt(array_map(static fn (array $client): int => (int) $client['sessions'], $clients));
        $maxSeconds = $this->maxInt(array_map(static fn (array $client): int => (int) $client['sec'], $clients));
        $sessionPercentages = $this->wholePercentages(array_map(
            static fn (array $client): int => (int) $client['sessions'],
            $clients,
        ));

        $breakdown = [];
        $ranked = [];
        $transcode = [];
        $usage = [];
        $conicSegments = [];
        $index = 0;

        foreach ($clients as $clientKey => $client) {
            $color = self::COLORS[$index % count(self::COLORS)];
            $sessions = (int) $client['sessions'];
            $transcodePct = self::standalonePercentage((int) $client['transcodes'], $sessions);
            $sharePct = $sessionPercentages[$clientKey] ?? 0;
            $conicSegments[] = ['pct' => $sharePct, 'color' => $color];

            $breakdown[] = ['name' => $client['name'], 'color' => $color, 'pct' => $sharePct . '%', 'sessions' => $this->comma($sessions)];
            $ranked[] = [
                'rank' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'name' => $client['name'],
                'color' => $color,
                'sessions' => $this->comma($sessions),
                'w' => (int) round(($sessions / $maxSessions) * 100) . '%',
            ];
            $transcode[] = [
                'name' => $client['name'],
                'color' => $color,
                'transcodeVal' => $transcodePct . '% transcoded',
                'directPct' => (100 - $transcodePct) . '%',
                'transcodePctStr' => $transcodePct . '%',
            ];
            $usage[] = [
                'name' => $client['name'],
                'color' => $color,
                'watch' => $this->duration((int) $client['sec']),
                'w' => (int) round(((int) $client['sec'] / $maxSeconds) * 100) . '%',
            ];
            $index++;
        }

        return [
            'conic' => $conicSegments === [] ? 'conic-gradient(rgba(255,255,255,.08) 0% 100%)' : $this->conic($conicSegments),
            'breakdown' => $breakdown,
            'ranked' => $ranked,
            'transcode' => $transcode,
            'usage' => $usage,
        ];
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, mixed>
     */
    private function directness(array $rows): array
    {
        $direct = 0;
        $stream = 0;
        $transcode = 0;

        foreach ($rows as $row) {
            $method = (string) $row['play_method'];
            if ($method === 'Transcode') {
                $transcode++;
            } elseif ($method === 'DirectStream') {
                $stream++;
            } else {
                $direct++;
            }
        }

        $sessionCount = $direct + $stream + $transcode;
        $percentages = $this->wholePercentages([
            'direct' => $direct,
            'stream' => $stream,
            'transcode' => $transcode,
        ]);
        $directPct = $percentages['direct'];
        $streamPct = $percentages['stream'];
        $transcodePct = $percentages['transcode'];

        return [
            'direct_pct' => $directPct,
            'transcode_count' => $transcode,
            'transcode_pct' => $transcodePct,
            'conic' => $sessionCount > 0
                ? $this->conic([
                    ['pct' => $directPct, 'color' => '#34d8a6'],
                    ['pct' => $streamPct, 'color' => '#3b9eff'],
                    ['pct' => $transcodePct, 'color' => '#f7b955'],
                ])
                : 'conic-gradient(rgba(255,255,255,.08) 0% 100%)',
            'legend' => [
                ['label' => 'Direct Play', 'method' => 'direct-play', 'color' => '#34d8a6', 'pct' => $directPct . '%'],
                ['label' => 'Direct Stream', 'method' => 'direct-stream', 'color' => '#3b9eff', 'pct' => $streamPct . '%'],
                ['label' => 'Transcode', 'method' => 'transcode', 'color' => '#f7b955', 'pct' => $transcodePct . '%'],
            ],
        ];
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, int>
     */
    private function counts(array $rows, string $column): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value === '') {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, int>
     */
    private function reasonCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $encoded = (string) ($row['transcode_reasons'] ?? '');
            if ($encoded === '') {
                continue;
            }

            $decoded = json_decode($encoded, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $reason) {
                $label = trim((string) $reason);
                if ($label !== '') {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     */
    private function reasonSessionCount(array $rows): int
    {
        $sessions = 0;

        foreach ($rows as $row) {
            if ((string) ($row['play_method'] ?? '') !== 'Transcode') {
                continue;
            }

            $decoded = json_decode((string) ($row['transcode_reasons'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $reason) {
                if (trim((string) $reason) !== '') {
                    $sessions++;
                    break;
                }
            }
        }

        return $sessions;
    }

    private function codecCoverage(int $covered, int $total): string
    {
        if ($total <= 0) {
            return 'no session data';
        }

        return $covered . ' of ' . $total . ' ' . ($total === 1 ? 'session' : 'sessions') . ' with codec data';
    }

    private function reasonCoverage(int $covered, int $total): string
    {
        if ($total <= 0) {
            return 'no transcodes';
        }

        return $covered . ' of ' . $total . ' ' . ($total === 1 ? 'transcode' : 'transcodes') . ' with reason data';
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array{name: string, color: string, pct: string, w: string, count: int}>
     */
    private function bars(array $counts, string $otherLabel = 'Other'): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return [];
        }

        if (count($counts) > 7) {
            $visible = array_slice($counts, 0, 6, true);
            $other = array_sum(array_slice($counts, 6, null, true));
            $visible[$otherLabel] = ($visible[$otherLabel] ?? 0) + $other;
            $counts = $visible;
        }

        $percentages = $this->wholePercentages($counts);
        $max = max($counts);
        $bars = [];
        $index = 0;

        foreach ($counts as $name => $count) {
            $bars[] = [
                'name' => (string) $name,
                'color' => self::COLORS[$index % count(self::COLORS)],
                'pct' => ($percentages[$name] ?? 0) . '%',
                'w' => (int) round(($count / $max) * 100) . '%',
                'count' => $count,
            ];
            $index++;
        }

        return $bars;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, string>>
     */
    private function trend(array $rows, string $range, \DateTimeImmutable $now): array
    {
        if ($range === 'year') {
            return $this->monthTrend($rows, $now);
        }

        if ($range === 'all') {
            return $this->yearTrend($rows);
        }

        return $this->dayTrend($rows, $range === 'week' ? 7 : 30, $now);
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, string>>
     */
    private function dayTrend(array $rows, int $days, \DateTimeImmutable $now): array
    {
        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $now->modify('-' . $i . ' days');
            $buckets[$day->format('Y-m-d')] = ['label' => $days === 7 ? $day->format('D') : $day->format('M j'), 'sec' => 0];
        }

        foreach ($rows as $row) {
            $key = (new \DateTimeImmutable((string) $row['started_at']))->format('Y-m-d');
            if (isset($buckets[$key])) {
                $buckets[$key]['sec'] += $this->viewingSeconds($row);
            }
        }

        return $this->trendBars($buckets);
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, string>>
     */
    private function monthTrend(array $rows, \DateTimeImmutable $now): array
    {
        $buckets = [];
        $monthAnchor = $now->setTime(0, 0)->modify('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $month = $monthAnchor->modify('-' . $i . ' months');
            $buckets[$month->format('Y-m')] = ['label' => $month->format('M'), 'sec' => 0];
        }

        foreach ($rows as $row) {
            $key = (new \DateTimeImmutable((string) $row['started_at']))->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['sec'] += $this->viewingSeconds($row);
            }
        }

        return $this->trendBars($buckets);
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, string>>
     */
    private function yearTrend(array $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $year = (new \DateTimeImmutable((string) $row['started_at']))->format('Y');
            $buckets[$year] ??= ['label' => $year, 'sec' => 0];
            $buckets[$year]['sec'] += $this->viewingSeconds($row);
        }

        ksort($buckets);

        return $this->trendBars($buckets);
    }

    /**
     * @param array<string, array{label: string, sec: int}> $buckets
     * @return array<int, array<string, string>>
     */
    private function trendBars(array $buckets): array
    {
        $max = $this->maxInt(array_map(static fn (array $bucket): int => $bucket['sec'], $buckets));

        return array_values(array_map(fn (array $bucket): array => [
            'label' => $bucket['label'],
            'h' => $bucket['sec'] > 0
                ? max(4, (int) round(($bucket['sec'] / $max) * 100)) . '%'
                : '0%',
            'value' => $this->duration($bucket['sec']),
        ], $buckets));
    }

    private function trendUnit(string $range): string
    {
        return match ($range) {
            'month' => 'by day - last 30 days',
            'year' => 'by month - last 12 months',
            'all' => 'by year - all time',
            default => 'by day - last 7 days',
        };
    }

    /**
     * @param array<int|string, int> $values
     */
    private function maxInt(array $values): int
    {
        return max([1, ...array_values($values)]);
    }

    /**
     * Allocate rounded whole percentages without letting the displayed total
     * drift below or above 100.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function wholePercentages(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return array_fill_keys(array_keys($counts), 0);
        }

        $percentages = [];
        $fractions = [];
        foreach ($counts as $key => $count) {
            $exact = (max(0, $count) / $total) * 100;
            $percentages[$key] = (int) floor($exact);
            $fractions[$key] = $exact - $percentages[$key];
        }

        arsort($fractions);
        $remaining = 100 - array_sum($percentages);
        foreach (array_keys($fractions) as $key) {
            if ($remaining <= 0) {
                break;
            }
            $percentages[$key]++;
            $remaining--;
        }

        return $percentages;
    }

    /**
     * @param array<int, array{pct: int|float, color: string}> $segments
     */
    private function conic(array $segments): string
    {
        $start = 0.0;
        $parts = [];

        foreach ($segments as $segment) {
            $end = $start + (float) $segment['pct'];
            $parts[] = $segment['color'] . ' ' . number_format($start, 2, '.', '') . '% ' . number_format($end, 2, '.', '') . '%';
            $start = $end;
        }

        return 'conic-gradient(' . implode(', ', $parts) . ')';
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     */
    private function sumViewingSeconds(array $rows): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += $this->viewingSeconds($row);
        }

        return $sum;
    }

    private function viewingSeconds(\Dibi\Row $row): int
    {
        $data = $row->toArray();

        return max(0, (int) ($data['watch_duration_sec'] ?? $data['watched_sec'] ?? 0));
    }

    /** @param array<int, \Dibi\Row> $rows */
    private function hasEstimatedViewingTime(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row->toArray()['watch_duration_sec'] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{text: string, color: string}
     */
    private function delta(int $current, int $previous, string $range, string $label): array
    {
        if ($range === 'all') {
            return ['text' => $label === 'plays' ? 'lifetime sessions' : 'lifetime total', 'color' => 'rgba(255,255,255,0.42)'];
        }

        if ($current <= 0 && $previous <= 0) {
            return ['text' => 'no activity this period', 'color' => 'rgba(255,255,255,0.42)'];
        }

        if ($previous <= 0) {
            return ['text' => 'new this period', 'color' => '#46e0b0'];
        }

        $pct = (int) round((($current - $previous) / $previous) * 100);
        $prefix = $pct >= 0 ? '+' : '';
        $period = self::RANGES[$range]['label'];

        return ['text' => $prefix . $pct . '% vs previous ' . strtolower((string) $period), 'color' => $pct >= 0 ? '#46e0b0' : '#f7b955'];
    }

    /**
     * @return array{text: string, color: string}
     */
    private function rateDelta(int $current, ?int $previous, string $range): array
    {
        if ($range === 'all') {
            return ['text' => 'lifetime mix', 'color' => 'rgba(255,255,255,0.42)'];
        }

        if ($previous === null) {
            return ['text' => 'no previous data', 'color' => 'rgba(255,255,255,0.42)'];
        }

        $diff = $current - $previous;
        if ($diff === 0) {
            return ['text' => 'unchanged', 'color' => 'rgba(255,255,255,0.42)'];
        }

        return ['text' => ($diff > 0 ? '+' : '') . $diff . ' pts vs previous', 'color' => $diff <= 0 ? '#46e0b0' : '#f7b955'];
    }

    /**
     * @param array{text: string, color: string} $delta
     * @return array<string, string>
     */
    private function kpi(string $label, string $color, string $value, array $delta, ?string $href = null): array
    {
        $kpi = [
            'label' => $label,
            'color' => $color,
            'value' => $value,
            'delta' => $delta['text'],
            'deltaColor' => $delta['color'],
        ];

        if ($href !== null) {
            $kpi['href'] = $href;
        }

        return $kpi;
    }

    public static function formatDuration(int $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        if ($minutes <= 0) {
            return $seconds > 0 ? '<1m' : '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0
            ? number_format($hours) . 'h ' . $remainingMinutes . 'm'
            : $remainingMinutes . 'm';
    }

    public static function standalonePercentage(int $part, int $total): int
    {
        return $total > 0 ? (int) round((max(0, $part) / $total) * 100) : 0;
    }

    private function duration(int $seconds): string
    {
        return self::formatDuration($seconds);
    }

    private function comma(int $value): string
    {
        return number_format($value);
    }

    private function avatars(): JellyfinUserAvatars
    {
        return $this->avatars ??= new JellyfinUserAvatars();
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return mb_substr($letters !== '' ? $letters : 'U', 0, 2);
    }
}
