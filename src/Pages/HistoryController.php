<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\JellyfinUserAvatars;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Main;

final class HistoryController extends Controller
{
    public function handle(): void
    {
        $filters = $this->filters();
        $repository = new PlayHistoryRepository();
        $aggregate = $repository->historyAggregate($filters);
        $totalFiltered = $aggregate['plays'];
        $page = $this->currentPage($totalFiltered, $filters->limit);
        $filters = new HistoryFilters(
            search: $filters->search,
            user: $filters->user,
            library: $filters->library,
            client: $filters->client,
            method: $filters->method,
            range: $filters->range,
            limit: $filters->limit,
            offset: ($page - 1) * $filters->limit,
            start: $filters->start,
            end: $filters->end,
            mediaType: $filters->mediaType,
            mediaId: $filters->mediaId,
            mediaItemType: $filters->mediaItemType,
            mediaTitle: $filters->mediaTitle,
            mediaLibrary: $filters->mediaLibrary,
        );
        $rows = $repository->historyRows($filters);
        $pages = max(1, (int) ceil($totalFiltered / max(1, $filters->limit)));

        $this->render('history/index', [
            'layout' => $this->layout([
                'title' => 'History',
                'page' => 'history',
            ]),
            'groups' => $this->groups($rows),
            'summary' => $this->summary($rows, $aggregate, $repository->totalRows(), $filters->offset),
            'pager' => $this->pager($page, $pages, $filters),
            'users' => $repository->users(),
            'libraries' => $repository->libraries(),
            'clients' => $repository->clients(),
            'filters' => [
                'search' => $filters->search,
                'user' => $filters->user,
                'library' => $filters->library,
                'client' => $filters->client,
                'method' => $filters->method,
                'range' => $filters->range,
                'is_exact' => $filters->hasExactPeriod(),
                'period_label' => $this->periodLabel($filters),
                'start' => $filters->start?->format('Y-m-d') ?? '',
                'end' => $filters->end?->format('Y-m-d') ?? '',
                'has_media' => $filters->hasMediaScope(),
                'media_type' => $filters->mediaType,
                'media_id' => $filters->mediaId,
                'media_item_type' => $filters->mediaItemType,
                'media_title' => $filters->mediaTitle,
                'media_library' => $filters->mediaLibrary,
                'media_clear_url' => $this->mediaClearUrl($filters),
            ],
        ]);
    }

    private function filters(): HistoryFilters
    {
        return HistoryFilters::fromQuery($_GET);
    }

    private function currentPage(int $total, int $perPage): int
    {
        $page = (int) (Main::captureGetString('p') ?? '1');
        if ($page < 1) {
            $page = 1;
        }

        $pages = max(1, (int) ceil($total / max(1, $perPage)));

        return min($page, $pages);
    }

    /**
     * @return array<string, mixed>
     */
    private function pager(int $page, int $pages, HistoryFilters $filters): array
    {
        return [
            'page' => $page,
            'pages' => $pages,
            'prev_url' => $page > 1 ? $this->historyUrl($filters, $page - 1) : '',
            'next_url' => $page < $pages ? $this->historyUrl($filters, $page + 1) : '',
        ];
    }

