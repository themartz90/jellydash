<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Cache\AtomicJsonFile;
use Mk\Framework\Http\RemoteRequestException;
use Mk\Framework\Log;

/** Read-side snapshots and title enrichment, independent of poller membership. */
final class RequestBrowser
{
    public const PAGE_SIZE = 24;
    public const CACHE_DIRECTORY = CACHE_DIR . '/seerr-browse';
    private const PAGE_TTL = 25;
    // A page served near the end of its list TTL can still finish six batches.
    private const ENRICHMENT_WINDOW = 120;
    private const STALE_TTL = 604_800;
    private const MEDIA_TTL = 604_800;
    private const MEDIA_RETRY = 300;
    private const MEDIA_BATCH = 4;
    private const MEDIA_BUCKET_LIMIT = 32;

    /** @var \Closure(): int */
    private \Closure $clock;
    private string $generation;
    private bool $writing;

    public function __construct(
        private JellyseerrClient $client,
        private string $cacheDirectory = self::CACHE_DIRECTORY,
        ?callable $clock = null,
        private ?SeerrRequestRepository $mirror = null,
    ) {
        $this->clock = $clock !== null ? $clock(...) : time(...);
        $file = self::stateFile($client, $cacheDirectory);
        $state = $file->read() ?? [];
        if (($state['writing'] ?? false) === true) {
            $state = self::recoverAbandonedWrite($file, $state);
        }
        $this->generation = is_string($state['generation'] ?? null) ? $state['generation'] : 'initial';
        $this->writing = ($state['writing'] ?? false) === true;
    }

