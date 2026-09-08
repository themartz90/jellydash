<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Config;
use Mk\Framework\Log;

/**
 * Keeps the local request mirror in step with Jellyseerr.
 *
 * The newest page refreshes current statuses. When that page contains only
 * unseen requests, older pages are fetched through a known local boundary so
 * an outage cannot hide requests beyond the first page.
 */
final class RequestSyncService
{
    // Page size and the number refreshed during an ordinary sync.
    private const FETCH_COUNT = 40;

    public function __construct(
        private ?JellyseerrClient $client = null,
        private ?SeerrRequestRepository $repository = null,
    ) {
    }

    /**
     * Pull the latest requests. Returns how many brand-new ones were stored.
     * On the very first sync everything is marked as already notified, so
     * enabling the feature never fires a burst of alerts for old requests.
     */
    public function sync(): int
    {
        $client = $this->client ?? new JellyseerrClient();
        if (!$client->isConfigured()) {
            return 0;
        }

        $repo = $this->repository ?? new SeerrRequestRepository();
        $firstRun = $repo->isEmpty();
        $requests = $firstRun
            ? $client->requests(self::FETCH_COUNT)
            : $this->requestsThroughKnownBoundary($client, $repo);

        if ($requests === []) {
            return 0;
        }

        $ids = [];
        foreach ($requests as $request) {
            $id = (int) ($request['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $known = $repo->knownIds($ids);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $statusUpdates = [];
        $inserts = [];

        foreach ($requests as $request) {
            $requestId = (int) ($request['id'] ?? 0);
            $media = is_array($request['media'] ?? null) ? $request['media'] : [];
            $mediaType = (string) ($media['mediaType'] ?? 'movie');
            $tmdbId = (int) ($media['tmdbId'] ?? 0);

            if ($requestId <= 0 || $tmdbId <= 0) {
                continue;
            }

            $requestStatus = (int) ($request['status'] ?? 0);
            $mediaStatus = (int) ($media['status'] ?? 0);

            if (in_array($requestId, $known, true)) {
                $statusUpdates[] = [
                    'request_id' => $requestId,
                    'request_status' => $requestStatus,
                    'media_status' => $mediaStatus,
                ];
                continue;
            }

            $details = $this->detailsFor($client, $mediaType, $tmdbId);

            $inserts[] = [
                'request_id' => $requestId,
                'media_type' => $mediaType,
                'tmdb_id' => $tmdbId,
                'title' => $details['title'],
                'year' => $details['year'],
                'poster_path' => $details['posterPath'],
                'requested_by' => $this->requesterName($request),
                'request_status' => $requestStatus,
                'media_status' => $mediaStatus,
                'is_4k' => ($request['is4k'] ?? false) ? 1 : 0,
                'season_count' => isset($request['seasonCount']) ? (int) $request['seasonCount'] : null,
                'requested_at' => $this->requestedAt($request, $now),
                'notified' => $firstRun ? 1 : 0,
                'created_at' => $now,
            ];
        }

        return $repo->applySyncBatch($statusUpdates, $inserts);
    }

    /**
     * Fetch the complete unseen prefix before performing any writes. A failed
     * later page therefore leaves the mirror at its previous safe boundary.
     *
     * @return array<int, array<string, mixed>>
     */
    private function requestsThroughKnownBoundary(JellyseerrClient $client, SeerrRequestRepository $repo): array
    {
        $requests = [];
        $seen = [];
        $skip = 0;

        while (true) {
            $page = $client->requestPage(self::FETCH_COUNT, $skip);
            if ($page === []) {
                break;
            }

            $pageIds = [];
            $newIds = 0;
            foreach ($page as $request) {
                $id = (int) ($request['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $pageIds[] = $id;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $requests[] = $request;
                    ++$newIds;
                }
            }

            if ($repo->knownIds($pageIds) !== []) {
                break;
            }
            if ($newIds === 0) {
                throw new \RuntimeException('Jellyseerr repeated a request page while syncing.');
            }
            if (count($page) < self::FETCH_COUNT) {
                break;
            }

            $skip += self::FETCH_COUNT;
        }

        return $requests;
    }

    /**
     * Title/year/poster for a request. A detail-lookup failure must not abort
     * the sync: we store a placeholder so the request still shows up and still
     * triggers its notification.
     *
     * @return array{title: string, year: ?string, posterPath: ?string}
     */
    private function detailsFor(JellyseerrClient $client, string $mediaType, int $tmdbId): array
    {
        try {
            $detail = $mediaType === 'tv' ? $client->tv($tmdbId) : $client->movie($tmdbId);

            return RequestMapper::details($detail, $mediaType);
        } catch (\Throwable $e) {
            Log::logException($e);

            return ['title' => $mediaType === 'tv' ? 'Unknown series' : 'Unknown title', 'year' => null, 'posterPath' => null];
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function requesterName(array $request): ?string
    {
        $by = is_array($request['requestedBy'] ?? null) ? $request['requestedBy'] : [];

        foreach (['displayName', 'jellyfinUsername', 'username', 'email'] as $key) {
            $value = trim((string) ($by[$key] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 128);
            }
        }

        return null;
    }

    /**
     * Jellyseerr timestamps are UTC ISO-8601; store them in the app's timezone
     * so they line up with the rest of the dashboard.
     *
     * @param array<string, mixed> $request
     */
    private function requestedAt(array $request, string $fallback): string
    {
        $raw = trim((string) ($request['createdAt'] ?? ''));
        if ($raw === '') {
            return $fallback;
        }

        // Resolve the zone directly so this stays correct even when a caller
        // did not run the normal web bootstrap first.
        $zone = Config::timezone();

        try {
            return (new \DateTimeImmutable($raw))
                ->setTimezone(new \DateTimeZone($zone))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $fallback;
        }
    }
}
