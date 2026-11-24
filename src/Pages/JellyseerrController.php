<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\LibraryFileRemoval;
use Mk\Framework\Jellyseerr\RequestBrowser;
use Mk\Framework\Jellyseerr\RequestEditor;
use Mk\Framework\Jellyseerr\RequestLink;
use Mk\Framework\Jellyseerr\RequestManager;
use Mk\Framework\Jellyseerr\RequestMapper;

final class JellyseerrController extends Controller
{
    private const FILTERS = ['all' => 'All requests', 'pending' => 'Pending approval', 'failed' => 'Failed'];

    public function handle(): void
    {
        $client = new JellyseerrClient(readTimeoutMilliseconds: 2_500);
        $configured = $client->isConfigured();
        $filterInput = $_GET['filter'] ?? null;
        $filter = is_string($filterInput) && isset(self::FILTERS[$filterInput]) ? $filterInput : 'all';
        $page = self::positiveInteger($_GET['p'] ?? null, intdiv(9_007_199_254_740_991, RequestBrowser::PAGE_SIZE)) ?? 1;
        $fragment = $_GET['fragment'] ?? null;

        if ($fragment !== null) {
            header('Cache-Control: private, no-store');
            if (!$configured || !in_array($fragment, ['queue', 'detail', 'counts', 'metadata', 'editor', 'file-removal'], true)) {
                http_response_code(404);
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if ($fragment === 'editor') {
                $id = RequestLink::id($_GET['request'] ?? null);
                $this->editor($client, $id);
                return;
            }
            if ($fragment === 'file-removal') {
                $this->fileRemoval($client, RequestLink::id($_GET['request'] ?? null));
                return;
            }
            $browser = new RequestBrowser($client);
            if ($fragment === 'queue') {
                $this->queue($browser, $client, $page, $filter);
            } elseif ($fragment === 'detail') {
                $id = RequestLink::id($_GET['request'] ?? null);
                $detail = $id !== null ? $browser->detail($id) : ['state' => 'missing', 'request' => null];
                $detail['can_manage'] = $this->canManageCurrent($client, $detail['state']);
                $detail['can_remove_files'] = $detail['can_manage'] && $detail['request'] !== null
                    && LibraryFileRemoval::available($detail['request']) && $this->canRemoveFiles($client);
                $this->render('jellyseerr/_detail', $detail);
            } elseif ($fragment === 'counts') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($browser->otherTotals($filter), JSON_THROW_ON_ERROR);
            } else {
                $result = $browser->enrich($page, $filter);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'remaining' => $result['remaining'],
                    'requests' => array_map(static fn (array $row): array => [
                        'id' => $row['requestId'], 'title' => $row['title'],
                        'year' => $row['year'], 'poster' => $row['posterUrl'],
                    ], $result['requests']),
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return;
        }

        $views = [];
        foreach (self::FILTERS as $key => $label) {
            $views[$key] = ['label' => $label, 'url' => $this->url($key, 1)];
        }

        $this->render('jellyseerr/index', [
            'layout' => $this->layout(['title' => 'Seerr', 'page' => 'jellyseerr', 'hide_footer' => true, 'header_class' => 'seerr-header']),
            'configured' => $configured,
            'queue_url' => $this->url($filter, $page),
            'filter' => $filter,
            'views' => $views,
        ]);
    }

    private function fileRemoval(JellyseerrClient $client, ?int $id): void
    {
        if (!$this->canRemoveFiles($client)) {
            http_response_code(403);
            return;
        }
        $target = null;
        try {
            if ($id !== null) {
                $request = $client->requestDetail($id);
                $version = RequestManager::version($request);
                if ($version !== null && $version === ($_GET['version'] ?? null)) {
                    $target = (new LibraryFileRemoval($client))->target($request);
                }
            }
        } catch (\RuntimeException) {
            // An unverified destination has no confirm action.
        }
        if (!$this->canRemoveFiles($client)) {
            http_response_code(403);
            return;
        }
        $this->render('jellyseerr/_file_removal', [
            'target' => $target === null ? null : array_intersect_key($target, array_flip(['token', 'title', 'service', 'type', 'is4k'])),
        ]);
    }

    /** @phpstan-impure Permissions can change during upstream reads. */
    private function canRemoveFiles(JellyseerrClient $client): bool
    {
        try {
            return (new Authorization())->canRemoveSeerrFiles($client);
        } catch (\Throwable) {
            return false;
        }
    }

    private function editor(JellyseerrClient $client, ?int $id): void
    {
        if (!$this->canManageCurrent($client, 'current')) {
            http_response_code(403);
            return;
        }
        $request = null;
        $options = null;
        $link = null;
        $version = null;
        $destination = is_string($_GET['destination'] ?? null) ? $_GET['destination'] : null;
        if ($id !== null) {
            try {
                $request = $client->requestDetail($id);
                $version = RequestManager::version($request);
                if (($request['status'] ?? null) === RequestMapper::REQUEST_PENDING && $version !== null
                    && $version === ($_GET['version'] ?? null)) {
                    $editor = new RequestEditor($client);
                    $options = $editor->options($request, $destination);
                    if ($destination === null) {
                        $link = $editor->browserLink($request);
                    } elseif (($options['baseVersion'] ?? null) !== ($_GET['base'] ?? '')) {
                        $options = null;
                    }
                }
            } catch (\RuntimeException) {
                // Editor choices always require a live exact request.
            }
        }
        if (!$this->canManageCurrent($client, 'current')) {
            http_response_code(403);
            return;
        }
        $this->render('jellyseerr/_editor', [
            'pending' => ($request['status'] ?? null) === RequestMapper::REQUEST_PENDING,
            'stale' => $version !== null && $version !== ($_GET['version'] ?? null),
            'version' => $version, 'options' => $options, 'seerr_link' => $link,
            'seasons' => $options['stored']['payload']['seasons'] ?? [],
        ]);
    }

    private static function positiveInteger(mixed $input, int $max): ?int
    {
        if (!is_string($input) || preg_match('/^[1-9][0-9]{0,15}$/D', $input) !== 1) {
            return null;
        }
        $value = (int) $input;

        return $value <= $max ? $value : null;
    }

    private function queue(RequestBrowser $browser, JellyseerrClient $client, int $page, string $filter): void
    {
        $result = $browser->page($page, $filter);
        $current = $result['page'];
        $pages = $result['pages'];
        $url = $this->url($filter, $current);
        $cards = [];
        foreach ($result['requests'] as $row) {
            $row['url'] = $this->url($filter, $current, $row['requestId']);
            $row['initials'] = RequestMapper::initials($row['requester']);
            $row['requestedAgo'] = $this->timeAgo($row['requestedAt']);
            $cards[] = $row;
        }
        $statusCounts = array_count_values(array_column($cards, 'statusKind'));
        $partial = $statusCounts['partial'] ?? 0;
        $start = $cards === [] ? 0 : 1 + ($current - 1) * RequestBrowser::PAGE_SIZE;
        $this->render('jellyseerr/_queue', [
            'state' => $result['state'], 'total' => $result['total'], 'requests' => $cards, 'queue_url' => $url,
            'can_manage' => $this->canManageCurrent($client, $result['state']),
            'first' => $start, 'last' => $start === 0 ? 0 : $start + count($cards) - 1,
            'available' => ($statusCounts['available'] ?? 0) + $partial,
            'partial' => $partial, 'requested' => $statusCounts['requested'] ?? 0,
            'filter' => $filter,
            'pager' => ['page' => $current, 'pages' => $pages,
                'prev_url' => $current > 1 ? $this->url($filter, $current - 1) : null,
                'next_url' => $current < $pages ? $this->url($filter, $current + 1) : null],
        ]);
    }

    /** @phpstan-impure Permissions can change during upstream reads. */
    private function canManageCurrent(JellyseerrClient $client, string $state): bool
    {
        try {
            return $state === 'current' && (new Authorization())->canManageSeerrRequests($client);
        } catch (\Throwable) {
            return false;
        }
    }

    private function url(string $filter, int $page, ?int $id = null): string
    {
        $params = [];
        if ($filter !== 'all') {
            $params['filter'] = $filter;
        }
        if ($page !== 1) {
            $params['p'] = $page;
        }
        if ($id !== null) {
            $params['request'] = $id;
        }

        return '/jellyseerr' . ($params !== [] ? '?' . http_build_query($params) : '');
    }

    private function timeAgo(?string $datetime): string
    {
        if ($datetime === null) {
            return '';
        }
        try {
            $seconds = time() - (new \DateTimeImmutable($datetime))->getTimestamp();
        } catch (\Exception) {
            return '';
        }

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ago',
            default => intdiv($seconds, 86400) . 'd ago',
        };
    }
}
