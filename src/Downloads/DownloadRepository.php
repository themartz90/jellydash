<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Integrations\CredentialStore;

final class DownloadRepository
{
    private const LEASE_SECONDS = 60;
    private const POLL_SECONDS = 15;
    private const MAX_BATCH_ITEMS = 1000;
    private const HISTORY_LIMIT = 250;
    private const HISTORY_MAX_AGE = 7776000;

    private readonly Database $database;
    private readonly \Dibi\Connection $db;
    private readonly CredentialStore $store;
    private readonly ConnectionRepository $connections;

    public function __construct(?Database $database = null, ?CredentialStore $store = null)
    {
        $this->database = $database ?? Container::db();
        $this->db = $this->database->getDibi();
        $this->store = $store ?? new CredentialStore();
        self::ensureSchema($this->database);
        $this->connections = new ConnectionRepository($this->database, $this->store);
    }

    public static function ensureSchema(Database $database): void
    {
        ConnectionRepository::ensureSchema($database);
        $database->getPlatform()->createTable(
            'CREATE TABLE IF NOT EXISTS `download_connection_state` (
                `connection_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `config_revision` bigint NOT NULL,
                `lease_token` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `lease_expires_at` bigint DEFAULT NULL,
                `next_due_at` bigint NOT NULL DEFAULT 0,
                `last_attempt_at` bigint DEFAULT NULL,
                `last_success_at` bigint DEFAULT NULL,
                `failure_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `failure_count` int NOT NULL DEFAULT 0,
                `cursor_json` mediumtext DEFAULT NULL,
                `snapshot_json` mediumtext DEFAULT NULL,
                `snapshot_at` bigint DEFAULT NULL,
                `session_envelope` mediumtext DEFAULT NULL,
                PRIMARY KEY (`connection_id`),
                KEY `idx_download_state_due` (`next_due_at`, `lease_expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `download_connection_state` (
                `connection_id` TEXT NOT NULL PRIMARY KEY COLLATE BINARY,
                `config_revision` INTEGER NOT NULL,
                `lease_token` TEXT DEFAULT NULL COLLATE BINARY,
                `lease_expires_at` INTEGER DEFAULT NULL,
                `next_due_at` INTEGER NOT NULL DEFAULT 0,
                `last_attempt_at` INTEGER DEFAULT NULL,
                `last_success_at` INTEGER DEFAULT NULL,
                `failure_code` TEXT DEFAULT NULL COLLATE BINARY,
                `failure_count` INTEGER NOT NULL DEFAULT 0,
                `cursor_json` TEXT DEFAULT NULL,
                `snapshot_json` TEXT DEFAULT NULL,
                `snapshot_at` INTEGER DEFAULT NULL,
                `session_envelope` TEXT DEFAULT NULL
            )'
        );
        $database->getPlatform()->createSqliteIndex(
            'idx_download_state_due',
            'download_connection_state',
            ['next_due_at', 'lease_expires_at'],
        );

        $database->getPlatform()->createTable(
            'CREATE TABLE IF NOT EXISTS `download_completions` (
                `connection_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `source_id_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `source_id` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `title` varchar(1024) NOT NULL,
                `category` varchar(256) NOT NULL DEFAULT \'\',
                `tags_json` mediumtext NOT NULL,
                `size_bytes` bigint DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT \'completed\',
                `completed_at` bigint DEFAULT NULL,
                `observed_at` bigint NOT NULL,
                `last_seen_at` bigint NOT NULL,
                PRIMARY KEY (`connection_id`, `source_id_digest`),
                KEY `idx_download_completion_recent` (`connection_id`, `completed_at`, `observed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `download_completions` (
                `connection_id` TEXT NOT NULL COLLATE BINARY,
                `source_id_digest` TEXT NOT NULL COLLATE BINARY,
                `source_id` TEXT NOT NULL COLLATE BINARY,
                `title` TEXT NOT NULL,
                `category` TEXT NOT NULL DEFAULT \'\',
                `tags_json` TEXT NOT NULL,
                `size_bytes` INTEGER DEFAULT NULL,
                `status` TEXT NOT NULL DEFAULT \'completed\',
                `completed_at` INTEGER DEFAULT NULL,
                `observed_at` INTEGER NOT NULL,
                `last_seen_at` INTEGER NOT NULL,
                PRIMARY KEY (`connection_id`, `source_id_digest`)
            )'
        );
        $platform = $database->getPlatform();
        if (!$platform->columnExists('download_completions', 'status')) {
            try {
                $platform->addColumn('download_completions',
                    "`status` varchar(16) NOT NULL DEFAULT 'completed'",
                    "`status` TEXT NOT NULL DEFAULT 'completed'");
            } catch (\Dibi\Exception $e) {
                if (!$platform->columnExists('download_completions', 'status')) {
                    throw $e;
                }
            }
        }
        if (!$platform->isSqlite() && self::completionCategoryLength($database) < 256) {
            try {
                $database->getDibi()->query(
                    "ALTER TABLE `download_completions` MODIFY COLUMN `category` varchar(256) NOT NULL DEFAULT ''"
                );
            } catch (\Dibi\Exception $e) {
                if (self::completionCategoryLength($database) < 256) {
                    throw $e;
                }
            }
        }
        if (!$platform->isSqlite() && self::completionTagsNeedUpgrade($database)) {
            try {
                $database->getDibi()->query(
                    'ALTER TABLE `download_completions` MODIFY COLUMN `tags_json` mediumtext NOT NULL'
                );
            } catch (\Dibi\Exception $e) {
                if (self::completionTagsNeedUpgrade($database)) {
                    throw $e;
                }
            }
        }
        $database->getPlatform()->createSqliteIndex(
            'idx_download_completion_recent',
            'download_completions',
            ['connection_id', 'completed_at', 'observed_at'],
        );
    }

    /** @phpstan-impure */
    private static function completionCategoryLength(Database $database): int
    {
        return (int) $database->getDibi()->query(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            'download_completions', 'category',
        )->fetchSingle();
    }

    /** @phpstan-impure */
    private static function completionTagsNeedUpgrade(Database $database): bool
    {
        return $database->getDibi()->query(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            'download_completions', 'tags_json',
        )->fetchSingle() === 'text';
    }

    /** @return array<string,mixed>|null */
    public function state(string $id): ?array
    {
        $row = $this->db->select('*')->from('download_connection_state')
            ->where('connection_id = %s', $id)->fetch();

        return $row === null ? null : $this->decodeState($row->toArray());
    }

    /** @return array<string,array<string,mixed>> */
    public function states(): array
    {
        $result = [];
        foreach ($this->db->select('*')->from('download_connection_state')->fetchAll() as $row) {
            $decoded = $this->decodeState($row->toArray());
            $result[(string) $decoded['connection_id']] = $decoded;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function session(Connection $connection): array
    {
        $row = $this->db->select('session_envelope')->from('download_connection_state')
            ->where('connection_id = %s AND config_revision = %i', $connection->id, $connection->revision)
            ->fetchSingle();
        if ($row === false || $row === null || $row === '') {
            return [];
        }
        if (!is_string($row)) {
            throw new \RuntimeException('The saved downloader session is invalid.');
        }

        try {
            $decoded = json_decode(
                $this->store->decode($row, $this->sessionContext($connection)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \RuntimeException('The saved downloader session is invalid.');
            }
        } catch (\RuntimeException|\InvalidArgumentException|\JsonException) {
            $this->replaceSessionEnvelope($connection, $row, null);

            return [];
        }
        if ($this->store->isLegacy($row)) {
            $this->replaceSessionEnvelope($connection, $row, $this->store->encode(
                $this->encode($decoded), $this->sessionContext($connection),
            ));
        }

        return $decoded;
    }

    public function claim(Connection $connection, int $now): ?string
    {
        if ($now < 0 || !$connection->enabled) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->beginWrite();
        try {
            if (!$this->isCurrent($connection, true)) {
                $this->db->rollback();

                return null;
            }
            // Environment changes can replace a revision without a settings write.
            $this->db->delete('download_connection_state')
                ->where('connection_id = %s AND config_revision <> %i', $connection->id, $connection->revision)->execute();
            try {
                $this->db->insert('download_connection_state', [
                    'connection_id' => $connection->id,
                    'config_revision' => $connection->revision,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'next_due_at' => 0,
                    'failure_count' => 0,
                ])->execute();
            } catch (\Dibi\UniqueConstraintViolationException) {
            }

            $this->db->query(
                'UPDATE `download_connection_state` SET `lease_token` = %s, `lease_expires_at` = %i, `last_attempt_at` = %i WHERE `connection_id` = %s AND `config_revision` = %i AND `next_due_at` <= %i AND (`lease_token` IS NULL OR `lease_expires_at` IS NULL OR `lease_expires_at` <= %i)',
                $token,
                $now + self::LEASE_SECONDS,
                $now,
                $connection->id,
                $connection->revision,
                $now,
                $now,
            );

            $claimed = $this->db->getAffectedRows() === 1;
            $this->db->commit();

            return $claimed ? $token : null;
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function abandon(Connection $connection, string $token): void
    {
        if (!$this->validToken($token)) {
            throw new \InvalidArgumentException('The collector lease is invalid.');
        }

        $this->db->update('download_connection_state', ['lease_token' => null, 'lease_expires_at' => null])
            ->where('connection_id = %s AND config_revision = %i AND lease_token = %s',
                $connection->id, $connection->revision, $token)
            ->execute();
    }

    public function succeed(Connection $connection, string $token, CollectionBatch $batch, int $now): bool
    {
        if (!$this->validToken($token) || $now < 0) {
            throw new \InvalidArgumentException('The collector result is invalid.');
        }
        if (count($batch->items) > self::MAX_BATCH_ITEMS || count($batch->completions) > self::MAX_BATCH_ITEMS) {
            throw new \InvalidArgumentException('The collector result contains too many items.');
        }
        $items = [];
        $activeIds = [];
        $includedSpeed = 0.0;
        $outsideSpeed = 0.0;
        $includedSpeedKnown = true;
        $outsideSpeedKnown = true;
        $includedDownloading = false;
        $outsideDownloading = false;
        foreach ($batch->items as $item) {
            $normalized = $this->normalizeItem($item, false);
            $matches = $connection->matches($normalized);
            // A SAB job without observed progress may still receive data now.
            // Do not use sampled activity to assign its entire client's speed.
            $mayDownload = $connection->provider === 'sabnzbd'
                ? in_array($normalized['state'], ['downloading', 'queued', 'unknown'], true)
                : $normalized['state'] === 'downloading';
            if ($mayDownload) {
                if ($matches) {
                    $includedDownloading = true;
                } else {
                    $outsideDownloading = true;
                }
            }
            if (Providers::hasItemSpeed($connection->provider) && $connection->filterMode === 'selected') {
                $speed = in_array($normalized['state'], ['paused', 'error', 'completed'], true)
                    ? 0.0 : $normalized['speed'];
                if ($speed === null && in_array($normalized['state'], ['queued', 'checking', 'processing'], true)) {
                    $speed = 0.0;
                }
                if ($matches) {
                    $includedSpeedKnown = $includedSpeedKnown && $speed !== null;
                    $includedSpeed += $speed ?? 0.0;
                } else {
                    $outsideSpeedKnown = $outsideSpeedKnown && $speed !== null;
                    $outsideSpeed += $speed ?? 0.0;
                }
            }
            if ($matches) {
                $items[] = $normalized;
                if ($normalized['state'] !== 'error') {
                    $activeIds[hash('sha256', $normalized['source_id'])] = true;
                }
            }
        }
        $completions = [];
        foreach ($batch->completions as $item) {
            $normalized = $this->normalizeItem($item, true);
            if ($connection->matches($normalized)) {
                $completions[] = $normalized;
            }
        }
        $cursor = $this->normalizeServerMap($batch->cursor, 'completion cursor');
        $session = $this->normalizeServerMap($batch->session, 'downloader session');
        $history = $this->normalizeHistoryState($batch->history);
        $clientSpeed = $this->nullableFloat($batch->speed, 'client speed', 0.0, PHP_FLOAT_MAX);
        $speed = $clientSpeed;
        $outside = $clientSpeed === null ? null : 0.0;
        if ($connection->filterMode === 'selected') {
            $speed = $outside = null;
            if ($batch->complete) {
                if (Providers::hasItemSpeed($connection->provider)) {
                    $speed = $includedSpeedKnown ? $includedSpeed : null;
                    $outside = $outsideSpeedKnown ? $outsideSpeed : null;
                } elseif (in_array($connection->provider, ['sabnzbd', 'nzbget'], true)) {
                    if ($clientSpeed === 0.0) {
                        $speed = $outside = 0.0;
                    } elseif (!$includedDownloading) {
                        $speed = 0.0;
                        $outside = $outsideDownloading ? $clientSpeed : null;
                    } elseif (!$outsideDownloading) {
                        $speed = $clientSpeed;
                        $outside = $clientSpeed === null ? null : 0.0;
                    }
                }
            }
        }
        $snapshot = [
            'items' => $items, 'speed' => $speed, 'client_speed' => $clientSpeed,
            'outside_speed' => $outside, 'speed_complete' => $speed !== null,
            'complete' => $batch->complete, 'history' => $history,
        ];
        $sessionEnvelope = $session === [] ? null : $this->store->encode(
            $this->encode($session),
            $this->sessionContext($connection),
        );

        $this->beginWrite();
        try {
            if (!$this->isCurrent($connection, true) || !$this->ownsLease($connection, $token, $now)) {
                $this->db->rollback();

                return false;
            }
            foreach (array_keys($activeIds) as $sourceIdDigest) {
                $this->db->delete('download_completions')
                    ->where('connection_id = %s AND source_id_digest = %s AND status IN %in',
                        $connection->id, $sourceIdDigest, ['failed', 'warning'])->execute();
            }
            foreach ($completions as $completion) {
                if (in_array($completion['state'], ['failed', 'warning'], true) && isset($activeIds[hash('sha256', $completion['source_id'])])) {
                    continue;
                }
                $this->mergeCompletion($connection->id, $completion, $now);
            }
            $this->pruneHistory($connection->id, $now);
            $this->db->update('download_connection_state', [
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_due_at' => $now + self::POLL_SECONDS,
                'last_success_at' => $now,
                'failure_code' => null,
                'failure_count' => 0,
                'cursor_json' => $this->encode($cursor),
                'snapshot_json' => $this->encode($snapshot),
                'snapshot_at' => $now,
                'session_envelope' => $sessionEnvelope,
            ])->where(
                'connection_id = %s AND config_revision = %i AND lease_token = %s',
                $connection->id,
                $connection->revision,
                $token
            )->execute();
            if ($this->db->getAffectedRows() !== 1) {
                $this->db->rollback();

                return false;
            }
            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function fail(Connection $connection, string $token, string $errorCode, int $now): void
    {
        if (!$this->validToken($token) || $now < 0) {
            throw new \InvalidArgumentException('The collector failure is invalid.');
        }
        $errorCode = preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $errorCode) === 1
            ? $errorCode : 'request_failed';
        $row = $this->db->select('failure_count')->from('download_connection_state')
            ->where(
                'connection_id = %s AND config_revision = %i AND lease_token = %s AND lease_expires_at > %i',
                $connection->id,
                $connection->revision,
                $token,
                $now
            )->fetch();
        if ($row === null || !$this->isCurrent($connection)) {
            return;
        }
        $failureCount = min(31, (int) $row['failure_count'] + 1);
        $delays = [15, 30, 60, 120];
        $delay = $errorCode === 'authentication_failed'
            ? 300 : $delays[min($failureCount - 1, count($delays) - 1)];
        $this->db->update('download_connection_state', [
            'lease_token' => null,
            'lease_expires_at' => null,
            'next_due_at' => $now + $delay,
            'failure_code' => $errorCode,
            'failure_count' => $failureCount,
            'session_envelope' => $errorCode === 'authentication_failed' ? null : $this->sessionEnvelope($connection),
        ])->where(
            'connection_id = %s AND config_revision = %i AND lease_token = %s AND lease_expires_at > %i',
            $connection->id,
            $connection->revision,
            $token,
            $now
        )->execute();
    }

    /** @param list<string> $connectionIds @return list<array<string,mixed>> */
    public function recent(array $connectionIds, int $limit = 250): array
    {
        $connectionIds = array_values(array_unique(array_filter(
            $connectionIds,
            static fn (string $id): bool => $id !== '',
        )));
        if ($connectionIds === []) {
            return [];
        }
        $limit = max(1, min(self::HISTORY_LIMIT, $limit));
        $connections = [];
        foreach ($connectionIds as $connectionId) {
            $connection = $this->connections->find($connectionId);
            if ($connection !== null) {
                $connections[$connectionId] = $connection;
            }
        }
        if ($connections === []) {
            return [];
        }
        $rows = $this->db->select('connection_id, source_id, title, category, tags_json, size_bytes, status, completed_at, observed_at')
            ->from('download_completions')->where('connection_id IN %in', array_keys($connections))
            ->orderBy('COALESCE(completed_at, observed_at) DESC, connection_id, source_id_digest')
            ->limit(self::HISTORY_LIMIT * min(10, count($connections)))->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $item = [
                'connection_id' => (string) $row['connection_id'],
                'source_id' => (string) $row['source_id'],
                'title' => (string) $row['title'],
                'category' => (string) $row['category'],
                'tags' => $this->decodeStringList((string) $row['tags_json'], 'completion tags'),
                'size' => $row['size_bytes'] === null ? null : (int) $row['size_bytes'],
                'status' => (string) $row['status'],
                'completed_at' => $row['completed_at'] === null ? null : (int) $row['completed_at'],
                'observed_at' => (int) $row['observed_at'],
            ];
            if (!$connections[$item['connection_id']]->matches($item)) {
                continue;
            }
            $result[] = $item;
            if (count($result) === $limit) {
                break;
            }
        }

        return $result;
    }

    private function ownsLease(Connection $connection, string $token, int $now): bool
    {
        return (int) $this->db->select('COUNT(*)')->from('download_connection_state')
            ->where(
                'connection_id = %s AND config_revision = %i AND lease_token = %s AND lease_expires_at > %i',
                $connection->id,
                $connection->revision,
                $token,
                $now
            )->fetchSingle() === 1;
    }

    private function sessionEnvelope(Connection $connection): ?string
    {
        $value = $this->db->select('session_envelope')->from('download_connection_state')
            ->where('connection_id = %s AND config_revision = %i', $connection->id, $connection->revision)
            ->fetchSingle();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function replaceSessionEnvelope(Connection $connection, string $oldEnvelope, ?string $newEnvelope): void
    {
        $this->db->update('download_connection_state', ['session_envelope' => $newEnvelope])
            ->where('connection_id = %s AND config_revision = %i AND session_envelope = %s',
                $connection->id, $connection->revision, $oldEnvelope)
            ->execute();
    }

    /** @phpstan-impure */
    private function isCurrent(Connection $connection, bool $lock = false): bool
    {
        if ($lock && !$this->database->getPlatform()->isSqlite()) {
            $row = $this->db->query(
                'SELECT `provider`, `endpoint`, `enabled`, `config_revision` FROM `integration_connections` WHERE `id` = %s FOR UPDATE',
                $connection->id,
            )->fetch();
            if ($row !== null) {
                return (bool) $row['enabled'] && (int) $row['config_revision'] === $connection->revision
                    && (string) $row['provider'] === $connection->provider
                    && (string) $row['endpoint'] === $connection->url;
            }
        }
        $current = $this->connections->find($connection->id);

        return $current !== null && $current->enabled && $current->revision === $connection->revision
            && $current->provider === $connection->provider && $current->url === $connection->url;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeState(array $row): array
    {
        return [
            'connection_id' => (string) $row['connection_id'],
            'revision' => (int) $row['config_revision'],
            'snapshot' => $this->decodeMap($row['snapshot_json'], 'download snapshot'),
            'cursor' => $this->decodeMap($row['cursor_json'], 'completion cursor'),
            'snapshot_at' => $row['snapshot_at'] === null ? null : (int) $row['snapshot_at'],
            'last_attempt_at' => $row['last_attempt_at'] === null ? null : (int) $row['last_attempt_at'],
            'last_success_at' => $row['last_success_at'] === null ? null : (int) $row['last_success_at'],
            'next_due_at' => (int) $row['next_due_at'],
            'failure_code' => $row['failure_code'] === null ? null : (string) $row['failure_code'],
            'failure_count' => (int) $row['failure_count'],
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalizeItem(array $item, bool $completion): array
    {
        $sourceId = $this->boundedString($item['source_id'] ?? null, 256, 'source identity', false);
        $title = $this->boundedString($item['title'] ?? null, 1024, 'download title', false);
        $category = $this->boundedUnicodeString($item['category'] ?? '', 256, 'download category', true);
        $tags = $item['tags'] ?? [];
        if (!is_array($tags) || !array_is_list($tags) || count($tags) > 100) {
            throw new \InvalidArgumentException('The normalized download tags are invalid.');
        }
        $normalizedTags = [];
        foreach ($tags as $tag) {
            $normalizedTags[] = $this->boundedUnicodeString($tag, 256, 'download tag', false);
        }
        $normalizedTags = array_values(array_unique($normalizedTags));
        $states = ['downloading', 'processing', 'queued', 'paused', 'stalled', 'checking', 'metadata', 'unknown', 'error', 'completed', 'failed', 'warning'];
        $state = $item['state'] ?? ($completion ? 'completed' : null);
        if (!is_string($state) || !in_array($state, $states, true)
            || ($completion && !in_array($state, ['completed', 'failed', 'warning'], true))
            || (!$completion && in_array($state, ['failed', 'warning'], true))) {
            throw new \InvalidArgumentException('The normalized download state is invalid.');
        }

        return [
            'source_id' => $sourceId,
            'title' => $title,
            'category' => $category,
            'tags' => $normalizedTags,
            'state' => $state,
            'progress' => $this->nullableFloat($item['progress'] ?? null, 'download progress', 0.0, 100.0),
            'size' => $this->nullableInt($item['size'] ?? null, 'download size', 0),
            'downloaded' => $this->nullableInt($item['downloaded'] ?? null, 'downloaded size', 0),
            'speed' => $this->nullableFloat($item['speed'] ?? null, 'download speed', 0.0, PHP_FLOAT_MAX),
            'eta' => $this->nullableInt($item['eta'] ?? null, 'download ETA', 0),
            'position' => $this->nullableInt($item['position'] ?? null, 'queue position', 0),
            'completed_at' => $this->nullableInt($item['completed_at'] ?? null, 'completion time', 0),
        ];
    }

    /** @param array<string,mixed> $completion */
    private function mergeCompletion(string $connectionId, array $completion, int $now): void
    {
        $digest = hash('sha256', $completion['source_id']);
        $existing = $this->db->select('status, completed_at, observed_at')->from('download_completions')
            ->where('connection_id = %s AND source_id_digest = %s', $connectionId, $digest)->fetch();
        $data = [
            'source_id' => $completion['source_id'],
            'title' => $completion['title'],
            'category' => $completion['category'],
            'tags_json' => $this->encode($completion['tags']),
            'size_bytes' => $completion['size'],
            'status' => $completion['state'],
            'last_seen_at' => $now,
        ];
        if ($existing === null) {
            $this->db->insert('download_completions', $data + [
                'connection_id' => $connectionId,
                'source_id_digest' => $digest,
                'completed_at' => $completion['completed_at'],
                'observed_at' => $now,
            ])->execute();

            return;
        }

        $oldCompleted = $existing['completed_at'] === null ? null : (int) $existing['completed_at'];
        $newCompleted = $completion['completed_at'];
        $oldStatus = (string) $existing['status'];
        $newStatus = $completion['state'];
        $stale = ($oldCompleted !== null && $newCompleted !== null && $newCompleted < $oldCompleted)
            || ($oldStatus === 'completed' && in_array($newStatus, ['failed', 'warning'], true)
                && ($newCompleted === null || $newCompleted <= max($oldCompleted ?? 0, (int) $existing['observed_at'])))
            || ($oldStatus === $newStatus && $oldCompleted !== null
                && ($newCompleted === null || $newCompleted < $oldCompleted));
        if ($stale) {
            $this->db->update('download_completions', ['last_seen_at' => $now])
                ->where('connection_id = %s AND source_id_digest = %s', $connectionId, $digest)->execute();

            return;
        } elseif ($newCompleted !== null && ($oldCompleted === null || $newCompleted > $oldCompleted)) {
            $data['completed_at'] = $newCompleted;
            $data['observed_at'] = $now;
        } elseif ($oldStatus !== $newStatus) {
            $data['completed_at'] = $newCompleted;
            $data['observed_at'] = $now;
        }
        $this->db->update('download_completions', $data)
            ->where('connection_id = %s AND source_id_digest = %s', $connectionId, $digest)->execute();
    }

    private function pruneHistory(string $connectionId, int $now): void
    {
        $this->db->query(
            'DELETE FROM `download_completions` WHERE `connection_id` = %s AND COALESCE(`completed_at`, `observed_at`) < %i',
            $connectionId,
            $now - self::HISTORY_MAX_AGE,
        );
        $rows = $this->db->select('source_id_digest')->from('download_completions')
            ->where('connection_id = %s', $connectionId)
            ->orderBy('COALESCE(completed_at, observed_at) DESC, source_id_digest')
            ->limit(self::MAX_BATCH_ITEMS)->offset(self::HISTORY_LIMIT)->fetchAll();
        foreach ($rows as $row) {
            $this->db->delete('download_completions')
                ->where('connection_id = %s AND source_id_digest = %s', $connectionId, (string) $row['source_id_digest'])
                ->execute();
        }
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function normalizeServerMap(array $value, string $label): array
    {
        $encoded = $this->encode($value);
        if (strlen($encoded) > 131072) {
            throw new \InvalidArgumentException('The normalized ' . $label . ' is too large.');
        }

        return $value;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function normalizeHistoryState(array $value): array
    {
        if ($value === []) {
            return [];
        }
        $attempt = $this->nullableInt($value['last_attempt_at'] ?? null, 'history attempt time', 0);
        $success = $this->nullableInt($value['last_success_at'] ?? null, 'history success time', 0);
        $due = $this->nullableInt($value['next_due_at'] ?? null, 'history due time', 0);
        $failures = $this->nullableInt($value['failure_count'] ?? null, 'history failure count', 0);
        if ($due === null || $failures === null || $failures > 31) {
            throw new \InvalidArgumentException('The normalized history state is invalid.');
        }
        $error = $value['error'] ?? null;
        $error = is_string($error) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $error) === 1
            ? $error : ($error === null ? null : 'history_update_failed');

        return [
            'last_attempt_at' => $attempt,
            'last_success_at' => $success,
            'error' => $error,
            'next_due_at' => $due,
            'failure_count' => $failures,
        ];
    }

    private function boundedString(mixed $value, int $maxLength, string $label, bool $empty): string
    {
        if (!is_string($value) || (!$empty && $value === '') || strlen($value) > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('The normalized ' . $label . ' is invalid.');
        }

        return $value;
    }

    private function boundedUnicodeString(mixed $value, int $maxLength, string $label, bool $empty): string
    {
        if (!is_string($value) || (!$empty && $value === '') || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('The normalized ' . $label . ' is invalid.');
        }

        return $value;
    }

    private function nullableInt(mixed $value, string $label, int $minimum): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $minimum) {
            throw new \InvalidArgumentException('The normalized ' . $label . ' is invalid.');
        }

        return $value;
    }

    private function nullableFloat(mixed $value, string $label, float $minimum, float $maximum): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_float($value) && !is_int($value)) {
            throw new \InvalidArgumentException('The normalized ' . $label . ' is invalid.');
        }
        $value = (float) $value;
        if (!is_finite($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException('The normalized ' . $label . ' is invalid.');
        }

        return $value;
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private function sessionContext(Connection $connection): string
    {
        return sprintf('session:%s:%s:%d', $connection->id, $connection->provider, $connection->revision);
    }

    private function beginWrite(): void
    {
        if ($this->database->getPlatform()->isSqlite()) {
            $this->db->query('BEGIN IMMEDIATE');

            return;
        }
        $this->db->begin();
    }

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $encoded, string $label): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }
        if (!is_string($encoded)) {
            throw new \RuntimeException('The saved ' . $label . ' is invalid.');
        }
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new \RuntimeException('The saved ' . $label . ' is invalid.');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function decodeStringList(string $encoded, string $label): array
    {
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('The saved ' . $label . ' are invalid.');
        }
        foreach ($decoded as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException('The saved ' . $label . ' are invalid.');
            }
        }

        return $decoded;
    }
}