    public static function stateFile(JellyseerrClient $client, string $directory): AtomicJsonFile
    {
        return new AtomicJsonFile($directory . '/state-' . $client->cacheIdentity() . '.json');
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private static function recoverAbandonedWrite(AtomicJsonFile $file, array $state): array
    {
        try {
            return $file->withExclusiveLock(static function () use ($file, $state): array {
                $latest = $file->read() ?? $state;
                if (($latest['writing'] ?? false) === true) {
                    $latest['generation'] = bin2hex(random_bytes(16));
                    $latest['writing'] = false;
                    $file->write($latest);
                }
                return $latest;
            }, blocking: false);
        } catch (\RuntimeException) {
            // A live writer owns the lock, or recovery could not be persisted.
            return $state;
        }
    }

    /** @return array{state: string, page: int, pages: int, total: ?int, requests: list<array<string, mixed>>} */
    public function page(int $page, string $filter): array
    {
        $key = $filter . ':' . $page;
        $saved = $this->read('page', $key, self::STALE_TTL);
        if ($saved !== null && $saved['age'] <= self::PAGE_TTL) {
            return $this->pageResult($saved['value'], 'current');
        }

        try {
            $response = $this->client->requestPageWithMetadata($page, $filter, self::PAGE_SIZE);
            $last = max(1, $response['pageInfo']['pages']);
            if ($page > $last && $response['pageInfo']['results'] !== 0) {
                $response = $this->client->requestPageWithMetadata($last, $filter, self::PAGE_SIZE);
            }
            $snapshot = [
                'page' => $response['pageInfo']['results'] === 0 ? 1 : $response['pageInfo']['page'],
                'pages' => max(1, $response['pageInfo']['pages']),
                'total' => $response['pageInfo']['results'],
                'requests' => array_map(self::card(...), $response['results']),
            ];
            $this->write('page', $filter . ':' . $snapshot['page'], $snapshot);
            $this->write('count-' . $filter, 'total', ['total' => $snapshot['total']]);

            return $this->pageResult($snapshot, 'current');
        } catch (\RuntimeException $e) {
            if ($saved !== null && !self::denied($e)) {
                return $this->pageResult($saved['value'], 'cached');
            }

            return ['state' => 'unavailable', 'page' => $page, 'pages' => 1, 'total' => null, 'requests' => []];
        }
    }

    /** @return array<string, int|null> Totals for the tabs without the active page's own total. */
    public function otherTotals(string $activeFilter): array
    {
        $totals = [];
        foreach (['all', 'pending', 'failed'] as $filter) {
            if ($filter !== $activeFilter) {
                $totals[$filter] = $this->filterTotal($filter);
            }
        }

        return $totals;
    }

    private function filterTotal(string $filter): ?int
    {
        $kind = 'count-' . $filter;
        $saved = $this->read($kind, 'total', self::PAGE_TTL);
        if ($saved !== null) {
            return $saved['value']['total'];
        }

        [$file] = $this->slot($kind, 'total');
        try {
            return $file->withExclusiveLock(function () use ($kind, $filter): ?int {
                $saved = $this->read($kind, 'total', self::PAGE_TTL);
                if ($saved !== null) {
                    return $saved['value']['total'];
                }
                try {
                    // Match the list's edition and status filters, and discard its single result.
                    $total = $this->client->requestPageWithMetadata(1, $filter, 1)['pageInfo']['results'];
                } catch (\RuntimeException) {
                    $total = null;
                }
                $saved = $this->read($kind, 'total', self::PAGE_TTL);
                if ($saved !== null) {
                    return $saved['value']['total'];
                }
                $this->write($kind, 'total', ['total' => $total]);

                return $total;
            });
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return array{state: string, request: array<string, mixed>|null} */
    public function detail(int $id): array
    {
        $key = (string) $id;
        $saved = $this->read('detail', $key, self::STALE_TTL);
        // A negative lookup also masks older snapshots for this ID.
        if ($saved !== null && $saved['age'] <= self::PAGE_TTL) {
            $card = $saved['value'];
            return $card === [] ? ['state' => 'missing', 'request' => null]
                : ['state' => 'current', 'request' => $this->withMetadata([$card])[0]];
        }

        try {
            $record = $this->client->requestDetail($id);
            $card = self::card($record);
            $card['jellyfinBinding'] = JellyfinRequestLink::binding($record);
            $this->write('detail', $key, $card);
            $cards = $this->withMetadata([$card]);
            if ($cards[0]['metadataPending']) {
                $this->fetchMedia($card);
                $cards = $this->withMetadata([$card]);
            }

            return ['state' => 'current', 'request' => $cards[0]];
        } catch (\RuntimeException $e) {
            if ($e instanceof RemoteRequestException && $e->getCode() === 404) {
                $this->write('detail', $key, []);
                return ['state' => 'missing', 'request' => null];
            }
            if ($saved !== null && $saved['value'] !== [] && !self::denied($e)) {
                return ['state' => 'cached', 'request' => $this->withMetadata([$saved['value']])[0]];
            }

            return ['state' => 'unavailable', 'request' => null];
        }
    }

    /**
     * Only enrich cards from the validated, recent snapshot for this query.
     *
     * @return array{requests: list<array<string, mixed>>, remaining: bool}
     */
    public function enrich(int $page, string $filter): array
    {
        $saved = $this->read('page', $filter . ':' . $page, self::ENRICHMENT_WINDOW, forEnrichment: true);
        if ($saved === null) {
            return ['requests' => [], 'remaining' => false];
        }

        $requests = $saved['value']['requests'];
        $pending = array_filter($this->withMetadata($requests), static fn (array $card): bool => $card['metadataPending']);
        $seen = [];
        foreach ($pending as $card) {
            $key = $card['mediaType'] . ':' . $card['tmdbId'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->fetchMedia($card);
            if (count($seen) === self::MEDIA_BATCH) {
                break;
            }
        }
        $requests = $this->withMetadata($requests);

        return ['requests' => $requests, 'remaining' => in_array(true, array_column($requests, 'metadataPending'), true)];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private static function card(array $request): array
    {
        $card = RequestMapper::display($request);
        $card['managementVersion'] = RequestManager::version($request, $card);

        return $card;
    }

    /**
     * @param array<string, mixed> $value
     * @return array{state: string, page: int, pages: int, total: int, requests: list<array<string, mixed>>}
     */
    private function pageResult(array $value, string $state): array
    {
        return ['state' => $state, 'page' => $value['page'], 'pages' => $value['pages'],
            'total' => $value['total'], 'requests' => $this->withMetadata($value['requests'])];
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    private function withMetadata(array $cards): array
    {
        $rows = [];
        if ($cards !== []) {
            try {
                $rows = ($this->mirror ??= new SeerrRequestRepository())->displayMetadata(array_column($cards, 'requestId'));
            } catch (\Dibi\Exception $e) {
                Log::logException($e);
            }
        }

        foreach ($cards as &$card) {
            $row = $rows[$card['requestId']] ?? null;
            $key = $card['mediaType'] . ':' . $card['tmdbId'];
            $cached = $this->read('media', $key, self::MEDIA_TTL);
            $card['metadataPending'] = false;
            if ($row !== null && (int) $row['tmdb_id'] === $card['tmdbId'] && $row['media_type'] === $card['mediaType']
                && (int) $row['metadata_pending'] === 0 && !in_array($row['title'], ['Unknown title', 'Unknown series'], true)) {
                $card['title'] = (string) $row['title'];
                $card['year'] = $row['year'] !== null ? (string) $row['year'] : null;
                $card['posterUrl'] = RequestMapper::posterUrl($row['poster_path'] !== null ? (string) $row['poster_path'] : null);
            } elseif ($cached !== null && $cached['value'] !== []) {
                $card['title'] = $cached['value']['title'];
                $card['year'] = $cached['value']['year'];
                $card['posterUrl'] = RequestMapper::posterUrl($cached['value']['posterPath']);
            } else {
                $card['metadataPending'] = $card['mediaType'] !== null && $card['tmdbId'] !== null
                    && ($cached === null || $cached['age'] > self::MEDIA_RETRY);
            }
        }

        return $cards;
    }

    /** @param array<string, mixed> $card */
    private function fetchMedia(array $card): void
    {
        $type = $card['mediaType'];
        $id = $card['tmdbId'];
        try {
            $data = $type === 'tv' ? $this->client->tv($id) : $this->client->movie($id);
            $details = RequestMapper::details($data, $type);
        } catch (\RuntimeException) {
            $details = [];
        }
        $this->write('media', $type . ':' . $id, $details);
    }

    private static function denied(\RuntimeException $e): bool
    {
        return $e instanceof RemoteRequestException && in_array($e->getCode(), [401, 403], true);
    }

    /** @return array{value: array<string, mixed>, age: int}|null */
    private function read(string $kind, string $key, int $maxAge, bool $forEnrichment = false): ?array
    {
        // A process interrupted after sending a write leaves browse reads live-only.
        if ($this->writing && $kind !== 'media' && !$forEnrichment) {
            return null;
        }
        [$file, $digest] = $this->slot($kind, $key);
        $stored = $file->read();
        $entry = $kind === 'media' ? ($stored[$digest] ?? null) : $stored;
        if ($entry === null || ($entry['key'] ?? null) !== $digest
            || !is_int($entry['savedAt'] ?? null) || !is_array($entry['value'] ?? null)) {
            return null;
        }
        $age = ($this->clock)() - $entry['savedAt'];

        return $age >= 0 && $age <= $maxAge ? ['value' => $entry['value'], 'age' => $age] : null;
    }

    /** @param array<string, mixed> $value */
    private function write(string $kind, string $key, array $value): void
    {
        [$file, $digest] = $this->slot($kind, $key);
        try {
            $entry = ['key' => $digest, 'savedAt' => ($this->clock)(), 'value' => $value];
            if ($kind === 'media') {
                $file->withExclusiveLock(static function () use ($file, $digest, $entry): void {
                    $entries = $file->read() ?? [];
                    unset($entries[$digest]);
                    $entries[$digest] = $entry;
                    $file->write(array_slice($entries, -self::MEDIA_BUCKET_LIMIT, preserve_keys: true));
                });
            } else {
                $file->write($entry);
            }
        } catch (\RuntimeException $e) {
            Log::logException($e);
        }
    }

    /** @return array{AtomicJsonFile, string} */
    private function slot(string $kind, string $key): array
    {
        $version = $kind === 'media' ? 'v2' : 'v3:' . $this->generation;
        $digest = hash('sha256', $version . ':' . $this->client->cacheIdentity() . ':' . $kind . ':' . $key);
        // Fixed-size slots limit disk use even when clients visit arbitrary pages.
        $slot = substr($digest, 0, 2);

        return [new AtomicJsonFile($this->cacheDirectory . '/' . $kind . '-' . $slot . '.json'), $digest];
    }
}