    private function historyUrl(HistoryFilters $filters, int $page): string
    {
        $query = $filters->queryParameters();

        if ($page > 1) {
            $query['p'] = (string) $page;
        }

        return '/history' . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function mediaClearUrl(HistoryFilters $filters): string
    {
        $query = $filters->queryParameters();
        unset(
            $query['media_type'],
            $query['media_id'],
            $query['media_item_type'],
            $query['media_title'],
            $query['media_library'],
        );

        return '/history' . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function periodLabel(HistoryFilters $filters): string
    {
        if (!$filters->hasExactPeriod()) {
            return '';
        }

        $start = $filters->start;
        $inclusiveEnd = $filters->end?->modify('-1 day');
        if ($start === null || $inclusiveEnd === null) {
            return '';
        }

        if ($start->format('Y-m-d') === $inclusiveEnd->format('Y-m-d')) {
            return $start->format('M j, Y');
        }

        if ($start->format('Y') !== $inclusiveEnd->format('Y')) {
            return $start->format('M j, Y') . ' - ' . $inclusiveEnd->format('M j, Y');
        }

        if ($start->format('m') === $inclusiveEnd->format('m')) {
            return $start->format('M j') . ' - ' . $inclusiveEnd->format('j, Y');
        }

        return $start->format('M j') . ' - ' . $inclusiveEnd->format('M j, Y');
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $rows): array
    {
        $avatars = new JellyfinUserAvatars();
        $groups = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        foreach ($rows as $row) {
            $rowData = $row->toArray();
            $watchDuration = $rowData['watch_duration_sec'] ?? null;
            $startedAt = new \DateTimeImmutable((string) $row['started_at']);
            $dayKey = $startedAt->format('Y-m-d');

            if (!isset($groups[$dayKey])) {
                $groups[$dayKey] = [
                    'label' => match ($dayKey) {
                        $today => 'Today',
                        $yesterday => 'Yesterday',
                        default => $startedAt->format('l, M j'),
                    },
                    'dateSub' => $startedAt->format('l, M j'),
                    'summary' => '',
                    'plays' => [],
                    'watch_sec' => 0,
                    'estimated' => false,
                ];
            }

            $play = $this->rowView($row, $startedAt, $avatars);
            $groups[$dayKey]['watch_sec'] += (int) ($watchDuration ?? $row['watched_sec']);
            $groups[$dayKey]['estimated'] = $groups[$dayKey]['estimated'] || $watchDuration === null;
            $groups[$dayKey]['plays'][] = $play;
        }

        foreach ($groups as &$group) {
            $count = count($group['plays']);
            $group['summary'] = $count . ($count === 1 ? ' play - ' : ' plays - ')
                . $this->durationLabel((int) $group['watch_sec'])
                . ($group['estimated'] ? ' estimated' : ' watched');
            unset($group['watch_sec'], $group['estimated']);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowView(\Dibi\Row $row, \DateTimeImmutable $startedAt, JellyfinUserAvatars $avatars): array
    {
        $itemType = (string) $row['item_type'];
        $isTranscode = (string) $row['play_method'] === 'Transcode';
        $watchedSec = (int) $row['watched_sec'];
        $watchDuration = $row->toArray()['watch_duration_sec'] ?? null;
        $viewingSec = (int) ($watchDuration ?? $watchedSec);
        $runtimeSec = (int) $row['runtime_sec'];
        $completion = $runtimeSec > 0 ? min(100, (int) round(($watchedSec / $runtimeSec) * 100)) : 0;
        $seriesName = (string) ($row['series_name'] ?? '');
        $itemName = (string) ($row['item_name'] ?? 'Unknown title');
        $user = (string) ($row['user_name'] ?? '');
        if (trim($user) === '') {
            $user = 'Unknown user';
        }
        $userId = (string) ($row['user_id'] ?? '');
        $library = trim((string) ($row['library'] ?? ''));

        return [
            'time' => $startedAt->format('H:i'),
            'user' => $user,
            'initials' => $this->initials($user),
            'avatarBg' => $this->avatarBg($userId !== '' ? $userId : $user),
            'avatarUrl' => $avatars->url($userId) ?? '',
            'title' => $itemType === 'Episode' && $seriesName !== '' ? $seriesName : $itemName,
            'sub' => $this->itemDetail($itemType, (string) ($row['season_ep'] ?? ''), $itemName),
            'library' => $library,
            'methodLabel' => $isTranscode ? 'Transcoding' : 'Direct',
            'isTranscode' => $isTranscode,
            'isDirect' => !$isTranscode,
            'client' => (string) ($row['client'] ?? ''),
            'device' => (string) ($row['device'] ?? ''),
            'watchedLabel' => $this->durationLabel($viewingSec) . ($watchDuration === null ? ' estimated' : ' watched'),
            'completionPct' => $completion,
            'finished' => (bool) $row['is_finished'] || $completion >= 95,
            'poster' => $this->poster((string) $row['item_id'], $itemType),
        ];
    }

    private function itemDetail(string $itemType, string $seasonEp, string $itemName): string
    {
        if ($itemType === 'Episode') {
            return trim($seasonEp . ' - ' . $itemName, ' -');
        }

        return match ($itemType) {
            'Movie' => 'Movie',
            'TvChannel' => 'Live TV',
            'MusicVideo' => 'Music video',
            'Audio' => 'Audio',
            default => $itemType !== '' ? $itemType : 'Video',
        };
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @param array{plays: int, unique_users: int, watch_sec: int, estimated_plays: int, transcodes: int} $aggregate
     * @return array<string, mixed>
     */
    private function summary(array $rows, array $aggregate, int $totalRows, int $offset): array
    {
        $shown = count($rows);
        $totalFiltered = $aggregate['plays'];

        return [
            'shown' => $shown,
            'from' => $shown === 0 ? 0 : $offset + 1,
            'to' => $offset + $shown,
            'total' => $totalRows,
            'filtered_total' => $totalFiltered,
            'unique_users' => $aggregate['unique_users'],
            'watch_time' => $this->durationLabel($aggregate['watch_sec']),
            'watch_time_estimated' => $aggregate['estimated_plays'] > 0,
            'transcoded_pct' => PlaybackStatisticsService::standalonePercentage(
                $aggregate['transcodes'],
                $totalFiltered,
            ) . '%',
        ];
    }

    private function durationLabel(int $seconds): string
    {
        return PlaybackStatisticsService::formatDuration($seconds);
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

    private function avatarBg(string $seed): string
    {
        $gradients = [
            'linear-gradient(135deg,#7c5cff,#b06bff)',
            'linear-gradient(135deg,#f0913a,#f7b955)',
            'linear-gradient(135deg,#1fb6a6,#34d8a6)',
            'linear-gradient(135deg,#3b9eff,#6f7bff)',
        ];

        return $gradients[abs(crc32($seed)) % count($gradients)];
    }

    /**
     * Real Jellyfin poster art layered over a colored gradient, so the gradient
     * shows through while the image loads (or if the item has no artwork).
     */
    private function poster(string $itemId, string $itemType): string
    {
        $gradient = $this->posterGradient($itemId);

        if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
            return $gradient;
        }

        // Episodes resolve to their series poster; movies use their own poster.
        $url = '/api/image.php?item=' . rawurlencode($itemId) . '&type=Primary&maxWidth=240';
        if ($itemType === 'Episode') {
            $url .= '&kind=series';
        }

        return 'url("' . $url . '"), ' . $gradient;
    }

    private function posterGradient(string $seed): string
    {
        $gradients = [
            'linear-gradient(145deg,#7a4a1e,#160d07)',
            'linear-gradient(145deg,#1f4a5c,#0a141c)',
            'linear-gradient(145deg,#233d5d,#090d18)',
            'linear-gradient(145deg,#69411f,#100b0c)',
            'linear-gradient(145deg,#375449,#091411)',
        ];

        return $gradients[abs(crc32($seed)) % count($gradients)];
    }
}
