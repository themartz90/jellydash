<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;

final class PlaybackStatisticsService
{
    private const RANGES = [
        'week' => ['label' => 'Week', 'sub' => 'Last 7 days', 'days' => 7],
        'month' => ['label' => 'Month', 'sub' => 'Last 30 days', 'days' => 30],
        'year' => ['label' => 'Year', 'sub' => 'Last 12 months', 'days' => 365],
        'all' => ['label' => 'All time', 'sub' => 'All recorded history', 'days' => null],
    ];

    private const COLORS = ['#7c5cff', '#34d8a6', '#3b9eff', '#f7b955', '#ff6b9d', '#f0913a', '#6f7bff', '#c44fff'];

    public function __construct(
        private ?PlayHistoryRepository $repository = null,
        private ?JellyfinClient $client = null,
    ) {
    }

    /** @var array<string, string> Jellyfin item-path lookups, shared across the strips on one render. */
    private array $pathCache = [];

    /** @var array<string, array<int, string>>|null Excluded-library locations, fetched once per render. */
    private ?array $locationsCache = null;

    private ?JellyfinUserAvatars $avatars = null;

    /**
     * @return array<string, mixed>
     */
    public function data(string $range, ?\DateTimeImmutable $now = null): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : 'week';
        $now ??= new \DateTimeImmutable('now');
        $repository = $this->repository ?? new PlayHistoryRepository();
        $rows = $repository->statisticsRows($range, $now);
        $previousRows = $this->previousRows($repository, $range, $now);

        $users = $this->users($rows);
        $clients = $this->clients($rows);
        $directness = $this->directness($rows);
        $codecs = $this->bars($this->counts($rows, 'source_video_codec'));
        $reasons = $this->bars($this->reasonCounts($rows));
        $watchSeconds = $this->sum($rows, 'watched_sec');
        $previousWatchSeconds = $this->sum($previousRows, 'watched_sec');
        $plays = count($rows);
        $previousPlays = count($previousRows);
        $transcodeRate = $plays > 0 ? (int) round(($directness['transcode_count'] / $plays) * 100) : 0;
        $previousDirectness = $this->directness($previousRows);
        $previousTranscodeRate = count($previousRows) > 0 ? (int) round(($previousDirectness['transcode_count'] / count($previousRows)) * 100) : null;
        $trending = $this->trending($rows);
        $mostWatched = $this->mostWatched($repository, $range, $rows);

        return [
            'range' => $range,
            'ranges' => $this->ranges($range),
            'subLabel' => self::RANGES[$range]['sub'] . ' - all libraries',
            'trending' => $trending,
            'hasTrending' => $trending !== [],
            'mostWatched' => $mostWatched,
            'hasMostWatched' => $mostWatched['series'] !== [] || $mostWatched['movies'] !== [],
            'kpis' => [
                $this->kpi('Total Watch Time', '#7c5cff', $this->duration($watchSeconds), $this->delta($watchSeconds, $previousWatchSeconds, $range, 'watch time')),
                $this->kpi('Total Plays', '#3b9eff', $this->comma($plays), $this->delta($plays, $previousPlays, $range, 'plays')),
                $this->kpi('Active Users', '#34d8a6', (string) count($users), ['text' => 'unique viewers', 'color' => 'rgba(255,255,255,0.42)']),
                $this->kpi('Transcode Rate', '#f7b955', $transcodeRate . '%', $this->rateDelta($transcodeRate, $previousTranscodeRate, $range)),
            ],
            'totalWatch' => $this->duration($watchSeconds),
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
            'reasons' => $reasons,
            'hasReasonData' => $reasons !== [],
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
     * @return array<int, array<string, mixed>>
     */
    private function trending(array $rows): array
    {
        $items = $this->titleCards($this->groupTitles($rows));

        usort($items, static fn (array $a, array $b): int => [$b['users'], $b['plays']] <=> [$a['users'], $a['plays']]);

        return $this->withoutExcludedLibraries($items);
    }

    /**
     * All-time favourites: Trending's lifetime complement. Ranked by play
     * count (viewers as tiebreak, the reverse of Trending's ordering) and split
     * into series and movies, so both media kinds get their own podium. Always
     * computed over all recorded history regardless of the selected range.
     *
     * @param array<int, \Dibi\Row> $rangeRows rows already fetched for the page's range
     * @return array{series: array<int, array<string, mixed>>, movies: array<int, array<string, mixed>>}
     */
    private function mostWatched(PlayHistoryRepository $repository, string $range, array $rangeRows): array
    {
        // The 'all' range already fetched the full table; don't fetch it twice.
        $rows = $range === 'all' ? $rangeRows : $repository->statisticsRowsForPeriod(null, null);
        $groups = $this->groupTitles($rows);

        $series = $this->titleCards(array_filter($groups, static fn (array $g): bool => (bool) $g['isEpisode']));
        $movies = $this->titleCards(array_filter(
            $groups,
            static fn (array $g): bool => !$g['isEpisode'] && (string) $g['type'] === 'Movie'
        ));

        $byPlays = static fn (array $a, array $b): int => [$b['plays'], $b['users']] <=> [$a['plays'], $a['users']];
        usort($series, $byPlays);
        usort($movies, $byPlays);

        return [
            'series' => $this->withoutExcludedLibraries($series),
            'movies' => $this->withoutExcludedLibraries($movies),
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
            $key = ($isEpisode ? 'series:' : 'item:') . mb_strtolower($title);

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
                ];
            }

            $groups[$key]['plays']++;
            $groups[$key]['watched'] += (int) $row['watched_sec'];

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
    private function titleCards(array $groups): array
    {
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
                'href' => '/history?search=' . rawurlencode((string) $group['title']),
            ];
        }

