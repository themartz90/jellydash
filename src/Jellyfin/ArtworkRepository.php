<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;

/** Durable poster data, separate from playback rows and disposable caches. */
final class ArtworkRepository
{
    public const MAX_IMAGE_BYTES = 262144;
    public const DEFAULT_BUDGET = 536870912;
    public const EXTERNAL_LIFETIME = 15552000;
    private \Dibi\Connection $db;
    private Database $database;
    public readonly string $scope;
    private bool $inTransaction = false;
    private ?ArtworkResolver $externalResolver = null;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $initialized = null;

    public function __construct(?Database $database = null, ?string $scope = null)
    {
        $this->database = $database ?? Container::db();
        $this->db = $this->database->getDibi();
        $this->scope = $scope ?? ThemePlaybackExclusions::serverKey((string) Config::get('JELLYFIN_URL', ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $this->scope)) {
            throw new \InvalidArgumentException('Invalid artwork source.');
        }
        if (!$this->database->getPlatform()->isSqlite() && (bool) $this->db->query('SELECT @@in_transaction')->fetchSingle()) {
            throw new \LogicException('Initialize artwork outside an existing MariaDB transaction.');
        }
        $this->ensureSchema();
    }

    /** @return array<string, mixed> */
    public function source(): array
    {
        $row = $this->fetchCurrent('artwork_sources');
        return $row instanceof \Dibi\Row ? $row->toArray() : ['source_key' => $this->scope,
            'server_id' => '', 'blocked' => 0, 'lease_token' => null, 'lease_until' => 0, 'used_bytes' => 0, 'backfill_cursor' => 0,
            'legacy_confirmed' => 0, 'next_attempt' => 0, 'last_error' => null, 'anchored_at' => 0, 'fresh_cursor' => 0,
            'external_preference' => -1, 'external_revision' => 1, 'external_next_attempt' => 0, 'external_last_error' => null,
            'external_discovery_after' => 0];
    }

    public function anchor(string $serverId): bool
    {
        self::id($serverId);
        return $this->atomic(function () use ($serverId): bool {
            $source = $this->source();
            if ((int) $source['blocked'] !== 0) {
                return false;
            }
            if ($source['server_id'] !== '' && $source['server_id'] !== $serverId) {
                $this->db->update('artwork_sources', ['blocked' => 1, 'lease_token' => null, 'lease_until' => 0])
                    ->where('source_key = %s', $this->scope)->execute();
                return false;
            }
            $data = ['server_id' => $serverId];
            if ($source['server_id'] === '') {
                $data['anchored_at'] = (int) (microtime(true) * 1000000);
            }
            $this->db->update('artwork_sources', $data)->where('source_key = %s', $this->scope)->execute();
            return true;
        });
    }

