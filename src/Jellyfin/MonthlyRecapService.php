<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;

final class MonthlyRecapService
{
    private const MAX_MONTH_OPTIONS = 240;

    public function __construct(
        private ?PlayHistoryRepository $repository = null,
        private ?JellyfinClient $client = null,
    ) {
    }

    /**
     * @return array{recap: array<string, mixed>, months: array<string, string>, viewer: string, viewerOptions: array<string, string>}
     */
    public function data(
        ?string $month = null,
        ?string $viewer = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $now = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $currentMonth = $now->modify('first day of this month')->setTime(0, 0);
        $defaultMonth = $currentMonth->modify('-1 month');
        $start = $this->validCompletedMonth($month, $currentMonth) ?? $defaultMonth;
        $end = $start->modify('+1 month');
        $repository = $this->repository ?? new PlayHistoryRepository();

        // Fetch the whole month before applying a viewer filter. This keeps the
        // available viewer choices stable while switching between accounts.
        $monthRows = $repository->statisticsRowsForPeriod($start, $end);
        $viewerOptions = ['' => 'All viewers'];
        foreach ($repository->users() as $name) {
            if ($name !== '') {
                $viewerOptions[$name] = $name;
            }
        }
        if ($viewer === null || !array_key_exists($viewer, $viewerOptions)) {
            $viewer = '';
        }
        $rows = $viewer === ''
            ? $monthRows
            : array_values(array_filter(
                $monthRows,
                static fn (\Dibi\Row $row): bool => (string) ($row['user_name'] ?? '') === $viewer,
            ));

        return [
            'recap' => $this->recap($rows, $start, $viewer, $timezone),
            'months' => $this->months($repository, $start, $defaultMonth),
            'viewer' => $viewer,
            'viewerOptions' => $viewerOptions,
        ];
    }

