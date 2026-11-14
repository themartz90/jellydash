<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;

/** Acquires posters independently of History intake and page rendering. */
final class ArtworkCollector
{
    private Database $database;
    private JellyfinClient $client;
    private ArtworkRepository $repository;
    private MonitoringExclusions $exclusions;
    /** @var \Closure(string, ?string): array{body: string, contentType: string} */
    private \Closure $fetcher;

    /** @param (\Closure(string, ?string): array{body: string, contentType: string})|null $fetcher */
    public function __construct(
        ?Database $database = null,
        ?JellyfinClient $client = null,
        ?ArtworkRepository $repository = null,
        ?\Closure $fetcher = null,
        ?MonitoringExclusions $exclusions = null
    ) {
        $this->database = $database ?? Container::db();
        $this->client = $client ?? new JellyfinClient();
        $this->repository = $repository ?? new ArtworkRepository($this->database, ThemePlaybackExclusions::serverKey($this->client->baseUrl()));
        $this->fetcher = $fetcher ?? (new ArtworkFetcher())->fetch(...);
        $this->exclusions = $exclusions ?? new MonitoringExclusions();
        new PlayHistoryRepository($this->database);
    }

    /** @return array{saved: int, checked: int, error: ?string} */
    public function runBatch(?int $now = null, int $limit = 4): array
    {
        $now ??= time();
        $start = hrtime(true);
        $result = ['saved' => 0, 'checked' => 0, 'error' => null];
        $source = $this->repository->source();
        if ((int) $source['blocked'] !== 0) {
            return $result;
        }
        if ($this->client->baseUrl() === '' || (int) ($source['next_attempt'] ?? 0) > $now) {
            return $result;
        }
        $lease = null;
        try {
            $this->verifySource();
            $lease = $this->repository->claim($now, 60);
            if ($lease === null) {
                return $result;
            }
            $this->repository->defer(null, 0);
            $this->intake();
            foreach ($this->repository->pendingItems($now, 1) as $item) {
                if ((hrtime(true) - $start) / 1e9 >= 6) {
                    break;
                }
                try {
                    $this->resolveItem($item);
                } catch (\Throwable $error) {
                    $reason = self::reason($error);
                    if (in_array($reason, ['authentication', 'throttled', 'decoder_missing'], true)) {
                        throw $error;
                    }
                    $item['status'] = in_array($reason, ['missing_item', 'metadata'], true) ? 'review' : 'pending';
                    $item['next_attempt'] = $now + 3600;
                    $this->repository->remember($item);
                }
            }
            foreach ($this->repository->jobs($now, max(1, min(8, $limit))) as $job) {
                if ((hrtime(true) - $start) / 1e9 >= 15) {
                    break;
                }
                ++$result['checked'];
                try {
                    if ($this->acquire($job, $lease, $now + (int) ((hrtime(true) - $start) / 1e9))) {
                        ++$result['saved'];
                    }
                } catch (\Throwable $error) {
                    $reason = self::reason($error);
                    $delay = in_array($reason, ['missing_item', 'missing_image', 'metadata'], true)
                        ? 86400 : min(21600, 60 * (2 ** min(8, (int) $job['attempts'])));
                    $this->repository->retry($job, $reason, $lease, $now, $delay);
                    if (in_array($reason, ['authentication', 'throttled', 'decoder_missing'], true)) {
                        throw $error;
                    }
                }
            }
        } catch (\Throwable $error) {
            $result['error'] = self::reason($error);
            $this->repository->defer($result['error'], $now + 900);
        } finally {
            if ($lease !== null) {
                $this->repository->release($lease);
            }
        }
        return $result;
    }

    public function verifySource(): void
    {
        $known = (new MovieIdentityRepository($this->database, $this->repository->scope))->state();
        if (is_string($known['serverId'] ?? null) && $known['serverId'] !== '') {
            if (!$this->repository->anchor($known['serverId'])) {
                throw new \RuntimeException('server_changed');
            }
        }
        $info = $this->client->getJson('/System/Info/Public', 4);
        if (!is_array($info) || !is_string($info['Id'] ?? null)) {
            throw new \RuntimeException('request_failed');
        }
        if (!$this->repository->anchor($info['Id'])) {
            throw new \RuntimeException('server_changed');
        }
    }