    public function claim(int $now, int $ttl = 60): ?string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->update('artwork_sources', ['lease_token' => $token, 'lease_until' => $now + $ttl])
            ->where('source_key = %s AND blocked = 0 AND server_id <> %s', $this->scope, '')
            ->where('lease_until <= %i', $now)->execute();
        return $this->db->getAffectedRows() === 1 ? $token : null;
    }

    public function release(string $token): void
    {
        $this->db->update('artwork_sources', ['lease_token' => null, 'lease_until' => 0])
            ->where('source_key = %s AND lease_token = %s', $this->scope, $token)->execute();
    }

    /** @return array<string, mixed>|null */
    public function item(string $id): ?array
    {
        $row = $this->db->select('*')->from('artwork_items')->where('source_key = %s AND item_id = %s', $this->scope, $id)->fetch();
        return $row instanceof \Dibi\Row ? $row->toArray() : null;
    }

    /** @param array<string, mixed> $item */
    public function remember(array $item): void
    {
        self::id((string) $item['item_id']);
        $this->atomic(function () use ($item): void {
            $old = $this->item((string) $item['item_id']);
            if ($old !== null && (int) $old['observed_at'] > (int) $item['observed_at']) {
                return;
            }
            if ($old !== null && in_array($old['evidence'], ['manual', 'dismissed'], true) && $item['evidence'] !== 'manual') {
                $item['poster_id'] = $old['poster_id'];
                $item['evidence'] = $old['evidence'];
                $item['status'] = $old['evidence'] === 'manual' ? 'ready' : 'review';
            }
            if ($old !== null && $old['status'] === 'pending' && $item['status'] === 'pending'
                && $old['poster_id'] === $item['poster_id']) {
                $item['next_attempt'] = max((int) $old['next_attempt'], (int) $item['next_attempt']);
            }
            if ($old !== null && $old['status'] === 'review' && $old['poster_id'] === $item['poster_id']
                && is_string($item['poster_id']) && $this->image($item['poster_id']) === null
                && in_array($this->poster($item['poster_id'])['last_error'] ?? null, ['missing_item', 'missing_image', 'metadata'], true)) {
                $item['status'] = 'review';
            }
            $item['source_key'] = $this->scope;
            if ($old === null) {
                $this->db->insert('artwork_items', $item)->execute();
            } else {
                $this->db->update('artwork_items', $item)->where('source_key = %s AND item_id = %s', $this->scope, $item['item_id'])->execute();
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function pendingItems(int $now, int $limit = 8): array
    {
        return array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $this->db->select('*')->from('artwork_items')
            ->where('source_key = %s AND status = %s AND next_attempt <= %i', $this->scope, 'pending', $now)
            ->orderBy('next_attempt, item_id')->limit($limit)->fetchAll());
    }

    /** @param array<string, mixed> $item */
    public function rememberWithPoster(array $item, ?string $tag = null): void
    {
        $this->atomic(function () use ($item, $tag): void {
            $this->remember($item);
            $saved = $this->item((string) $item['item_id']);
            if ($saved !== null && is_string($saved['poster_id'])) {
                $this->enqueue(
                    $saved['poster_id'],
                    $saved['item_type'] === 'Movie' ? 'Movie' : 'Series',
                    $saved['poster_id'] === $item['poster_id'] ? $tag : null,
                    (int) $saved['observed_at']
                );
            }
        });
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    public function items(array $ids): array
    {
        if ($ids === [] || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Choose up to 100 History items.');
        }
        foreach ($ids as $id) {
            self::id($id);
        }
        return array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $this->db->select('*')->from('artwork_items')
            ->where('source_key = %s AND item_id IN %in', $this->scope, $ids)->orderBy('item_id')->fetchAll());
    }

    /** @param list<array<string, mixed>> $items */
    public static function fingerprint(array $items): string
    {
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['item_id'], (string) $b['item_id']));
        return hash('sha256', json_encode(array_map(static fn (array $item): array => [
            $item['item_id'], $item['item_type'], $item['title'], $item['series_name'], $item['library'] ?? '',
            $item['poster_id'], (int) $item['observed_at'], $item['evidence'], $item['status'],
        ], $items), JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $ids */
    public function matchItems(array $ids, ?string $target, string $fingerprint): void
    {
        if ($target !== null) {
            self::id($target);
        }
        $this->atomic(function () use ($ids, $target, $fingerprint): void {
            $items = $this->items($ids);
            if ((int) $this->source()['blocked'] !== 0 || count($items) !== count($ids)
                || !hash_equals(self::fingerprint($items), $fingerprint)) {
                throw new \RuntimeException('The selection changed. Refresh and try again.');
            }
            foreach ($items as $item) {
                if (($target !== null && $item['status'] !== 'review') || ($target === null && $item['evidence'] !== 'manual')) {
                    throw new \RuntimeException('The selection changed. Refresh and try again.');
                }
                $item['poster_id'] = $target;
                $item['evidence'] = $target === null ? 'dismissed' : 'manual';
                $item['status'] = $target === null ? 'review' : 'ready';
                $item['next_attempt'] = 0;
                if ($target === null) {
                    $this->db->update('artwork_items', $item)->where('source_key = %s AND item_id = %s', $this->scope, $item['item_id'])->execute();
                } else {
                    $this->rememberWithPoster($item);
                }
            }
        });
    }

    public function changePoster(string $id, int $revision, bool $clear): void
    {
        $this->atomic(function () use ($id, $revision, $clear): void {
            $poster = $this->poster($id);
            if ($poster === null || (int) $poster['revision'] !== $revision) {
                throw new \RuntimeException('The poster changed. Refresh and try again.');
            }
            if ($clear) {
                $this->clear($id);
            } else {
                $this->resume($id);
            }
        });
    }

    public function retryAll(): void
    {
        $this->atomic(function (): void {
            $this->db->update('artwork_sources', ['next_attempt' => 0, 'last_error' => null])->where('source_key = %s', $this->scope)->execute();
            $this->db->query('UPDATE artwork_posters SET next_attempt = 0, attempts = 0, last_error = NULL, revision = revision + 1 WHERE source_key = %s AND suppressed = 0', $this->scope);
            $this->db->update('artwork_items', ['next_attempt' => 0])->where('source_key = %s AND status = %s', $this->scope, 'pending')->execute();
        });
    }

    public function cursor(int $id): void
    {
        $this->atomic(function () use ($id): void {
            $this->db->update('artwork_sources', ['backfill_cursor' => $id])->where('source_key = %s', $this->scope)->execute();
        });
    }

    public function freshCursor(int $id): void
    {
        $this->atomic(function () use ($id): void {
            $this->db->update('artwork_sources', ['fresh_cursor' => $id])->where('source_key = %s', $this->scope)->execute();
        });
    }

    public function confirmLegacy(): void
    {
        $this->atomic(function (): void {
            $this->db->update('artwork_sources', ['legacy_confirmed' => 1, 'backfill_cursor' => 0, 'next_attempt' => 0])
                ->where('source_key = %s', $this->scope)->execute();
            $this->db->update('artwork_items', ['status' => 'pending', 'next_attempt' => 0])
                ->where('source_key = %s AND evidence IN %in AND status <> %s', $this->scope, ['legacy', 'provisional'], 'ready')->execute();
        });
    }

    public function defer(?string $error, int $until): void
    {
        $this->atomic(function () use ($error, $until): void {
            $this->db->update('artwork_sources', ['last_error' => $error, 'next_attempt' => $until])
                ->where('source_key = %s', $this->scope)->execute();
        });
    }

    public function enqueue(string $id, string $kind, ?string $tag, int $observedAt): void
    {
        self::id($id);
        if (!in_array($kind, ['Movie', 'Series'], true)) {
            throw new \InvalidArgumentException('Invalid artwork kind.');
        }
        $tag = $tag !== null && strlen($tag) <= 255 ? $tag : null;
        $this->atomic(function () use ($id, $kind, $tag, $observedAt): void {
            $old = $this->poster($id);
            if ($old === null) {
                $this->db->insert('artwork_posters', ['source_key' => $this->scope, 'poster_id' => $id,
                    'kind' => $kind, 'requested_tag' => $tag, 'observed_at' => $observedAt])->execute();
            } elseif ($observedAt > (int) $old['observed_at']) {
                $data = ['observed_at' => $observedAt];
                if ($tag !== null && $tag !== $old['requested_tag']) {
                    $data += ['requested_tag' => $tag, 'revision' => (int) $old['revision'] + 1, 'next_attempt' => 0];
                }
                $this->db->update('artwork_posters', $data)->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            } elseif ($observedAt === (int) $old['observed_at'] && $tag !== null && $tag !== $old['requested_tag']) {
                // Conflicting observations cannot order opaque tags. Fetch fresh metadata.
                $this->db->update('artwork_posters', ['requested_tag' => null,
                    'revision' => (int) $old['revision'] + 1, 'next_attempt' => 0])
                    ->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function poster(string $id): ?array
    {
        $row = $this->fetchCurrent('artwork_posters', $id);
        return $row instanceof \Dibi\Row ? $row->toArray() : null;
    }

    /** @return list<array<string, mixed>> */
    public function jobs(int $now, int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        $due = $this->db->select('*')->from('artwork_posters')
            ->where('source_key = %s AND suppressed = 0 AND next_attempt <= %i', $this->scope, $now)
            ->where('last_checked > 0')->orderBy('next_attempt, poster_id')->limit(1)->fetchAll();
        if (count($due) < $limit) {
            $new = $this->db->select('*')->from('artwork_posters')
                ->where('source_key = %s AND suppressed = 0 AND next_attempt <= %i', $this->scope, $now);
            if ($due !== []) {
                $new->where('poster_id <> %s', $due[0]['poster_id']);
            }
            $due = [...$due, ...$new->orderBy('next_attempt, last_checked, poster_id')->limit($limit - count($due))->fetchAll()];
        }
        return array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $due);
    }

    /** @param array<string, mixed> $job */
    public function publish(array $job, string $body, string $contentType, string $lease, int $now, int $budget = self::DEFAULT_BUDGET): bool
    {
        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES || \Mk\Framework\Http\ImageContentType::normalize($contentType) === null) {
            throw new \InvalidArgumentException('Invalid retained image.');
        }
        return $this->atomic(function () use ($job, $body, $contentType, $lease, $now, $budget): bool {
            if (!$this->owns($lease, $now) || !$this->current($job)) {
                return false;
            }
            $id = (string) $job['poster_id'];
            $old = $this->image($id);
            $used = (int) $this->source()['used_bytes'] - (int) ($old['body_size'] ?? 0) + strlen($body);
            if ($used > $budget) {
                $this->retry($job, 'capacity', $lease, $now, 3600);
                return false;
            }
            $data = ['body%bin' => $body, 'body_size' => strlen($body), 'content_type' => $contentType,
                'tag' => $job['requested_tag'], 'sha256' => hash('sha256', $body), 'saved_at' => $now];
            if ($old === null) {
                $this->db->insert('artwork_images', $data + ['source_key' => $this->scope, 'poster_id' => $id])->execute();
            } else {
                $this->db->update('artwork_images', $data)->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            }
            $this->db->update('artwork_sources', ['used_bytes' => $used])->where('source_key = %s', $this->scope)->execute();
            $this->db->update('artwork_posters', ['attempts' => 0, 'next_attempt' => $now + 86400,
                'last_checked' => $now, 'last_error' => null, 'revision' => (int) $job['revision'] + 1])
                ->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            $this->db->update('artwork_items', ['status' => 'ready'])->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            return true;
        });
    }

    /** @param array<string, mixed> $job */
    public function retry(array $job, ?string $error, string $lease, int $now, int $delay): void
    {
        $this->atomic(function () use ($job, $error, $lease, $now, $delay): void {
            if (!$this->owns($lease, $now) || !$this->current($job)) {
                return;
            }
            $this->db->update('artwork_posters', ['attempts' => $error === null ? 0 : (int) $job['attempts'] + 1,
                'next_attempt' => $now + max(1, $delay), 'last_checked' => $now, 'last_error' => $error])
                ->where('source_key = %s AND poster_id = %s', $this->scope, $job['poster_id'])->execute();
            if (in_array($error, ['missing_item', 'missing_image', 'metadata'], true) && $this->image((string) $job['poster_id']) === null) {
                $this->db->update('artwork_items', ['status' => 'review'])->where('source_key = %s AND poster_id = %s', $this->scope, $job['poster_id'])->execute();
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function image(string $id): ?array
    {
        $source = $this->source();
        if ((int) $source['blocked'] !== 0 || $source['server_id'] === '') {
            return null;
        }
        $row = $this->fetchCurrent('artwork_images', $id);
        return $row instanceof \Dibi\Row ? $row->toArray() : null;
    }

    public function clear(string $id): void
    {
        $this->atomic(function () use ($id): void {
            $bytes = (int) $this->db->select('body_size')->from('artwork_images')
                ->where('source_key = %s AND poster_id = %s', $this->scope, $id)->fetchSingle();
            $this->db->delete('artwork_images')->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            $this->db->query('UPDATE artwork_sources SET used_bytes = used_bytes - %i WHERE source_key = %s', $bytes, $this->scope);
            $this->db->query('UPDATE artwork_posters SET suppressed = 1, revision = revision + 1 WHERE source_key = %s AND poster_id = %s', $this->scope, $id);
            $this->db->query("UPDATE artwork_external_bindings SET suppressed = 1, suppressed_by = 'jellyfin_clear', state = 'suppressed', revision = revision + 1 WHERE source_key = %s AND suppressed = 0 AND item_id IN (SELECT item_id FROM artwork_items WHERE source_key = %s AND poster_id = %s)", $this->scope, $this->scope, $id);
        });
    }

    public function resume(string $id): void
    {
        $this->atomic(function () use ($id): void {
            $this->db->query('UPDATE artwork_posters SET suppressed = 0, next_attempt = 0, revision = revision + 1 WHERE source_key = %s AND poster_id = %s', $this->scope, $id);
            $this->db->query("UPDATE artwork_external_bindings SET suppressed = 0, suppressed_by = '', state = 'review', revision = revision + 1, next_attempt = 0 WHERE source_key = %s AND suppressed_by = 'jellyfin_clear' AND item_id IN (SELECT item_id FROM artwork_items WHERE source_key = %s AND poster_id = %s)", $this->scope, $this->scope, $id);
        });
    }

    /** @param array<string, mixed> $job */
    private function current(array $job): bool
    {
        $poster = $this->poster((string) $job['poster_id']);
        return ($job['source_key'] ?? null) === $this->scope && $poster !== null
            && (int) $poster['suppressed'] === 0 && (int) $poster['revision'] === (int) $job['revision'];
    }

    private function owns(string $token, int $now): bool
    {
        $source = $this->source();
        return (int) $source['blocked'] === 0 && $source['server_id'] !== ''
            && $source['lease_token'] === $token && (int) $source['lease_until'] > $now;
    }

    private function fetchCurrent(string $table, ?string $id = null): ?\Dibi\Row
    {
        $sql = 'SELECT * FROM %n WHERE source_key = %s';
        $arguments = [$table, $this->scope];
        if ($id !== null) {
            $sql .= ' AND poster_id = %s';
            $arguments[] = $id;
        }
        if ($this->inTransaction && !$this->database->getPlatform()->isSqlite()) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->query($sql, ...$arguments)->fetch();
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function atomic(\Closure $operation): mixed
    {
        if ($this->inTransaction) {
            return $operation();
        }
        if (!$this->database->getPlatform()->isSqlite() && (bool) $this->db->query('SELECT @@in_transaction')->fetchSingle()) {
            throw new \LogicException('Artwork writes require their own MariaDB transaction.');
        }
        $savepoint = $this->database->getPlatform()->isSqlite() ? 'artwork_' . bin2hex(random_bytes(8)) : null;
        if ($savepoint === null && $this->externalResolver !== null) {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->db->begin($savepoint);
        $this->inTransaction = true;
        try {
            $this->db->query($this->database->getPlatform()->isSqlite()
                ? 'INSERT INTO artwork_sources (source_key) VALUES (%s) ON CONFLICT (source_key) DO NOTHING'
                : 'INSERT IGNORE INTO artwork_sources (source_key) VALUES (%s)', $this->scope);
            $this->db->query('UPDATE artwork_sources SET used_bytes = used_bytes WHERE source_key = %s', $this->scope);
            if (!$this->database->getPlatform()->isSqlite() && $this->externalResolver !== null) {
                // Serialize the external commit with independent server-identity quarantine.
                $this->db->query('SELECT server_key FROM movie_identity_state WHERE server_key = %s FOR UPDATE', $this->scope)->fetch();
            }
            $result = $operation();
            $this->db->commit($savepoint);
            return $result;
        } catch (\Throwable $error) {
            try {
                $this->db->rollback($savepoint);
                if ($savepoint !== null) {
                    $this->db->commit($savepoint);
                }
            } catch (\Throwable) {
                // The driver may already have rolled back a failed transaction.
            }
            throw $error;
        } finally {
            $this->inTransaction = false;
        }
    }

    public static function id(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid artwork item identity.');
        }
    }

    public static function budget(): int
    {
        $mb = filter_var(Config::get('ARTWORK_STORAGE_LIMIT_MB', '512'), FILTER_VALIDATE_INT);
        return ($mb !== false && $mb >= 1 && $mb <= 65536 ? $mb : 512) * 1048576;
    }

    public function externalEnabled(): bool
    {
        $value = (int) ($this->source()['external_preference'] ?? -1);
        return $value === -1 ? Config::bool('ARTWORK_EXTERNAL_RECOVERY', true) : $value === 1;
    }

    public function externalBlocked(): bool
    {
        return $this->externalResolver()->blocked();
    }

    /** @return array{key: string, kind: string, title: string}|null */
    public function discoveryInput(string $id): ?array
    {
        $item = $this->item($id);
        if ($item === null || !in_array($item['item_type'], ['Movie', 'Episode'], true)) {
            return null;
        }
        $title = trim((string) ($item['item_type'] === 'Episode' ? $item['series_name'] : $item['title']));
        if ($title === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 160 || preg_match('/[\x00-\x1F\x7F]/u', $title)) {
            return null;
        }
        $kind = $item['item_type'] === 'Episode' ? 'Series' : 'Movie';
        $source = $this->source();
        return ['key' => hash('sha256', json_encode([$this->scope, $source['server_id'], (int) $source['external_revision'], $kind, $title], JSON_THROW_ON_ERROR)),
            'kind' => $kind, 'title' => $title];
    }

    /** @return array<string, mixed>|null */
    public function discoveryRecord(string $key, int $now): ?array
    {
        if ($this->externalBlocked()) {
            return null;
        }
        $source = $this->source();
        $row = $this->db->select('*')->from('artwork_external_searches')->where(
            'source_key = %s AND query_key = %s AND server_id = %s AND config_revision = %i AND expires_at > %i',
            $this->scope,
            $key,
            $source['server_id'],
            (int) $source['external_revision'],
            $now
        )->fetch();
        if ($row === null || strlen((string) $row['candidates_json']) > 32768) {
            return null;
        }
        $candidates = json_decode((string) $row['candidates_json'], true);
        if (!is_array($candidates) || !array_is_list($candidates) || count($candidates) > 5) {
            return null;
        }
        return $row->toArray() + ['candidates' => $candidates];
    }

    /** @param array<string, mixed> $job */
    private function discoveryJobIsCurrent(array $job, string $lease, int $now): bool
    {
        $current = $this->externalBinding((string) $job['item_id']);
        $item = $this->item((string) $job['item_id']);
        return $this->owns($lease, $now) && $this->externalEnabled() && !$this->externalBlocked() && $current !== null && $item !== null
            && (int) $current['revision'] === (int) $job['revision'] && (int) $current['config_revision'] === (int) $job['config_revision']
            && $current['context_key'] === ($job['context_key'] ?? null) && $current['origin'] === 'automatic'
            && $current['verified_id'] === null && $current['tmdb_id'] === null && (int) $current['identity_conflict'] === 0
            && (int) $current['presence'] === 0 && (int) $current['suppressed'] === 0 && (int) $current['snoozed_until'] <= $now
            && ((int) $this->source()['legacy_confirmed'] !== 0 || in_array($item['evidence'], ['observed', 'manual'], true))
            && $this->externalResolver()->jellyfinImage((string) $job['item_id'], $item['item_type'] === 'Episode') === null;
    }

    /** @param array<string, mixed> $job @param array{key: string, kind: string, title: string} $input */
    public function reserveDiscovery(array $job, array $input, string $lease, int $now): bool
    {
        $this->externalResolver();
        $started = hrtime(true);
        return $this->atomic(function () use ($job, $input, $lease, $now, $started): bool {
            $now += (int) ((hrtime(true) - $started) / 1e9);
            if (!$this->discoveryJobIsCurrent($job, $lease, $now) || $this->discoveryInput((string) $job['item_id']) !== $input
                || (int) $this->externalBinding((string) $job['item_id'])['discovery_after'] > $now
                || (int) $this->source()['external_next_attempt'] > $now
                || (int) ($this->source()['external_discovery_after'] ?? 0) > $now) {
                return false;
            }
            if ($this->discoveryRecord($input['key'], $now) !== null) {
                return false;
            }
            $old = $this->db->select('revision')->from('artwork_external_searches')->where('source_key = %s AND query_key = %s', $this->scope, $input['key'])->fetchSingle();
            if ($old === false || $old === null) {
                $count = (int) $this->db->select('COUNT(*)')->from('artwork_external_searches')->where('source_key = %s', $this->scope)->fetchSingle();
                if ($count >= 100) {
                    $oldest = $this->db->select('query_key')->from('artwork_external_searches')->where('source_key = %s', $this->scope)->orderBy('expires_at, acquired_at, query_key')->limit(1)->fetchSingle();
                    $this->db->delete('artwork_external_searches')->where('source_key = %s AND query_key = %s', $this->scope, $oldest)->execute();
                }
            }
            $data = ['source_key' => $this->scope, 'query_key' => $input['key'], 'server_id' => $this->source()['server_id'],
                'config_revision' => (int) $job['config_revision'], 'kind' => $input['kind'], 'query_title' => $input['title'],
                'acquired_at' => $now, 'expires_at' => $now + 900, 'candidates_json' => '[]', 'last_error' => 'pending', 'revision' => (int) $old + 1];
            $this->db->delete('artwork_external_searches')->where('source_key = %s AND query_key = %s', $this->scope, $input['key'])->execute();
            $this->db->insert('artwork_external_searches', $data)->execute();
            $this->db->update('artwork_sources', ['external_discovery_after' => $now + 60])->where('source_key = %s', $this->scope)->execute();
            return true;
        });
    }

    /** @param array<string, mixed> $job @param array{key: string, kind: string, title: string} $input @param list<array<string, mixed>> $candidates */
    public function completeDiscovery(array $job, array $input, array $candidates, string $lease, int $now, ?string $error = null, int $delay = 900): bool
    {
        $this->externalResolver();
        $json = json_encode($candidates, JSON_THROW_ON_ERROR);
        if (count($candidates) > 5 || strlen($json) > 32768) {
            throw new \InvalidArgumentException('Too many discovery candidates.');
        }
        $started = hrtime(true);
        return $this->atomic(function () use ($job, $input, $candidates, $json, $lease, $now, $error, $delay, $started): bool {
            $now += (int) ((hrtime(true) - $started) / 1e9);
            if (!$this->discoveryJobIsCurrent($job, $lease, $now) || $this->discoveryInput((string) $job['item_id']) !== $input) {
                return false;
            }
            $record = $this->discoveryRecord($input['key'], $now);
            if ($record === null || $record['last_error'] !== 'pending') {
                return false;
            }
            $expires = $candidates === [] ? $now + max(900, min(86400, $delay)) : (int) $record['acquired_at'] + 604800;
            foreach ($candidates as $candidate) {
                $expires = min($expires, (int) ($candidate['expires_at'] ?? 0));
            }
            if ($expires <= $now) {
                return false;
            }
            $this->db->update('artwork_external_searches', ['candidates_json' => $json, 'expires_at' => $expires, 'last_error' => $error,
                'revision' => (int) $record['revision'] + 1])->where('source_key = %s AND query_key = %s', $this->scope, $input['key'])->execute();
            $this->deferDiscovery($job, $lease, $now, $expires);
            return true;
        });
    }

    /** Scheduler-only state does not invalidate a manager's selection fingerprint.
     * @param array<string, mixed> $job
     */
    public function deferDiscovery(array $job, string $lease, int $now, int $until): void
    {
        $this->externalResolver();
        $started = hrtime(true);
        $this->atomic(function () use ($job, $lease, $now, $until, $started): void {
            $now += (int) ((hrtime(true) - $started) / 1e9);
            if (!$this->discoveryJobIsCurrent($job, $lease, $now)) {
                return;
            }
            $this->db->update('artwork_external_bindings', ['discovery_after' => $until, 'next_attempt' => min($until, $now + 86400)])
                ->where('source_key = %s AND item_id = %s', $this->scope, $job['item_id'])->execute();
        });
    }

    /** @param array<string, mixed> $meta */
    public function notePosterMetadata(string $id, array $meta, string $lease, int $now, ?int $presence = null): void
    {
        $this->atomic(function () use ($id, $meta, $lease, $now, $presence): void {
            $poster = $this->poster($id);
            if (!$this->owns($lease, $now) || $poster === null || ($meta['Type'] ?? null) !== $poster['kind']
                || !is_string($meta['Id'] ?? null) || ThemePlaybackExclusions::canonicalItemId($meta['Id']) !== ThemePlaybackExclusions::canonicalItemId($id)
                || (isset($meta['ServerId']) && $meta['ServerId'] !== $this->source()['server_id'])) {
                return;
            }
            $providers = $meta['ProviderIds'] ?? [];
            $ids = [];
            if (is_array($providers)) {
                foreach ($providers as $key => $value) {
                    if (is_string($key) && strcasecmp($key, 'Tmdb') === 0 && is_string($value)
                        && preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
                        $ids[] = $value;
                    }
                }
            }
            $tmdb = count($ids) === 1 ? $ids[0] : null;
            $conflict = (int) $poster['provider_conflict'] !== 0
                || ($tmdb !== null && $poster['provider_tmdb_id'] !== null && $tmdb !== $poster['provider_tmdb_id']) || count($ids) > 1;
            if ($presence === null && is_array($meta['ImageTags'] ?? null)) {
                $tag = $meta['ImageTags']['Primary'] ?? null;
                $presence = $tag === null ? 0 : (is_string($tag) && $tag !== '' ? 1 : null);
            }
            $data = ['provider_conflict' => (int) $conflict, 'provider_tmdb_id' => $poster['provider_tmdb_id'] ?? $tmdb,
                'provider_title' => is_string($meta['Name'] ?? null) ? mb_substr($meta['Name'], 0, 16383) : $poster['provider_title'],
                'provider_year' => is_int($meta['ProductionYear'] ?? null) ? $meta['ProductionYear'] : $poster['provider_year']];
            if ($presence !== null && in_array($presence, [0, 1], true)) {
                $data['presence'] = $presence;
                $data['presence_checked'] = $now;
            }
            $this->db->update('artwork_posters', $data)->where('source_key = %s AND poster_id = %s', $this->scope, $id)->execute();
            if ($conflict) {
                $this->db->query("UPDATE artwork_external_bindings SET verified_id = NULL, identity_conflict = 1, tmdb_id = NULL, poster_path = NULL, asset_key = NULL, selected_title = '', selected_year = NULL, expires_at = 0, state = CASE WHEN suppressed = 1 THEN 'suppressed' WHEN presence = 1 THEN 'present' ELSE 'review' END, revision = revision + 1 WHERE source_key = %s AND origin = 'automatic' AND (identity_conflict = 0 OR tmdb_id IS NOT NULL) AND item_id IN (SELECT item_id FROM artwork_items WHERE source_key = %s AND poster_id = %s)", $this->scope, $this->scope, $id);
            }
            if ($presence === 1 && !$conflict) {
                $this->db->query("UPDATE artwork_external_bindings SET presence = 1, state = CASE WHEN suppressed = 1 THEN 'suppressed' ELSE 'present' END, revision = revision + 1 WHERE source_key = %s AND presence <> 1 AND item_id IN (SELECT item_id FROM artwork_items WHERE source_key = %s AND poster_id = %s)", $this->scope, $this->scope, $id);
            }
        });
    }

    public function configureExternal(bool $enabled, ?int $expectedRevision = null): void
    {
        $this->atomic(function () use ($enabled, $expectedRevision): void {
            if ($expectedRevision !== null && (int) $this->source()['external_revision'] !== $expectedRevision) {
                throw new \RuntimeException('The poster settings changed.');
            }
            $this->db->query('UPDATE artwork_sources SET external_preference = %i, external_revision = external_revision + 1 WHERE source_key = %s', (int) $enabled, $this->scope);
            if ($enabled) {
                $this->db->query("UPDATE artwork_external_bindings SET discovery_after = 0, next_attempt = 0 WHERE source_key = %s AND origin = 'automatic' AND verified_id IS NULL AND tmdb_id IS NULL AND identity_conflict = 0 AND suppressed = 0", $this->scope);
            }
        });
    }

    public function deferExternalSource(string $lease, int $now, ?string $reason, int $until): void
    {
        $this->atomic(function () use ($lease, $now, $reason, $until): void {
            if ($this->owns($lease, $now)) {
                $current = (int) $this->source()['external_next_attempt'];
                if ($until === 0 && $current > $now) {
                    return;
                }
                $this->db->update('artwork_sources', ['external_next_attempt' => $until === 0 ? 0 : max($until, $current), 'external_last_error' => $reason])
                    ->where('source_key = %s', $this->scope)->execute();
            }
        });
    }

    public function externalNetworkFailure(string $serverId, int $revision, string $reason, int $until): void
    {
        $this->atomic(function () use ($serverId, $revision, $reason, $until): void {
            $source = $this->source();
            if ($source['server_id'] === $serverId && (int) $source['external_revision'] === $revision && (int) $source['blocked'] === 0) {
                $this->db->update('artwork_sources', ['external_next_attempt' => max($until, (int) $source['external_next_attempt']), 'external_last_error' => $reason])
                    ->where('source_key = %s', $this->scope)->execute();
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function externalBinding(string $id): ?array
    {
        $this->externalResolver();
        $row = $this->db->select('*')->from('artwork_external_bindings')->where('source_key = %s AND item_id = %s', $this->scope, $id)->fetch();
        if ($row === null) {
            return null;
        }
        $data = $row->toArray();
        if ((int) $data['expires_at'] <= time()) {
            $data['tmdb_id'] = $data['poster_path'] = $data['asset_key'] = $data['selected_year'] = null;
            $data['selected_title'] = '';
            $data['state'] = (int) $data['suppressed'] !== 0 ? 'suppressed' : ((int) $data['presence'] === 1 ? 'present' : 'review');
        }
        return $data + ['config_revision' => (int) ($this->source()['external_revision'] ?? 1), 'context_key' => $this->externalContextKey($id)];
    }

    /** Observations come only from a verified Jellyfin lookup, not provider search. */
    public function externalObserve(string $id, ?int $presence = null, ?string $verifiedId = null, bool $conflicted = false): void
    {
        $this->externalResolver();
        if ($presence !== null && !in_array($presence, [-1, 0, 1], true)) {
            throw new \InvalidArgumentException('Invalid poster presence.');
        }
        if ($verifiedId !== null) {
            ExternalPosterProvider::providerId($verifiedId);
        }
        $this->atomic(function () use ($id, $presence, $verifiedId, $conflicted): void {
            $source = $this->source();
            $item = $this->item($id);
            if ($this->externalBlocked() || $source['server_id'] === '' || $item === null
                || !in_array($item['item_type'], ['Movie', 'Episode'], true)) {
                throw new \RuntimeException('Artwork identity unavailable.');
            }
            $identity = self::externalIdentityKey($item);
            $old = $this->externalBinding($id);
            $data = $old ?? ['source_key' => $this->scope, 'item_id' => $id, 'identity_key' => $identity,
                'kind' => $item['item_type'] === 'Episode' ? 'Series' : 'Movie', 'verified_id' => null,
                'identity_conflict' => 0, 'tmdb_id' => null, 'poster_path' => null, 'asset_key' => null,
                'presence' => -1, 'state' => 'review', 'origin' => 'automatic', 'suppressed' => 0,
                'suppressed_by' => '', 'selected_title' => '', 'selected_year' => null,
                'snoozed_until' => 0, 'revision' => 1, 'next_attempt' => 0, 'attempts' => 0, 'last_error' => null];
            $data['expires_at'] ??= 0;
            $data['discovery_after'] ??= 0;
            unset($data['config_revision'], $data['context_key']);
            $changedIdentity = $data['identity_key'] !== $identity;
            $changedId = $verifiedId !== null && $data['verified_id'] !== null && $data['verified_id'] !== $verifiedId;
            $newConflict = $conflicted && (int) $data['identity_conflict'] === 0;
            if ($changedIdentity) {
                $data['verified_id'] = null;
                $data['presence'] = -1;
                $data['discovery_after'] = 0;
            }
            if ($changedId || $conflicted) {
                $data['identity_conflict'] = 1;
                $data['verified_id'] = null;
            }
            if ($verifiedId !== null && (int) $data['identity_conflict'] === 0) {
                $data['verified_id'] = $verifiedId;
            }
            if ($presence !== null && $presence !== -1) {
                $data['presence'] = $presence;
            }
            $data['identity_key'] = $identity;
            if ($changedIdentity || $changedId || $newConflict || ($conflicted && $data['origin'] !== 'manual')) {
                $data['snoozed_until'] = 0;
                $data['tmdb_id'] = null;
                $data['poster_path'] = null;
                $data['asset_key'] = null;
                $data['selected_title'] = '';
                $data['selected_year'] = null;
                $data['expires_at'] = 0;
                $data['last_error'] = null;
            }
            if (is_string($item['poster_id']) && (int) ($this->poster($item['poster_id'])['suppressed'] ?? 0) !== 0) {
                if ((int) $data['suppressed'] === 0) {
                    $data['suppressed_by'] = 'jellyfin_clear';
                }
                $data['suppressed'] = 1;
            }
            $data['state'] = (int) $data['suppressed'] !== 0 ? 'suppressed'
                : ((int) $data['presence'] === 1 ? 'present' : ($data['tmdb_id'] === null ? 'review'
                    : ($data['asset_key'] === null ? 'pending' : 'saved')));
            if ($old === null) {
                $this->db->insert('artwork_external_bindings', $data)->execute();
            } elseif (array_diff_assoc($data, array_diff_key($old, ['config_revision' => true, 'context_key' => true])) !== []) {
                ++$data['revision'];
                $this->db->update('artwork_external_bindings', $data)->where('source_key = %s AND item_id = %s', $this->scope, $id)->execute();
            }
        });
    }

    /** @param list<string> $ids */
    public function externalFingerprint(array $ids): string
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        if ($ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException('Choose up to 100 historical items.');
        }
        $rows = [];
        foreach ($ids as $id) {
            self::id($id);
            $row = $this->externalBinding($id);
            if ($row === null) {
                throw new \RuntimeException('The poster selection changed.');
            }
            $rows[] = [$id, $row['identity_key'], $row['context_key'], (int) $row['revision'], (int) $row['config_revision']];
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $ids @param array<string, mixed> $candidate */
    public function externalSelect(array $ids, string $fingerprint, array $candidate, bool $automatic = false, ?\Closure $guard = null): void
    {
        if (!is_string($candidate['id'] ?? null) || !is_string($candidate['path'] ?? null)
            || !in_array($candidate['kind'] ?? null, ['Movie', 'Series'], true)
            || ExternalPosterProvider::posterPath('https://image.tmdb.org/t/p/w342/' . $candidate['path']) !== $candidate['path']) {
            throw new \InvalidArgumentException('Invalid external poster.');
        }
        ExternalPosterProvider::providerId($candidate['id']);
        $resolver = $this->externalResolver();
        $this->atomic(function () use ($ids, $fingerprint, $candidate, $automatic, $resolver, $guard): void {
            if ($guard !== null) {
                $guard();
            }
            if (!$this->externalEnabled() || $resolver->blocked()
                || !hash_equals($this->externalFingerprint($ids), $fingerprint)) {
                throw new \RuntimeException('The poster selection changed.');
            }
            foreach (array_unique($ids) as $id) {
                $row = $this->externalBinding($id);
                $item = $this->item($id);
                if ($row === null || (int) $row['suppressed'] !== 0 || (int) $row['presence'] !== 0 || $row['kind'] !== $candidate['kind']
                    || $item === null || $row['identity_key'] !== self::externalIdentityKey($item)
                    || $resolver->jellyfinImage($id, $item['item_type'] === 'Episode') !== null
                    || ($automatic && ((int) $row['identity_conflict'] !== 0 || $row['verified_id'] !== $candidate['id']))) {
                    throw new \RuntimeException('This historical item needs review.');
                }
                $this->db->update('artwork_external_bindings', ['tmdb_id' => $candidate['id'], 'poster_path' => $candidate['path'],
                    'selected_title' => mb_substr((string) ($candidate['title'] ?? ''), 0, 16383),
                    'selected_year' => is_int($candidate['year'] ?? null) ? $candidate['year'] : null,
                    'origin' => $automatic ? 'automatic' : 'manual', 'asset_key' => null, 'state' => 'pending',
                    'expires_at' => min((int) ($candidate['expires_at'] ?? (time() + self::EXTERNAL_LIFETIME)), time() + self::EXTERNAL_LIFETIME),
                    'revision' => (int) $row['revision'] + 1, 'next_attempt' => 0, 'attempts' => 0, 'last_error' => null,
                    'snoozed_until' => 0])->where('source_key = %s AND item_id = %s', $this->scope, $id)->execute();
            }
        });
    }

    /** @param list<string> $ids */
    public function externalSuppress(array $ids, string $fingerprint, bool $suppress, ?\Closure $guard = null): void
    {
        $this->externalResolver();
        $this->atomic(function () use ($ids, $fingerprint, $suppress, $guard): void {
            if ($guard !== null) {
                $guard();
            }
            if ($this->externalBlocked() || !hash_equals($this->externalFingerprint($ids), $fingerprint)) {
                throw new \RuntimeException('The poster selection changed.');
            }
            foreach ($ids as $id) {
                $this->db->query(
                    'UPDATE artwork_external_bindings SET suppressed = %i, suppressed_by = %s, state = %s, revision = revision + 1, next_attempt = 0 WHERE source_key = %s AND item_id = %s',
                    (int) $suppress,
                    $suppress ? 'selected' : '',
                    $suppress ? 'suppressed' : 'review',
                    $this->scope,
                    $id
                );
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function externalImage(string $id): ?array
    {
        $source = $this->source();
        $binding = $this->externalBinding($id);
        $item = $this->item($id);
        if ($binding !== null && $item !== null && $binding['origin'] === 'automatic') {
            $target = $this->externalResolver()->target($id, $item['item_type'] === 'Episode') ?? $id;
            foreach (array_unique([$target, is_string($item['poster_id']) ? $item['poster_id'] : $id]) as $posterId) {
                if ((int) ($this->poster($posterId)['provider_conflict'] ?? 0) !== 0) {
                    return null;
                }
            }
        }
        if ($this->externalBlocked() || $source['server_id'] === '' || $binding === null || $item === null
            || $binding['identity_key'] !== self::externalIdentityKey($item)
            || ($binding['origin'] === 'automatic' && ((int) $binding['identity_conflict'] !== 0 || $binding['verified_id'] !== $binding['tmdb_id']))
            || (int) $binding['suppressed'] !== 0 || (int) $binding['presence'] !== 0
            || $binding['state'] !== 'saved' || !is_string($binding['asset_key'])) {
            return null;
        }
        $row = $this->db->query(
            'SELECT a.*, b.body FROM artwork_external_assets a JOIN artwork_external_bodies b ON a.source_key = b.source_key AND a.asset_key = b.asset_key WHERE a.source_key = %s AND a.asset_key = %s AND a.expires_at > %i',
            $this->scope,
            $binding['asset_key'],
            time()
        )->fetch();
        return $row === null ? null : $row->toArray();
    }

    /** @param array<string, mixed> $job @param array{body: string, contentType: string} $image */
    public function publishExternal(array $job, array $image, string $lease, int $now, int $budget = self::DEFAULT_BUDGET): bool
    {
        $this->externalResolver();
        if ($image['body'] === '' || strlen($image['body']) > self::MAX_IMAGE_BYTES
            || \Mk\Framework\Http\ImageContentType::normalize($image['contentType']) === null) {
            throw new \InvalidArgumentException('Invalid poster image.');
        }
        return $this->atomic(function () use ($job, $image, $lease, $now, $budget): bool {
            $current = $this->externalBinding((string) $job['item_id']);
            $source = $this->source();
            if (!$this->owns($lease, $now) || !$this->externalEnabled() || $this->externalBlocked() || $current === null
                || (int) $current['revision'] !== (int) $job['revision']
                || (int) $current['config_revision'] !== (int) $job['config_revision']
                || $current['context_key'] !== ($job['context_key'] ?? null)
                || (int) $current['presence'] !== 0 || (int) $current['suppressed'] !== 0
                || $current['state'] !== 'pending' || !is_string($current['tmdb_id']) || !is_string($current['poster_path'])) {
                return false;
            }
            if ($current['origin'] === 'automatic' && ((int) $current['identity_conflict'] !== 0 || $current['verified_id'] !== $current['tmdb_id'])) {
                return false;
            }
            $item = $this->item((string) $job['item_id']);
            if ($item === null || $current['identity_key'] !== self::externalIdentityKey($item)) {
                return false;
            }
            if (is_string($item['poster_id'])
                && ($this->image($item['poster_id']) !== null || (int) ($this->poster($item['poster_id'])['suppressed'] ?? 0) !== 0)) {
                return false;
            }
            $key = hash('sha256', $current['kind'] . ':' . $current['tmdb_id'] . ':' . $current['poster_path']);
            $exists = $this->db->select('asset_key, expires_at')->from('artwork_external_assets')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->fetch();
            if ($exists !== null && (int) $exists['expires_at'] <= time()) {
                return false;
            }
            if ($exists === null) {
                $used = (int) $source['used_bytes'] + strlen($image['body']);
                if ($used > $budget) {
                    $this->db->update('artwork_external_bindings', ['last_error' => 'capacity', 'next_attempt' => $now + 3600])
                        ->where('source_key = %s AND item_id = %s', $this->scope, $job['item_id'])->execute();
                    return false;
                }
                $this->db->insert('artwork_external_assets', ['source_key' => $this->scope, 'asset_key' => $key,
                    'kind' => $current['kind'], 'provider_id' => $current['tmdb_id'], 'poster_path' => $current['poster_path'],
                    'title' => $current['selected_title'],
                    'year' => $current['selected_year'], 'body_size' => strlen($image['body']), 'content_type' => $image['contentType'],
                    'sha256' => hash('sha256', $image['body']), 'saved_at' => $now, 'expires_at' => min((int) $current['expires_at'], time() + self::EXTERNAL_LIFETIME)])->execute();
                $this->db->insert('artwork_external_bodies', ['source_key' => $this->scope, 'asset_key' => $key, 'body%bin' => $image['body']])->execute();
                $this->db->update('artwork_sources', ['used_bytes' => $used])->where('source_key = %s', $this->scope)->execute();
            }
            $this->db->update('artwork_external_bindings', ['asset_key' => $key, 'state' => 'saved',
                'expires_at' => min((int) $current['expires_at'], (int) ($exists['expires_at'] ?? (time() + self::EXTERNAL_LIFETIME))),
                'revision' => (int) $current['revision'] + 1, 'last_error' => null, 'attempts' => 0, 'next_attempt' => $now + 86400])
                ->where('source_key = %s AND item_id = %s', $this->scope, $job['item_id'])->execute();
            $this->db->query('UPDATE artwork_external_assets SET revision = revision + 1 WHERE source_key = %s AND asset_key = %s', $this->scope, $key);
            return true;
        });
    }

    /** @param array<string, mixed> $item */
    private static function externalIdentityKey(array $item): string
    {
        return hash('sha256', json_encode([$item['item_id'], $item['item_type'], $item['title'],
            $item['series_name'], $item['library'], $item['poster_id']], JSON_THROW_ON_ERROR));
    }

    /** Fresh ID-only metadata and validated bytes renew a shared external image together.
     * @param array<string, mixed> $job @param array<string, mixed> $candidate
     * @param array{body: string, contentType: string} $image
     */
    public function renewExternal(array $job, array $candidate, array $image, string $assetFingerprint, string $lease, int $now, int $budget = self::DEFAULT_BUDGET): bool
    {
        $this->externalResolver();
        $image = ArtworkFetcher::validate($image['body'], $image['contentType']);
        return $this->atomic(function () use ($job, $candidate, $image, $assetFingerprint, $lease, $now, $budget): bool {
            $current = $this->externalBinding((string) $job['item_id']);
            if (!$this->owns($lease, $now) || !$this->externalEnabled() || $this->externalBlocked() || $current === null
                || (int) $current['revision'] !== (int) $job['revision'] || $current['context_key'] !== ($job['context_key'] ?? null)
                || (int) $current['config_revision'] !== (int) $job['config_revision'] || !is_string($current['asset_key'])
                || (int) $current['presence'] !== 0 || (int) $current['suppressed'] !== 0
                || ($candidate['id'] ?? null) !== $current['tmdb_id'] || ($candidate['kind'] ?? null) !== $current['kind']
                || !is_string($candidate['path'] ?? null)
                || ExternalPosterProvider::posterPath('https://image.tmdb.org/t/p/w342/' . $candidate['path']) !== $candidate['path']
                || !hash_equals($this->externalAssetFingerprint($current['asset_key']), $assetFingerprint)) {
                return false;
            }
            $oldKey = $current['asset_key'];
            $old = $this->externalAsset($oldKey);
            if ($old === null || $this->externalResolver()->jellyfinImage((string) $job['item_id'], $current['kind'] === 'Series') !== null) {
                return false;
            }
            $newKey = hash('sha256', $current['kind'] . ':' . $current['tmdb_id'] . ':' . $candidate['path']);
            $other = $newKey !== $oldKey ? $this->db->select('body_size, revision')->from('artwork_external_assets')
                ->where('source_key = %s AND asset_key = %s', $this->scope, $newKey)->fetch()?->toArray() : null;
            $used = (int) $this->source()['used_bytes'] - (int) $old['body_size'] - (int) ($other['body_size'] ?? 0) + strlen($image['body']);
            if ($used > $budget) {
                return false;
            }
            $expires = min((int) ($candidate['expires_at'] ?? (time() + self::EXTERNAL_LIFETIME)), time() + self::EXTERNAL_LIFETIME);
            foreach (array_unique([$oldKey, $newKey]) as $key) {
                $this->db->delete('artwork_external_bodies')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->execute();
                $this->db->delete('artwork_external_assets')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->execute();
            }
            $this->db->insert('artwork_external_assets', ['source_key' => $this->scope, 'asset_key' => $newKey,
                'revision' => max((int) $old['revision'], (int) ($other['revision'] ?? 0)) + 1,
                'kind' => $current['kind'], 'provider_id' => $current['tmdb_id'], 'poster_path' => $candidate['path'],
                'title' => mb_substr((string) ($candidate['title'] ?? ''), 0, 16383), 'year' => $candidate['year'] ?? null,
                'body_size' => strlen($image['body']), 'content_type' => $image['contentType'], 'sha256' => hash('sha256', $image['body']),
                'saved_at' => $now, 'expires_at' => $expires])->execute();
            $this->db->insert('artwork_external_bodies', ['source_key' => $this->scope, 'asset_key' => $newKey, 'body%bin' => $image['body']])->execute();
            $this->db->update('artwork_external_bindings', ['poster_path' => $candidate['path'], 'selected_title' => mb_substr((string) ($candidate['title'] ?? ''), 0, 16383),
                'selected_year' => $candidate['year'] ?? null, 'expires_at' => $expires, 'last_error' => null, 'next_attempt' => $now + 86400])
                ->where('source_key = %s AND asset_key IN %in AND expires_at > %i', $this->scope, array_unique([$oldKey, $newKey]), time())->execute();
            $this->db->query("UPDATE artwork_external_bindings SET asset_key = %s, revision = revision + 1, state = CASE WHEN suppressed = 1 THEN 'suppressed' WHEN presence = 1 THEN 'present' ELSE 'saved' END WHERE source_key = %s AND asset_key IN %in AND expires_at > %i", $newKey, $this->scope, array_unique([$oldKey, $newKey]), time());
            $this->db->update('artwork_sources', ['used_bytes' => $used])->where('source_key = %s', $this->scope)->execute();
            return true;
        });
    }

    /** Local-only bounded maintenance, including disconnected and disabled source scopes. */
    public function purgeExpiredExternal(int $now, int $limit = 50): int
    {
        $scopes = $this->db->query("SELECT source_key FROM artwork_external_assets WHERE expires_at <= %i UNION SELECT source_key FROM artwork_external_bindings WHERE expires_at <= %i AND (tmdb_id IS NOT NULL OR poster_path IS NOT NULL OR asset_key IS NOT NULL OR selected_title <> '' OR selected_year IS NOT NULL) UNION SELECT source_key FROM artwork_external_searches WHERE expires_at <= %i LIMIT %i", $now, $now, $now, max(1, min(50, $limit)))->fetchAll();
        $removed = 0;
        foreach ($scopes as $scope) {
            $repository = $scope['source_key'] === $this->scope ? $this : new self($this->database, (string) $scope['source_key']);
            $removed += $repository->purgeExternalScope($now);
        }
        return $removed;
    }

    private function purgeExternalScope(int $now): int
    {
        return $this->atomic(function () use ($now): int {
            $assets = $this->db->select('asset_key, body_size')->from('artwork_external_assets')->where('source_key = %s AND expires_at <= %i', $this->scope, $now)->limit(50)->fetchAll();
            $bytes = 0;
            foreach ($assets as $asset) {
                $key = (string) $asset['asset_key'];
                $bytes += (int) $asset['body_size'];
                $this->db->query("UPDATE artwork_external_bindings SET asset_key = NULL, revision = revision + 1, state = CASE WHEN suppressed = 1 THEN 'suppressed' WHEN presence = 1 THEN 'present' WHEN tmdb_id IS NULL THEN 'review' ELSE 'pending' END WHERE source_key = %s AND asset_key = %s", $this->scope, $key);
                $this->db->delete('artwork_external_bodies')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->execute();
                $this->db->delete('artwork_external_assets')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->execute();
            }
            $rows = $this->db->select('item_id')->from('artwork_external_bindings')->where("source_key = %s AND expires_at <= %i AND (tmdb_id IS NOT NULL OR poster_path IS NOT NULL OR asset_key IS NOT NULL OR selected_title <> '' OR selected_year IS NOT NULL)", $this->scope, $now)->limit(100)->fetchAll();
            foreach ($rows as $row) {
                $this->db->query("UPDATE artwork_external_bindings SET tmdb_id = NULL, poster_path = NULL, selected_title = '', selected_year = NULL, asset_key = NULL, revision = revision + 1, last_error = NULL, next_attempt = 0, state = CASE WHEN suppressed = 1 THEN 'suppressed' WHEN presence = 1 THEN 'present' ELSE 'review' END WHERE source_key = %s AND item_id = %s", $this->scope, $row['item_id']);
            }
            if ($bytes > 0) {
                $this->db->query('UPDATE artwork_sources SET used_bytes = used_bytes - %i WHERE source_key = %s', $bytes, $this->scope);
            }
            $lookups = $this->db->select('query_key')->from('artwork_external_searches')->where('source_key = %s AND expires_at <= %i', $this->scope, $now)->limit(100)->fetchAll();
            foreach ($lookups as $lookup) {
                $this->db->delete('artwork_external_searches')->where('source_key = %s AND query_key = %s', $this->scope, $lookup['query_key'])->execute();
            }
            return count($assets) + count($rows) + count($lookups);
        });
    }

    /** @return list<array<string, mixed>> */
    public function externalDue(int $now, int $limit = 2): array
    {
        $limit = max(1, min(8, $limit));
        $old = $this->db->select('item_id')->from('artwork_external_bindings')
            ->where('source_key = %s AND suppressed = 0 AND next_attempt > 0 AND next_attempt <= %i', $this->scope, $now)
            ->orderBy('next_attempt, item_id')->limit(1)->fetchAll();
        $query = $this->db->select('item_id')->from('artwork_external_bindings')
            ->where('source_key = %s AND suppressed = 0 AND next_attempt <= %i', $this->scope, $now);
        if ($old !== []) {
            $query->where('item_id <> %s', $old[0]['item_id']);
        }
        $rows = $old;
        if (count($rows) < $limit) {
            $rows = [...$rows, ...$query->orderBy('CASE WHEN verified_id IS NOT NULL OR tmdb_id IS NOT NULL THEN 0 ELSE 1 END, next_attempt, item_id')->limit($limit - count($rows))->fetchAll()];
        }
        $jobs = [];
        foreach ($rows as $row) {
            $binding = $this->externalBinding((string) $row['item_id']);
            if ($binding !== null) {
                $jobs[] = $binding;
            }
        }
        return $jobs;
    }

    /** @return array<string, mixed>|null */
    public function reusableExternalAsset(string $kind, string $providerId, ?string $path = null): ?array
    {
        if ($this->externalBlocked() || $this->source()['server_id'] === '') {
            return null;
        }
        $query = $this->db->select('a.*, b.body')->from('artwork_external_assets')->as('a')
            ->innerJoin('artwork_external_bodies')->as('b')->on('a.source_key = b.source_key AND a.asset_key = b.asset_key')
            ->where('a.source_key = %s AND a.kind = %s AND a.provider_id = %s AND a.expires_at > %i', $this->scope, $kind, $providerId, time());
        if ($path !== null) {
            $query->where('a.poster_path = %s', $path);
        }
        $row = $query->orderBy('a.saved_at DESC, a.asset_key')->limit(1)->fetch();
        return $row === null ? null : $row->toArray();
    }

    public function externalCoveredByJellyfin(string $id, string $lease, int $now): void
    {
        $this->atomic(function () use ($id, $lease, $now): void {
            if ($this->owns($lease, $now)) {
                $this->db->query("UPDATE artwork_external_bindings SET state = 'present', next_attempt = %i WHERE source_key = %s AND item_id = %s AND suppressed = 0", $now + 86400, $this->scope, $id);
            }
        });
    }

    /** @param array<string, mixed> $job */
    public function retryExternal(array $job, ?string $error, string $lease, int $now, int $delay): void
    {
        $this->externalResolver();
        $this->atomic(function () use ($job, $error, $lease, $now, $delay): void {
            $current = $this->externalBinding((string) $job['item_id']);
            if (!$this->owns($lease, $now) || $current === null || (int) $current['revision'] !== (int) $job['revision']
                || (int) $current['config_revision'] !== (int) $job['config_revision']) {
                return;
            }
            $this->db->update('artwork_external_bindings', ['last_error' => $error,
                'attempts' => $error === null ? 0 : (int) $current['attempts'] + 1,
                'next_attempt' => $now + max(1, min(604800, $delay))])
                ->where('source_key = %s AND item_id = %s', $this->scope, $job['item_id'])->execute();
        });
    }

    private function externalResolver(): ArtworkResolver
    {
        return $this->externalResolver ??= new ArtworkResolver($this->database, $this);
    }

    /** @param list<string> $ids */
    public function manageExternal(array $ids, string $fingerprint, string $action, ?\Closure $guard = null): void
    {
        $this->externalResolver();
        $this->atomic(function () use ($ids, $fingerprint, $action, $guard): void {
            if ($guard !== null) {
                $guard();
            }
            if ($this->externalBlocked() || !hash_equals($this->externalFingerprint($ids), $fingerprint)) {
                throw new \RuntimeException('The poster selection changed.');
            }
            foreach ($ids as $id) {
                $row = $this->externalBinding($id);
                $data = ['revision' => (int) $row['revision'] + 1];
                if ($action === 'snooze') {
                    $data['snoozed_until'] = time() + 604800;
                } elseif ($action === 'unlink') {
                    $data += ['tmdb_id' => null, 'poster_path' => null, 'asset_key' => null, 'selected_title' => '',
                        'selected_year' => null, 'state' => (int) $row['suppressed'] !== 0 ? 'suppressed' : 'review',
                        'origin' => 'manual', 'next_attempt' => 0, 'snoozed_until' => 0, 'last_error' => null];
                } elseif ($action === 'retry') {
                    $data += ['next_attempt' => 0, 'last_error' => null];
                } else {
                    throw new \InvalidArgumentException('Invalid poster action.');
                }
                $this->db->update('artwork_external_bindings', $data)->where('source_key = %s AND item_id = %s', $this->scope, $id)->execute();
            }
        });
    }

    public function externalAssetFingerprint(string $key): string
    {
        $asset = $this->db->select('sha256, revision, kind, provider_id, poster_path')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $this->scope, $key)->fetch();
        if ($asset === null) {
            throw new \RuntimeException('The saved poster changed.');
        }
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([$this->scope, $this->source()['server_id'], $key, $asset->toArray()], JSON_THROW_ON_ERROR));
        $refs = $this->db->select('item_id, revision')->from('artwork_external_bindings')
            ->where('source_key = %s AND (asset_key = %s OR (kind = %s AND tmdb_id = %s AND poster_path = %s))', $this->scope, $key, $asset['kind'], $asset['provider_id'], $asset['poster_path'])->orderBy('item_id')->execute();
        while (($row = $refs->fetch()) !== null) {
            hash_update($hash, json_encode($row->toArray(), JSON_THROW_ON_ERROR));
        }
        return hash_final($hash);
    }

    public function clearExternalAsset(string $key, string $fingerprint): void
    {
        $this->externalResolver();
        $this->atomic(function () use ($key, $fingerprint): void {
            if ($this->externalBlocked() || !hash_equals($this->externalAssetFingerprint($key), $fingerprint)) {
                throw new \RuntimeException('The saved poster changed.');
            }
            $asset = $this->db->select('*')->from('artwork_external_assets')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->fetch();
            $size = (int) $asset['body_size'];
            $this->db->query("UPDATE artwork_external_bindings SET asset_key = NULL, suppressed = 1, suppressed_by = 'selected', state = 'suppressed', revision = revision + 1 WHERE source_key = %s AND (asset_key = %s OR (kind = %s AND tmdb_id = %s AND poster_path = %s))", $this->scope, $key, $asset['kind'], $asset['provider_id'], $asset['poster_path']);
            $this->db->delete('artwork_external_bodies')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->execute();
            $this->db->delete('artwork_external_assets')->where('source_key = %s AND asset_key = %s', $this->scope, $key)->execute();
            $this->db->query('UPDATE artwork_sources SET used_bytes = used_bytes - %i WHERE source_key = %s', $size, $this->scope);
        });
    }

    /** @return array<string, mixed>|null */
    public function externalAsset(string $key): ?array
    {
        if ($this->externalBlocked() || $this->source()['server_id'] === '') {
            return null;
        }
        $row = $this->db->query('SELECT a.*, b.body FROM artwork_external_assets a JOIN artwork_external_bodies b ON a.source_key = b.source_key AND a.asset_key = b.asset_key WHERE a.source_key = %s AND a.asset_key = %s AND a.expires_at > %i', $this->scope, $key, time())->fetch();
        return $row?->toArray();
    }

    private function externalContextKey(string $id): string
    {
        $item = $this->item($id);
        if ($item === null) {
            return hash('sha256', 'missing');
        }
        $target = $this->externalResolver()->target($id, $item['item_type'] === 'Episode') ?? $id;
        $proofs = [];
        foreach (array_unique([$target, is_string($item['poster_id']) ? $item['poster_id'] : $id]) as $posterId) {
            $poster = $this->poster($posterId);
            $proofs[] = [$posterId, $poster['provider_tmdb_id'] ?? null, $poster['provider_conflict'] ?? null, $poster['presence'] ?? null,
                $poster['provider_title'] ?? null, $poster['provider_year'] ?? null];
        }
        return hash('sha256', json_encode([self::externalIdentityKey($item), $target, $proofs, $this->source()['server_id'], $this->externalBlocked()], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed>|null $meta */
    public function acceptExternalObservation(
        string $id,
        string $expectedFingerprint,
        string $lease,
        int $now,
        int $presence,
        ?string $verifiedId,
        bool $conflicted,
        ?string $target = null,
        ?array $meta = null
    ): bool {
        $this->externalResolver();
        return $this->atomic(function () use ($id, $expectedFingerprint, $lease, $now, $presence, $verifiedId, $conflicted, $target, $meta): bool {
            if (!$this->owns($lease, $now) || $this->externalBlocked() || !hash_equals($this->externalFingerprint([$id]), $expectedFingerprint)) {
                return false;
            }
            $item = $this->item($id);
            if ($item === null) {
                return false;
            }
            if ($target !== null && $meta !== null) {
                $kind = $item['item_type'] === 'Episode' ? 'Series' : 'Movie';
                if (($meta['Type'] ?? null) !== $kind || !is_string($meta['Id'] ?? null)
                    || ThemePlaybackExclusions::canonicalItemId($meta['Id']) !== ThemePlaybackExclusions::canonicalItemId($target)) {
                    return false;
                }
                $tag = $meta['ImageTags']['Primary'] ?? null;
                $this->enqueue($target, $kind, is_string($tag) ? $tag : null, (int) (microtime(true) * 1000000));
                $this->notePosterMetadata($target, $meta, $lease, $now, $presence);
                $poster = $this->poster($target);
                $conflicted = $conflicted || (int) ($poster['provider_conflict'] ?? 0) !== 0;
                $verifiedId = $conflicted ? null : ($poster['provider_tmdb_id'] ?? $verifiedId);
                if ($item['item_type'] === 'Episode' && $item['poster_id'] === null && $presence !== -1) {
                    $item['poster_id'] = $target;
                    $item['status'] = 'ready';
                    $this->rememberWithPoster($item, is_string($tag) ? $tag : null);
                }
            }
            $this->externalObserve($id, $presence, $verifiedId, $conflicted);
            return true;
        });
    }

    private function ensureSchema(): void
    {
        self::$initialized ??= new \WeakMap();
        if (isset(self::$initialized[$this->db])) {
            return;
        }
        $definitions = [
            'artwork_external_searches' => 'source_key varchar(64) NOT NULL, query_key varchar(64) NOT NULL, server_id varchar(64) NOT NULL, config_revision bigint NOT NULL, kind varchar(16) NOT NULL, query_title text NOT NULL, acquired_at bigint NOT NULL, expires_at bigint NOT NULL, candidates_json text NOT NULL, last_error varchar(32) DEFAULT NULL, revision bigint NOT NULL DEFAULT 1, PRIMARY KEY(source_key, query_key)',
            'artwork_external_bindings' => "source_key varchar(64) NOT NULL, item_id varchar(64) NOT NULL, identity_key varchar(64) NOT NULL, kind varchar(16) NOT NULL, verified_id varchar(24) DEFAULT NULL, identity_conflict int NOT NULL DEFAULT 0, tmdb_id varchar(24) DEFAULT NULL, poster_path varchar(160) DEFAULT NULL, selected_title text NOT NULL DEFAULT '', selected_year int DEFAULT NULL, asset_key varchar(64) DEFAULT NULL, presence int NOT NULL DEFAULT -1, state varchar(24) NOT NULL DEFAULT 'review', origin varchar(16) NOT NULL DEFAULT 'automatic', suppressed int NOT NULL DEFAULT 0, suppressed_by varchar(16) NOT NULL DEFAULT '', snoozed_until bigint NOT NULL DEFAULT 0, revision bigint NOT NULL DEFAULT 1, next_attempt bigint NOT NULL DEFAULT 0, attempts int NOT NULL DEFAULT 0, last_error varchar(32) DEFAULT NULL, PRIMARY KEY(source_key, item_id)",
            'artwork_external_assets' => "source_key varchar(64) NOT NULL, asset_key varchar(64) NOT NULL, revision bigint NOT NULL DEFAULT 1, kind varchar(16) NOT NULL, provider_id varchar(24) NOT NULL, poster_path varchar(160) NOT NULL, title text NOT NULL, year int DEFAULT NULL, requested_language varchar(32) NOT NULL DEFAULT 'server-default', image_language varchar(32) DEFAULT NULL, body_size int NOT NULL, content_type varchar(32) NOT NULL, sha256 varchar(64) NOT NULL, saved_at bigint NOT NULL, PRIMARY KEY(source_key, asset_key)",
            'artwork_external_bodies' => 'source_key varchar(64) NOT NULL, asset_key varchar(64) NOT NULL, body MEDIUMBLOB NOT NULL, PRIMARY KEY(source_key, asset_key)',
            'artwork_sources' => "source_key varchar(64) NOT NULL PRIMARY KEY, server_id varchar(64) NOT NULL DEFAULT '', blocked int NOT NULL DEFAULT 0, lease_token varchar(64) DEFAULT NULL, lease_until bigint NOT NULL DEFAULT 0, used_bytes bigint NOT NULL DEFAULT 0, backfill_cursor bigint NOT NULL DEFAULT 0, legacy_confirmed int NOT NULL DEFAULT 0, next_attempt bigint NOT NULL DEFAULT 0, last_error varchar(32) DEFAULT NULL, anchored_at bigint NOT NULL DEFAULT 0, fresh_cursor bigint NOT NULL DEFAULT 0",
            'artwork_items' => "source_key varchar(64) NOT NULL, item_id varchar(64) NOT NULL, item_type varchar(16) NOT NULL, title text NOT NULL, series_name text NOT NULL, library text NOT NULL DEFAULT '', poster_id varchar(64) DEFAULT NULL, observed_at bigint NOT NULL DEFAULT 0, evidence varchar(16) NOT NULL, status varchar(24) NOT NULL, next_attempt bigint NOT NULL DEFAULT 0, PRIMARY KEY(source_key, item_id)",
            'artwork_posters' => "source_key varchar(64) NOT NULL, poster_id varchar(64) NOT NULL, kind varchar(16) NOT NULL, provider_tmdb_id varchar(24) DEFAULT NULL, provider_title text NOT NULL DEFAULT '', provider_year int DEFAULT NULL, provider_conflict int NOT NULL DEFAULT 0, presence int NOT NULL DEFAULT -1, presence_checked bigint NOT NULL DEFAULT 0, requested_tag varchar(255) DEFAULT NULL, observed_at bigint NOT NULL DEFAULT 0, revision bigint NOT NULL DEFAULT 1, attempts int NOT NULL DEFAULT 0, next_attempt bigint NOT NULL DEFAULT 0, last_checked bigint NOT NULL DEFAULT 0, suppressed int NOT NULL DEFAULT 0, last_error varchar(32) DEFAULT NULL, PRIMARY KEY(source_key, poster_id)",
            'artwork_images' => 'source_key varchar(64) NOT NULL, poster_id varchar(64) NOT NULL, body MEDIUMBLOB NOT NULL, content_type varchar(32) NOT NULL, tag varchar(255) DEFAULT NULL, body_size int NOT NULL, sha256 varchar(64) NOT NULL, saved_at bigint NOT NULL, PRIMARY KEY(source_key, poster_id)',
        ];
        foreach ($definitions as $table => $columns) {
            $sqlite = preg_replace('/varchar\([0-9]+\)|MEDIUMBLOB/i', 'TEXT', $columns);
            $sqlite = str_replace('body TEXT NOT NULL', 'body BLOB NOT NULL', (string) $sqlite);
            $this->database->getPlatform()->createTable(
                'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . $columns . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
                'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . $sqlite . ')'
            );
        }
        foreach (['artwork_external_bindings' => [
            'discovery_after' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'selected_title' => ["text NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
            'selected_year' => ['int DEFAULT NULL', 'INTEGER DEFAULT NULL'],
            'suppressed_by' => ["varchar(16) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
            'expires_at' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        ], 'artwork_external_assets' => [
            'revision' => ['bigint NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'],
            'expires_at' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        ], 'artwork_posters' => [
            'provider_tmdb_id' => ['varchar(24) DEFAULT NULL', 'TEXT DEFAULT NULL'],
            'provider_title' => ["text NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
            'provider_year' => ['int DEFAULT NULL', 'INTEGER DEFAULT NULL'],
            'provider_conflict' => ['int NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'presence' => ['int NOT NULL DEFAULT -1', 'INTEGER NOT NULL DEFAULT -1'],
            'presence_checked' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        ]] as $table => $fields) {
            foreach ($fields as $column => [$maria, $sqlite]) {
                if (!$this->database->getPlatform()->columnExists($table, $column)) {
                    try {
                        $this->database->getPlatform()->addColumn($table, $column . ' ' . $maria, $column . ' ' . $sqlite);
                    } catch (\Dibi\Exception $error) {
                        if (!$this->database->getPlatform()->columnExists($table, $column)) {
                            throw $error;
                        }
                    }
                }
            }
        }
        foreach (['external_preference' => ['int NOT NULL DEFAULT -1', 'INTEGER NOT NULL DEFAULT -1'],
            'external_discovery_after' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'external_revision' => ['bigint NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'],
            'external_next_attempt' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'external_last_error' => ['varchar(32) DEFAULT NULL', 'TEXT DEFAULT NULL'],
            'legacy_confirmed' => ['int NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'next_attempt' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'anchored_at' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'fresh_cursor' => ['bigint NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
            'last_error' => ['varchar(32) DEFAULT NULL', 'TEXT DEFAULT NULL']] as $name => [$maria, $sqlite]) {
            if (!$this->database->getPlatform()->columnExists('artwork_sources', $name)) {
                try {
                    $this->database->getPlatform()->addColumn('artwork_sources', $name . ' ' . $maria, $name . ' ' . $sqlite);
                } catch (\Dibi\Exception $error) {
                    if (!$this->database->getPlatform()->columnExists('artwork_sources', $name)) {
                        throw $error;
                    }
                }
            }
        }
        if (!$this->database->getPlatform()->columnExists('artwork_items', 'library')) {
            try {
                $this->database->getPlatform()->addColumn('artwork_items', "library text NOT NULL DEFAULT ''", "library TEXT NOT NULL DEFAULT ''");
            } catch (\Dibi\Exception $error) {
                if (!$this->database->getPlatform()->columnExists('artwork_items', 'library')) {
                    throw $error;
                }
            }
        }
        $this->database->getPlatform()->createIndexIfMissing('idx_artwork_due', 'artwork_posters', ['source_key', 'suppressed', 'next_attempt']);
        $this->database->getPlatform()->createIndexIfMissing('idx_artwork_item_status', 'artwork_items', ['source_key', 'status']);
        $this->database->getPlatform()->createIndexIfMissing('idx_artwork_external_asset', 'artwork_external_bindings', ['source_key', 'asset_key']);
        $this->database->getPlatform()->createIndexIfMissing('idx_artwork_external_due', 'artwork_external_bindings', ['source_key', 'suppressed', 'next_attempt']);
        self::$initialized[$this->db] = true;
    }
}