        return $items;
    }

    /**
     * Drop trending entries that live in an excluded Jellyfin library
     * (TRENDING_EXCLUDE_LIBRARIES). Resolves the real library lazily for just
     * enough top entries to fill the strip, and fails open if Jellyfin is
     * unreachable so the section never breaks.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function withoutExcludedLibraries(array $items): array
    {
        $excluded = $this->excludedLibraries();
        if ($excluded === []) {
            return array_slice($items, 0, 6);
        }

        $client = $this->client ?? new JellyfinClient();

        // Physical folder prefixes of the excluded libraries. Fetched once and
        // reused: Trending and both Most Watched strips filter on one render.
        if ($this->locationsCache === null) {
            try {
                $this->locationsCache = $client->libraryLocations();
            } catch (\Throwable $e) {
                return array_slice($items, 0, 6); // fail open
            }
        }
        $locations = $this->locationsCache;

        $prefixes = [];
        foreach ($excluded as $name) {
            foreach ($locations[$name] ?? [] as $location) {
                $location = rtrim($location, '/');
                if ($location !== '') {
                    $prefixes[] = $location;
                }
            }
        }

        if ($prefixes === []) {
            return array_slice($items, 0, 6); // no matching libraries
        }

        $kept = [];
        $lookupFailed = false;

        foreach ($items as $item) {
            if (count($kept) >= 6) {
                break;
            }

            if (!$lookupFailed) {
                try {
                    // Same titles surface in several strips, so look each item up once.
                    $itemId = (string) $item['itemId'];
                    $path = $this->pathCache[$itemId] ??= $client->itemPath($itemId);
                } catch (\Throwable $e) {
                    // Jellyfin outage: stop probing and keep the rest so the
                    // strip never breaks.
                    $lookupFailed = true;
                    $kept[] = $item;
                    continue;
                }

                // Drop items that live in an excluded library, and items that no
                // longer resolve at all: a deleted/temporary item has neither a
                // path nor cover art, so it shouldn't headline Trending.
                if ($path === '' || $this->pathInLibraries($path, $prefixes)) {
                    continue;
                }
            }

            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * @param array<int, string> $prefixes
     */
    private function pathInLibraries(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
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
        $days = self::RANGES[$range]['days'];
        if ($days === null) {
            return [];
        }

        $end = $now->modify('-' . $days . ' days');
        $start = $now->modify('-' . ($days * 2) . ' days');

        return $repository->statisticsRowsForPeriod($start, $end);
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
            $name = (string) ($row['user_name'] ?? 'Unknown user');
            $key = $name !== '' ? $name : 'Unknown user';
            $users[$key] ??= [
                'user' => $key,
                'user_id' => trim((string) ($row['user_id'] ?? '')),
                'min' => 0,
                'plays' => 0,
            ];
            if ($users[$key]['user_id'] === '') {
                $users[$key]['user_id'] = trim((string) ($row['user_id'] ?? ''));
            }
            $users[$key]['min'] += (int) floor(((int) $row['watched_sec']) / 60);
            $users[$key]['plays']++;
        }

        uasort($users, static fn (array $a, array $b): int => $b['min'] <=> $a['min']);
        $max = $this->maxInt(array_map(static fn (array $user): int => (int) $user['min'], $users));
        $index = 0;

        return array_values(array_map(function (array $user) use ($max, &$index): array {
            $color = self::COLORS[$index % count(self::COLORS)];
            $index++;
            $avg = (int) round((int) $user['min'] / max(1, (int) $user['plays']));
            $avatarBg = 'linear-gradient(135deg,' . $color . ',#3b9eff)';

            return [
                'user' => $user['user'],
                'initials' => $this->initials((string) $user['user']),
                'avatarBg' => $avatarBg,
                'avatarUrl' => $this->avatars()->url((string) $user['user_id']) ?? '',
                'color' => $color,
                'watch' => $this->duration(((int) $user['min']) * 60),
                'plays' => $this->comma((int) $user['plays']),
                'avg' => $this->duration($avg * 60),
                'w' => (int) round(((int) $user['min'] / $max) * 100) . '%',
            ];
        }, $users));
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
            $clients[$key] ??= ['name' => $key, 'sessions' => 0, 'transcodes' => 0, 'min' => 0];
            $clients[$key]['sessions']++;
            $clients[$key]['min'] += (int) floor(((int) $row['watched_sec']) / 60);
            if ((string) $row['play_method'] === 'Transcode') {
                $clients[$key]['transcodes']++;
            }
        }

        uasort($clients, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
        $maxSessions = $this->maxInt(array_map(static fn (array $client): int => (int) $client['sessions'], $clients));
        $maxMin = $this->maxInt(array_map(static fn (array $client): int => (int) $client['min'], $clients));
        $totalSessions = max(1, array_sum(array_map(static fn (array $client): int => (int) $client['sessions'], $clients)));

        $breakdown = [];
        $ranked = [];
        $transcode = [];
        $usage = [];
        $conicSegments = [];
        $index = 0;

        foreach ($clients as $client) {
            $color = self::COLORS[$index % count(self::COLORS)];
            $sessions = (int) $client['sessions'];
            $transcodePct = (int) round(((int) $client['transcodes'] / $sessions) * 100);
            $sharePct = (int) round(($sessions / $totalSessions) * 100);
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
                'watch' => $this->duration(((int) $client['min']) * 60),
                'w' => (int) round(((int) $client['min'] / $maxMin) * 100) . '%',
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

        $total = max(1, $direct + $stream + $transcode);
        $directPct = (int) round(($direct / $total) * 100);
        $streamPct = (int) round(($stream / $total) * 100);
        $transcodePct = max(0, 100 - $directPct - $streamPct);

        return [
            'direct_pct' => $directPct,
            'transcode_count' => $transcode,
            'conic' => $this->conic([
                ['pct' => $directPct, 'color' => '#34d8a6'],
                ['pct' => $streamPct, 'color' => '#3b9eff'],
                ['pct' => $transcodePct, 'color' => '#f7b955'],
            ]),
            'legend' => [
                ['label' => 'Direct Play', 'color' => '#34d8a6', 'pct' => $directPct . '%'],
                ['label' => 'Direct Stream', 'color' => '#3b9eff', 'pct' => $streamPct . '%'],
                ['label' => 'Transcode', 'color' => '#f7b955', 'pct' => $transcodePct . '%'],
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
     * @param array<string, int> $counts
     * @return array<int, array<string, string>>
     */
    private function bars(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return [];
        }

        $max = max($counts);
        $bars = [];
        $index = 0;

        foreach (array_slice($counts, 0, 7, true) as $name => $count) {
            $bars[] = [
                'name' => (string) $name,
                'color' => self::COLORS[$index % count(self::COLORS)],
                'pct' => (int) round(($count / $total) * 100) . '%',
                'w' => (int) round(($count / $max) * 100) . '%',
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
                $buckets[$key]['sec'] += (int) $row['watched_sec'];
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
        for ($i = 11; $i >= 0; $i--) {
            $month = $now->modify('-' . $i . ' months');
            $buckets[$month->format('Y-m')] = ['label' => $month->format('M'), 'sec' => 0];
        }

        foreach ($rows as $row) {
            $key = (new \DateTimeImmutable((string) $row['started_at']))->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['sec'] += (int) $row['watched_sec'];
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
            $buckets[$year]['sec'] += (int) $row['watched_sec'];
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

        return array_values(array_map(static fn (array $bucket): array => [
            'label' => $bucket['label'],
            'h' => max(4, (int) round(($bucket['sec'] / $max) * 100)) . '%',
        ], $buckets));
    }

    private function trendUnit(string $range): string
    {
        return match ($range) {
            'month' => 'by day - this month',
            'year' => 'by month - this year',
            'all' => 'by year - all time',
            default => 'Mon-Sun - this week',
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
    private function sum(array $rows, string $column): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) ($row[$column] ?? 0);
        }

        return $sum;
    }

    /**
     * @return array{text: string, color: string}
     */
    private function delta(int $current, int $previous, string $range, string $label): array
    {
        if ($range === 'all') {
            return ['text' => $label === 'plays' ? 'lifetime sessions' : 'lifetime total', 'color' => 'rgba(255,255,255,0.42)'];
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
    private function kpi(string $label, string $color, string $value, array $delta): array
    {
        return [
            'label' => $label,
            'color' => $color,
            'value' => $value,
            'delta' => $delta['text'],
            'deltaColor' => $delta['color'],
        ];
    }

    private function duration(int $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours >= 100) {
            return $this->comma($hours) . 'h';
        }

        return $hours > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $remainingMinutes . 'm';
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
                $letters .= strtoupper(substr($part, 0, 1));
            }
        }

        return substr($letters !== '' ? $letters : 'U', 0, 2);
    }
}