    private function intake(): void
    {
        $db = $this->database->getDibi();
        $freshCursor = (int) $this->repository->source()['fresh_cursor'];
        $equal = $this->database->getPlatform()->isSqlite() ? 'h.item_id COLLATE BINARY = a.item_id' : 'BINARY h.item_id = BINARY a.item_id';
        $freshQuery = $db->select('h.*')->from('play_history')->as('h')->leftJoin('artwork_items')->as('a')
            ->on('a.source_key = %s AND ' . $equal, $this->repository->scope)
            ->where('h.artwork_observed_at IS NOT NULL AND (a.observed_at IS NULL OR a.observed_at < h.artwork_observed_at)')
            ->where('h.id > %i', $freshCursor)
            ->where('h.artwork_observation LIKE %s', '%"source":"' . $this->repository->scope . '"%')
            ->where('h.item_type IN %in', ['Movie', 'Episode']);
        if ($this->exclusions->names() !== []) {
            $freshQuery->where("LOWER(TRIM(COALESCE(h.user_name, ''))) NOT IN %in", $this->exclusions->names());
        }
        $fresh = $freshQuery->orderBy('h.id')->limit(50)->fetchAll();
        foreach ($fresh as $row) {
            $this->intakeRow($row->toArray());
            $freshCursor = (int) $row['id'];
        }
        $this->repository->freshCursor(count($fresh) < 50 ? 0 : $freshCursor);
        $source = $this->repository->source();
        $rows = $db->select('*')->from('play_history')->where('id > %i', (int) $source['backfill_cursor'])
            ->orderBy('id')->limit(50)->fetchAll();
        foreach ($rows as $row) {
            $this->intakeRow($row->toArray());
            $this->repository->cursor((int) $row['id']);
        }
    }