    private function validCompletedMonth(
        ?string $value,
        \DateTimeImmutable $currentMonth,
    ): ?\DateTimeImmutable {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})$/D', $value, $matches)) {
            return null;
        }
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        if ($year < 1 || !checkdate($month, 1, $year)) {
            return null;
        }

        $date = $currentMonth->setDate($year, $month, 1);

        return $date < $currentMonth ? $date : null;
    }

    /** @return array<string, string> */
    private function months(
        PlayHistoryRepository $repository,
        \DateTimeImmutable $selected,
        \DateTimeImmutable $latest,
    ): array {
        $first = $repository->firstStatisticsStartedAt()?->modify('first day of this month')->setTime(0, 0);
        $minimum = $latest->modify('-' . (self::MAX_MONTH_OPTIONS - 1) . ' months');
        if ($first !== null && $first <= $latest && $first > $minimum) {
            $minimum = $first;
        }
        if ($first === null || $first > $latest) {
            $minimum = $latest;
        }

        $months = [];
        for ($cursor = $latest; $cursor >= $minimum; $cursor = $cursor->modify('-1 month')) {
            $months[$cursor->format('Y-m')] = $cursor->format('F Y');
        }
        $selectedKey = $selected->format('Y-m');
        if (!isset($months[$selectedKey])) {
            $months[$selectedKey] = $selected->format('F Y');
        }

        return $months;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, mixed>
     */
    private function recap(
        array $rows,
        \DateTimeImmutable $start,
        string $viewer,
        \DateTimeZone $timezone,
    ): array {
        $days = [];
        $dayCount = (int) $start->format('t');
        for ($day = 1; $day <= $dayCount; ++$day) {
            $days[$day] = [
                'day' => $day,
                'minutes' => 0,
                'seconds' => 0,
                'height' => 0.0,
                'duration' => 'No recorded viewing',
                'known' => false,
            ];
        }

        $totalSeconds = 0;
        $estimatedSeconds = 0;
        $otherSeconds = 0;
        $otherPlays = 0;
        $viewers = [];
        foreach ($rows as $row) {
            $seconds = $this->viewingSeconds($row);
            $totalSeconds += $seconds;
            if ($row['watch_duration_sec'] === null) {
                $estimatedSeconds += $seconds;
            }

            $startedAt = (string) ($row['started_at'] ?? '');
            $day = (int) substr($startedAt, 8, 2);
            if (isset($days[$day])) {
                $days[$day]['seconds'] += $seconds;
                $days[$day]['known'] = true;
            }

            $rawName = (string) ($row['user_name'] ?? '');
            $missingName = trim($rawName) === '';
            $viewerKey = $missingName ? "missing\0" : "named\0" . $rawName;
            $viewers[$viewerKey] ??= [
                'name' => $missingName ? 'Unknown viewer' : $rawName,
                'seconds' => 0,
                'plays' => 0,
            ];
            $viewers[$viewerKey]['seconds'] += $seconds;
            ++$viewers[$viewerKey]['plays'];

            if (!$this->isMovieOrEpisode((string) ($row['item_type'] ?? ''))) {
                $otherSeconds += $seconds;
                ++$otherPlays;
            }
        }

        $peakSeconds = max(array_column($days, 'seconds'));
        $activeDays = 0;
        foreach ($days as &$day) {
            if ($day['known']) {
                ++$activeDays;
                $day['minutes'] = (int) round($day['seconds'] / 60);
                $day['duration'] = $this->duration((int) $day['seconds']);
                $day['height'] = $peakSeconds > 0
                    ? round(((int) $day['seconds'] / $peakSeconds) * 100, 2)
                    : 0.0;
            }
        }
        unset($day);

        $rankingRows = $this->rankingRows($rows);
        $groups = $this->titleGroups($rankingRows);
        $movies = array_values(array_filter($groups, static fn (array $group): bool => $group['type'] === 'Movie'));
        $series = array_values(array_filter($groups, static fn (array $group): bool => $group['type'] === 'Series'));

        $viewerRows = array_values($viewers);
        usort($viewerRows, $this->rankRows(...));
        foreach ($viewerRows as &$person) {
            $person['duration'] = $this->duration((int) $person['seconds']);
            $person['percent'] = $totalSeconds > 0
                ? (int) round(((int) $person['seconds'] / $totalSeconds) * 100)
                : 0;
            unset($person['seconds']);
        }
        unset($person);

        $bestDay = null;
        if ($peakSeconds > 0) {
            foreach ($days as $day) {
                if ($day['seconds'] === $peakSeconds) {
                    $bestDay = $start->setDate(
                        (int) $start->format('Y'),
                        (int) $start->format('m'),
                        (int) $day['day'],
                    )->format('j F');
                    break;
                }
            }
        }

        $rankingNote = $this->excludedLibraries() === []
            ? ''
            : 'Movie and series rankings leave out libraries excluded in Statistics.';
        if ($otherPlays > 0) {
            $rankingNote .= ($rankingNote === '' ? '' : ' ') . $this->duration($otherSeconds)
                . ' from music and other media is included in the totals, but not in these rankings.';
        }

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'monthName' => $start->format('F'),
            'year' => $start->format('Y'),
            'days' => array_values($days),
            'totalSeconds' => $totalSeconds,
            'totalMinutes' => (int) round($totalSeconds / 60),
            'watch' => $this->duration($totalSeconds),
            'hasEstimates' => $estimatedSeconds > 0,
            'estimated' => $this->duration($estimatedSeconds),
            'plays' => count($rows),
            'activeDays' => $activeDays,
            'titleCount' => count($groups),
            'movies' => $movies,
            'series' => $series,
            'featured' => array_slice($groups, 0, 3),
            'viewers' => $viewerRows,
            'bestDay' => $bestDay,
            'bestDuration' => $this->duration($peakSeconds),
            'empty' => $rows === [],
            'scopeLabel' => $viewer !== '' ? $viewer : 'All viewers',
            'coverageNote' => 'Based on recorded plays in ' . $start->format('F Y')
                . '. Days without entries mean no recorded viewing; they do not prove that nothing was watched.',
            'rankingNote' => $rankingNote,
            'timezone' => $timezone->getName(),
            'otherSeconds' => $otherSeconds,
            'otherWatch' => $this->duration($otherSeconds),
            'otherPlays' => $otherPlays,
            'hasOtherMedia' => $otherPlays > 0,
        ];
    }

    private function viewingSeconds(\Dibi\Row $row): int
    {
        return max(0, (int) ($row['watch_duration_sec'] ?? $row['watched_sec'] ?? 0));
    }

    private function isMovieOrEpisode(string $type): bool
    {
        return strcasecmp($type, 'Movie') === 0 || strcasecmp($type, 'Episode') === 0;
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, \Dibi\Row>
     */
    private function rankingRows(array $rows): array
    {
        $rows = array_values(array_filter(
            $rows,
            fn (\Dibi\Row $row): bool => $this->isMovieOrEpisode((string) ($row['item_type'] ?? '')),
        ));
        $excluded = $this->excludedLibraries();
        if ($rows === [] || $excluded === []) {
            return $rows;
        }

        $unresolved = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['library'] ?? '')) === ''
                || trim((string) ($row['library_resolved_at'] ?? '')) === '') {
                $itemId = trim((string) ($row['resolved_item_id'] ?? $row['item_id'] ?? ''));
                if ($itemId !== '') {
                    $unresolved[$this->normalizedItemId($itemId)] = $itemId;
                }
            }
        }

        $meta = [];
        $lookupFailed = false;
        if ($unresolved !== []) {
            try {
                foreach (($this->client ?? new JellyfinClient())->itemImportMeta(array_values($unresolved)) as $itemId => $itemMeta) {
                    $meta[$this->normalizedItemId((string) $itemId)] = $itemMeta;
                }
            } catch (\Throwable) {
                $lookupFailed = true;
            }
        }

        return array_values(array_filter($rows, function (\Dibi\Row $row) use ($excluded, $meta, $lookupFailed): bool {
            $library = trim((string) ($row['library'] ?? ''));
            if ($library !== '' && trim((string) ($row['library_resolved_at'] ?? '')) !== '') {
                return !in_array(mb_strtolower($library), $excluded, true);
            }
            if ($lookupFailed) {
                return true;
            }
            $itemId = $this->normalizedItemId((string) ($row['resolved_item_id'] ?? $row['item_id'] ?? ''));
            if ($itemId === '') {
                return true;
            }
            $itemMeta = $meta[$itemId] ?? null;

            return $itemMeta !== null
                && !in_array(mb_strtolower(trim($itemMeta['library'])), $excluded, true);
        }));
    }

    /** @return list<string> */
    private function excludedLibraries(): array
    {
        $raw = AppSettings::get('trending_exclude_libraries')
            ?? (string) Config::get('TRENDING_EXCLUDE_LIBRARIES', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $name): string => mb_strtolower(trim($name, " \t\n\r\0\x0B\"'")),
            explode(',', $raw),
        ))));
    }

    private function normalizedItemId(string $itemId): string
    {
        return strtolower(str_replace('-', '', trim($itemId)));
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, mixed>>
     */
    private function titleGroups(array $rows): array
    {
        $seriesLibraries = [];
        $episodeLibraries = [];
        foreach ($rows as $row) {
            $library = trim((string) ($row['library'] ?? ''));
            if (strcasecmp((string) ($row['item_type'] ?? ''), 'Episode') !== 0
                || $library === '' || trim((string) ($row['library_resolved_at'] ?? '')) === '') {
                continue;
            }
            $name = trim((string) ($row['series_name'] ?? ''));
            $id = ThemePlaybackExclusions::canonicalItemId((string) ($row['item_id'] ?? ''));
            if ($name !== '') {
                $seriesLibraries[$name][$library] = $library;
            }
            if ($id !== '') {
                $episodeLibraries[$id][$library] = $library;
            }
        }

        $groups = [];
        foreach ($rows as $row) {
            $isEpisode = strcasecmp((string) ($row['item_type'] ?? ''), 'Episode') === 0;
            $itemId = trim((string) ($row['resolved_item_id'] ?? $row['item_id'] ?? ''));
            $library = trim((string) ($row['library'] ?? ''));
            $libraryConfirmed = $library !== '' && trim((string) ($row['library_resolved_at'] ?? '')) !== '';
            if ($isEpisode && !$libraryConfirmed) {
                // Use confirmed evidence only when it identifies one library.
                // Same-named shows in different libraries must stay separate.
                $id = ThemePlaybackExclusions::canonicalItemId($itemId);
                $name = trim((string) ($row['series_name'] ?? ''));
                $candidates = $episodeLibraries[$id] ?? $seriesLibraries[$name] ?? [];
                if (count($candidates) === 1) {
                    $library = array_values($candidates)[0];
                    $libraryConfirmed = true;
                }
            }
            $title = trim((string) ($isEpisode ? ($row['series_name'] ?? '') : ($row['item_name'] ?? '')));
            $title = $title !== '' ? $title : ($isEpisode ? 'Unknown series' : 'Untitled movie');
            $key = $isEpisode
                ? "series\0" . $title . "\0" . ($libraryConfirmed ? $library : '')
                : 'movie:' . ($itemId !== ''
                    ? ThemePlaybackExclusions::canonicalItemId($itemId)
                    : "title\0" . $title . "\0" . $library);
            if ($isEpisode && trim((string) ($row['series_name'] ?? '')) === '') {
                $key .= "\0" . ThemePlaybackExclusions::canonicalItemId($itemId);
            }

            $groups[$key] ??= [
                'title' => $title,
                'year' => null,
                'plays' => 0,
                'seconds' => 0,
                'duration' => '0m',
                'poster' => $this->poster($itemId, $isEpisode),
                'type' => $isEpisode ? 'Series' : 'Movie',
                '_latest' => '',
                '_key' => $key,
            ];
            ++$groups[$key]['plays'];
            $groups[$key]['seconds'] += $this->viewingSeconds($row);
            $startedAt = (string) ($row['started_at'] ?? '');
            if ($startedAt > $groups[$key]['_latest']) {
                $groups[$key]['_latest'] = $startedAt;
                $groups[$key]['poster'] = $this->poster($itemId, $isEpisode);
            }
        }

        $groups = array_values($groups);
        usort($groups, $this->rankRows(...));
        foreach ($groups as &$group) {
            $group['duration'] = $this->duration((int) $group['seconds']);
            unset($group['seconds'], $group['_latest'], $group['_key']);
        }
        unset($group);

        return $groups;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function rankRows(array $left, array $right): int
    {
        return ((int) ($right['seconds'] ?? 0) <=> (int) ($left['seconds'] ?? 0))
            ?: ((int) ($right['plays'] ?? 0) <=> (int) ($left['plays'] ?? 0))
            ?: strcmp((string) ($left['title'] ?? $left['name'] ?? ''), (string) ($right['title'] ?? $right['name'] ?? ''))
            ?: strcmp((string) ($left['_key'] ?? ''), (string) ($right['_key'] ?? ''));
    }

    private function poster(string $itemId, bool $series): ?string
    {
        if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $itemId)) {
            return null;
        }
        $url = '/api/image.php?item=' . rawurlencode($itemId) . '&type=Primary&maxWidth=320&retained=1';

        return $series ? $url . '&kind=series' : $url;
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds > 0 && $seconds < 60) {
            return '<1m';
        }
        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $hours > 0 ? $hours . 'h ' . $remaining . 'm' : $minutes . 'm';
    }
}
