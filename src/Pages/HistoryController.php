<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Main;

final class HistoryController extends Controller
{
    public function handle(): void
    {
        $filters = $this->filters();
        $repository = new PlayHistoryRepository();
        $totalFiltered = $repository->historyTotal($filters);
        $page = $this->currentPage($totalFiltered, $filters->limit);
        $filters = new HistoryFilters(
            search: $filters->search,
            user: $filters->user,
            library: $filters->library,
            range: $filters->range,
            limit: $filters->limit,
            offset: ($page - 1) * $filters->limit,
        );
        $rows = $repository->historyRows($filters);
        $pages = max(1, (int) ceil($totalFiltered / max(1, $filters->limit)));

        $this->render('history/index', [
            'layout' => $this->layout([
                'title' => 'History',
                'page' => 'history',
            ]),
            'groups' => $this->groups($rows),
            'summary' => $this->summary($rows, $totalFiltered, $repository->totalRows(), $filters->offset),
            'pager' => $this->pager($page, $pages, $filters),
            'users' => $repository->users(),
            'filters' => [
                'search' => $filters->search,
                'user' => $filters->user,
                'library' => $filters->library,
                'range' => $filters->range,
            ],
        ]);
    }

    private function filters(): HistoryFilters
    {
        $range = Main::captureGetString('range') ?? '30';
        if (!in_array($range, ['7', '30', 'all'], true)) {
            $range = '30';
        }

        return new HistoryFilters(
            search: trim((string) (Main::captureGetString('search') ?? '')),
            user: trim((string) (Main::captureGetString('user') ?? '')),
            library: trim((string) (Main::captureGetString('library') ?? '')),
            range: $range,
        );
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
        $query = array_filter([
            'search' => $filters->search,
            'user' => $filters->user,
            'library' => $filters->library,
            'range' => $filters->range !== '30' ? $filters->range : '',
        ], static fn (string $value): bool => $value !== '');

        if ($page > 1) {
            $query['p'] = (string) $page;
        }

        return '/history' . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $rows): array
    {
        $groups = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        foreach ($rows as $row) {
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
                ];
            }

            $play = $this->rowView($row, $startedAt);
            $groups[$dayKey]['watch_sec'] += (int) $row['watched_sec'];
            $groups[$dayKey]['plays'][] = $play;
        }

        foreach ($groups as &$group) {
            $count = count($group['plays']);
            $group['summary'] = $count . ($count === 1 ? ' play - ' : ' plays - ')
                . $this->durationLabel((int) $group['watch_sec']);
            unset($group['watch_sec']);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowView(\Dibi\Row $row, \DateTimeImmutable $startedAt): array
    {
        $itemType = (string) $row['item_type'];
        $isTranscode = (string) $row['play_method'] === 'Transcode';
        $watchedSec = (int) $row['watched_sec'];
        $runtimeSec = (int) $row['runtime_sec'];
        $completion = $runtimeSec > 0 ? min(100, (int) round(($watchedSec / $runtimeSec) * 100)) : 0;
        $seriesName = (string) ($row['series_name'] ?? '');
        $itemName = (string) ($row['item_name'] ?? 'Unknown title');

        return [
            'time' => $startedAt->format('H:i'),
            'user' => (string) ($row['user_name'] ?? 'Unknown user'),
            'initials' => $this->initials((string) ($row['user_name'] ?? 'Unknown user')),
            'title' => $itemType === 'Episode' && $seriesName !== '' ? $seriesName : $itemName,
            'sub' => $itemType === 'Episode'
                ? trim((string) ($row['season_ep'] ?? '') . ' - ' . $itemName, ' -')
                : (string) ($row['library'] ?? $itemType),
            'methodLabel' => $isTranscode ? 'Transcoding' : 'Direct',
            'isTranscode' => $isTranscode,
            'isDirect' => !$isTranscode,
            'client' => (string) ($row['client'] ?? ''),
            'device' => (string) ($row['device'] ?? ''),
            'watchedLabel' => $this->durationLabel($watchedSec),
            'completionPct' => $completion,
            'finished' => (bool) $row['is_finished'] || $completion >= 95,
            'poster' => $this->poster((string) $row['item_id'], $itemType),
        ];
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, int $totalFiltered, int $totalRows, int $offset): array
    {
        $watchSec = 0;
        $users = [];
        $transcodes = 0;

        foreach ($rows as $row) {
            $watchSec += (int) $row['watched_sec'];
            $user = (string) ($row['user_name'] ?? '');
            if ($user !== '') {
                $users[$user] = true;
            }
            if ((string) $row['play_method'] === 'Transcode') {
                $transcodes++;
            }
        }

        $shown = count($rows);

        return [
            'shown' => $shown,
            'from' => $shown === 0 ? 0 : $offset + 1,
            'to' => $offset + $shown,
            'total' => $totalRows,
            'filtered_total' => $totalFiltered,
            'unique_users' => count($users),
            'watch_time' => $this->durationLabel($watchSec),
            'transcoded_pct' => ($shown > 0 ? (int) round(($transcodes / $shown) * 100) : 0) . '%',
        ];
    }

    private function durationLabel(int $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $remainingMinutes . 'm';
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