    /** @param array<string, mixed> $row */
    private function intakeRow(array $row): void
    {
        if (!in_array($row['item_type'], ['Movie', 'Episode'], true)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) $row['item_id'])) {
            return;
        }
        $observation = ArtworkObservation::decode(is_string($row['artwork_observation'] ?? null) ? $row['artwork_observation'] : null);
        $source = $this->repository->source();
        $foreign = $observation !== null && $observation['source'] !== $this->repository->scope;
        $ignored = $this->exclusions->excludes(is_string($row['user_name'] ?? null) ? $row['user_name'] : null);
        if ($foreign || $ignored) {
            return;
        }
        $posterId = null;
        $trusted = $observation !== null && ($observation['server'] !== ''
            || ((int) ($source['anchored_at'] ?? 0) > 0 && $observation['observedAt'] >= (int) $source['anchored_at']));
        if ($observation !== null) {
            if ($observation['server'] !== '' && $observation['server'] !== $source['server_id']) {
                $this->repository->anchor($observation['server']);
                throw new \RuntimeException('server_changed');
            }
            if ($trusted) {
                $posterId = $row['item_type'] === 'Movie' ? (string) $row['item_id'] : $observation['series'];
            }
        }
        $item = ['item_id' => (string) $row['item_id'], 'item_type' => (string) $row['item_type'],
            'title' => (string) ($row['item_name'] ?? ''),
            'series_name' => (string) ($row['series_name'] ?? ''),
            'library' => !empty($row['library_resolved_at']) ? (string) ($row['library'] ?? '') : '',
            'poster_id' => $posterId, 'observed_at' => $observation['observedAt'] ?? 0,
            'evidence' => $observation !== null ? ($trusted ? 'observed' : 'provisional') : 'legacy',
            'status' => $posterId !== null ? 'ready'
                : ($trusted || (int) ($source['legacy_confirmed'] ?? 0) === 1 ? 'pending' : 'review'),
            'next_attempt' => 0];
        $this->repository->rememberWithPoster($item, $observation['tag'] ?? null);
    }

    /** @param array<string, mixed> $item */
    private function resolveItem(array $item): void
    {
        $meta = $this->lookup((string) $item['item_id']);
        if (($meta['Type'] ?? null) !== $item['item_type']
            || trim((string) ($meta['Name'] ?? '')) !== trim((string) $item['title'])
            || ($item['item_type'] === 'Episode' && trim((string) ($meta['SeriesName'] ?? '')) !== trim((string) $item['series_name']))) {
            throw new \RuntimeException('metadata');
        }
        $posterId = $item['item_type'] === 'Movie' ? (string) $meta['Id'] : ($meta['SeriesId'] ?? null);
        if (!is_string($posterId)) {
            throw new \RuntimeException('metadata');
        }
        ArtworkRepository::id($posterId);
        $item['poster_id'] = $posterId;
        $item['status'] = 'ready';
        $this->repository->rememberWithPoster($item);
    }

    /** @param array<string, mixed> $job */
    private function acquire(array &$job, string $lease, int $now): bool
    {
        $meta = $this->lookup((string) $job['poster_id']);
        if (($meta['Type'] ?? null) !== $job['kind']) {
            throw new \RuntimeException('metadata');
        }
        $this->repository->notePosterMetadata((string) $job['poster_id'], $meta, $lease, $now);
        if ((int) ($this->repository->poster((string) $job['poster_id'])['provider_conflict'] ?? 0) !== 0) {
            throw new \RuntimeException('metadata');
        }
        $tags = $meta['ImageTags'] ?? null;
        $tag = null;
        if (is_array($tags)) {
            $primary = $tags['Primary'] ?? null;
            if ($primary === null) {
                throw new \RuntimeException('missing_image');
            }
            if (!is_string($primary) || !preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $primary)) {
                throw new \RuntimeException('metadata');
            }
            $tag = $primary;
        }
        $this->repository->enqueue((string) $job['poster_id'], (string) $job['kind'], $tag, (int) (microtime(true) * 1000000));
        $current = $this->repository->poster((string) $job['poster_id']);
        if ($current === null || (int) $current['suppressed'] !== 0) {
            return false;
        }
        $job = $current;
        $old = $this->repository->image((string) $job['poster_id']);
        if ($tag !== null && $old !== null && $tag === $old['tag']) {
            $this->repository->retry($current, null, $lease, $now, 86400);
            return false;
        }
        $image = ($this->fetcher)((string) $job['poster_id'], $tag);
        $image = ArtworkFetcher::validate($image['body'], $image['contentType']);
        return $this->repository->publish($current, $image['body'], $image['contentType'], $lease, $now, ArtworkRepository::budget());
    }

    /** @return array<string, mixed> */
    public function lookup(string $id): array
    {
        ArtworkRepository::id($id);
        $payload = $this->client->getJson('/Items?' . http_build_query(['Ids' => $id, 'Fields' => 'ProviderIds',
            'CollapseBoxSetItems' => 'false', 'Limit' => 2], '', '&', PHP_QUERY_RFC3986), 4, 524288);
        if (!is_array($payload) || !is_array($payload['Items'] ?? null) || !array_is_list($payload['Items'])) {
            throw new \RuntimeException('request_failed');
        }
        if ($payload['Items'] === []) {
            throw new \RuntimeException('missing_item');
        }
        $item = count($payload['Items']) === 1 && is_array($payload['Items'][0]) ? $payload['Items'][0] : [];
        if (!is_string($item['Id'] ?? null) || self::canonical($item['Id']) !== self::canonical($id)) {
            throw new \RuntimeException('metadata');
        }
        if (isset($item['ServerId']) && $item['ServerId'] !== $this->repository->source()['server_id']) {
            throw new \RuntimeException('server_changed');
        }
        return $item;
    }

    private static function canonical(string $id): string
    {
        $guid = strtolower(str_replace('-', '', $id));
        return preg_match('/^[a-f0-9]{32}$/D', $guid) ? $guid : $id;
    }

    private static function reason(\Throwable $error): string
    {
        if (in_array($error->getCode(), [401, 403], true)) {
            return 'authentication';
        }
        if ($error->getCode() === 429) {
            return 'throttled';
        }
        if ($error->getCode() === 404) {
            return 'missing_item';
        }
        return in_array($error->getMessage(), ['configuration', 'decoder_missing', 'authentication', 'throttled', 'missing_item',
            'missing_image', 'metadata', 'server_changed', 'oversized', 'invalid_image'], true) ? $error->getMessage() : 'request_failed';
    }
}
