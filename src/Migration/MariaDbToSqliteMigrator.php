<?php

declare(strict_types=1);

namespace Mk\Framework\Migration;

use Mk\Framework\Database;
use Mk\Framework\DatabaseSchemaInitializer;

final class MariaDbToSqliteMigrator
{
    private const BATCH_SIZE = 500;
    private const BLOB_BATCH_SIZE = 8;

    /** @var list<string> */
    private const TABLES = [
        'users',
        'login_attempts',
        'auth_remember_tokens',
        'app_settings',
        'play_history',
        'theme_item_classifications',
        'theme_classification_state',
        'movie_replacements',
        'movie_identity_items',
        'movie_identity_state',
        'push_subscriptions',
        'seerr_requests',
        'system_status',
        'integration_connections',
        'download_connection_state',
        'download_completions',
        'artwork_sources',
        'artwork_items',
        'artwork_posters',
        'artwork_images',
        'artwork_external_bindings',
        'artwork_external_searches',
        'artwork_external_assets',
        'artwork_external_bodies',
    ];

    /**
     * Old Jellydash releases legitimately lack later additive columns. These
     * are the minimum columns that identify a supported version of each core
     * table. Every source column still has to exist in the current destination
     * schema, so an unknown newer column can never be dropped silently.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_SOURCE_COLUMNS = [
        'users' => ['id', 'username', 'password', 'name', 'role'],
        'login_attempts' => ['id', 'identifier', 'attempts', 'locked_until', 'updated_at'],
        'auth_remember_tokens' => ['id', 'user_id', 'selector', 'validator_hash', 'expires_at', 'created_at', 'last_used_at'],
        'app_settings' => ['setting_key', 'setting_value', 'updated_at'],
        'play_history' => ['id', 'session_key', 'item_id', 'item_type', 'play_method', 'watched_sec', 'runtime_sec', 'started_at', 'updated_at', 'is_finished'],
        'theme_item_classifications' => ['server_key', 'item_id', 'item_type', 'classification', 'attempts', 'next_retry_epoch', 'updated_at_epoch'],
        'theme_classification_state' => ['server_key', 'revision'],
        'movie_replacements' => ['server_key', 'item_id', 'replacement_id'],
        'movie_identity_items' => ['server_key', 'item_id', 'data_json'],
        'movie_identity_state' => ['server_key', 'data_json', 'lease_token', 'lease_until_epoch'],
        'push_subscriptions' => ['id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'failure_count', 'created_at'],
        'seerr_requests' => ['id', 'request_id', 'media_type', 'tmdb_id', 'title', 'request_status', 'media_status', 'is_4k', 'requested_at', 'notified', 'created_at'],
        'system_status' => ['source_id', 'component', 'status'],
        'integration_connections' => ['id', 'provider', 'display_name', 'endpoint', 'endpoint_hash', 'username', 'verify_tls', 'enabled', 'filter_mode', 'categories_json', 'tags_json', 'credential_envelope', 'credential_source', 'config_revision', 'created_at_epoch', 'updated_at_epoch'],
        'download_connection_state' => ['connection_id', 'config_revision', 'lease_token', 'lease_expires_at', 'next_due_at', 'last_attempt_at', 'last_success_at', 'failure_code', 'failure_count', 'cursor_json', 'snapshot_json', 'snapshot_at', 'session_envelope'],
        'download_completions' => ['connection_id', 'source_id_digest', 'source_id', 'title', 'category', 'tags_json', 'size_bytes', 'completed_at', 'observed_at', 'last_seen_at'],
        'artwork_sources' => ['source_key', 'server_id', 'blocked', 'lease_token', 'lease_until', 'used_bytes', 'backfill_cursor'],
        'artwork_items' => ['source_key', 'item_id', 'item_type', 'title', 'series_name', 'poster_id', 'observed_at', 'evidence', 'status', 'next_attempt'],
        'artwork_posters' => ['source_key', 'poster_id', 'kind', 'requested_tag', 'observed_at', 'revision', 'attempts', 'next_attempt', 'last_checked', 'suppressed', 'last_error'],
        'artwork_images' => ['source_key', 'poster_id', 'body', 'content_type', 'tag', 'body_size', 'sha256', 'saved_at'],
        'artwork_external_bindings' => ['source_key', 'item_id', 'identity_key', 'kind', 'verified_id', 'identity_conflict', 'tmdb_id', 'poster_path', 'asset_key', 'presence', 'state', 'origin', 'suppressed', 'snoozed_until', 'revision', 'next_attempt', 'attempts', 'last_error', 'expires_at'],
        'artwork_external_assets' => ['source_key', 'asset_key', 'revision', 'kind', 'provider_id', 'poster_path', 'title', 'year', 'requested_language', 'image_language', 'body_size', 'content_type', 'sha256', 'saved_at', 'expires_at'],
        'artwork_external_bodies' => ['source_key', 'asset_key', 'body'],
        'artwork_external_searches' => ['source_key', 'query_key', 'server_id', 'config_revision', 'kind', 'query_title', 'acquired_at', 'expires_at', 'candidates_json', 'last_error', 'revision'],
    ];

    public function __construct(private readonly Database $source)
    {
    }

    /** @return array<string, int> */
    public function migrate(string $destinationPath): array
    {
        $this->assertSupportedSource();
        $this->reserveDestination($destinationPath);

        $sourceConnection = $this->source->getDibi();
        $destination = null;
        $destinationTransaction = false;
        $sourceTransaction = false;

        try {
            $destination = Database::sqlite($destinationPath);
            DatabaseSchemaInitializer::initialize($destination);
            $destinationConnection = $destination->getDibi();

            $sourceConnection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $sourceConnection->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $sourceTransaction = true;

            $destinationConnection->begin();
            $destinationTransaction = true;

            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = $this->copyAndVerifyTable($table, $destinationConnection);
            }

            $destinationConnection->commit();
            $destinationTransaction = false;
            $sourceConnection->query('COMMIT');
            $sourceTransaction = false;
            $destinationConnection->disconnect();

            return $counts;
        } catch (\Throwable $e) {
            if ($destinationTransaction && $destination !== null) {
                try {
                    $destination->getDibi()->rollback();
                } catch (\Throwable) {
                }
            }
            if ($sourceTransaction) {
                try {
                    $sourceConnection->query('ROLLBACK');
                } catch (\Throwable) {
                }
            }
            if ($destination !== null && $destination->getDibi()->isConnected()) {
                $destination->getDibi()->disconnect();
            }

            $this->removeDestination($destinationPath);
            throw $e;
        }
    }

    private function assertSupportedSource(): void
    {
        $driver = strtolower((string) $this->source->getDibi()->getConfig('driver'));
        if ($this->source->getPlatform()->isSqlite()) {
            throw new \InvalidArgumentException('The configured database is already SQLite.');
        }
        if ($driver !== 'mysqli') {
            throw new \InvalidArgumentException('MariaDB through the mysqli driver is required as the migration source.');
        }
    }

    private function reserveDestination(string $path): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException('A destination SQLite file is required.');
        }
        if (file_exists($path)) {
            throw new \InvalidArgumentException('The destination already exists. Nothing was changed.');
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException('The destination directory does not exist.');
        }

        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not safely create the destination SQLite file.');
        }
        fclose($handle);
    }

    private function copyAndVerifyTable(string $table, \Dibi\Connection $destination): int
    {
        $sourceInfo = $this->source->getDibi()->getDatabaseInfo();
        if (!$sourceInfo->hasTable($table)) {
            return 0;
        }

        $sourceColumns = $sourceInfo->getTable($table)->getColumnNames();
        $destinationColumns = $destination->getDatabaseInfo()->getTable($table)->getColumnNames();
        $this->assertColumnContract($table, $sourceColumns, $destinationColumns);
        $columns = array_values(array_intersect($destinationColumns, $sourceColumns));
        if ($columns === []) {
            throw new \RuntimeException("No compatible columns found for {$table}.");
        }

        $orderBy = match ($table) {
            'system_status' => ['source_id', 'component'],
            'theme_item_classifications' => ['server_key', 'item_id'],
            'theme_classification_state' => ['server_key'],
            'movie_replacements' => ['server_key', 'item_id'],
            'movie_identity_items' => ['server_key', 'item_id'],
            'movie_identity_state' => ['server_key'],
            'integration_connections' => ['id'],
            'download_connection_state' => ['connection_id'],
            'download_completions' => ['connection_id', 'source_id_digest'],
            'artwork_sources' => ['source_key'],
            'artwork_items', 'artwork_posters', 'artwork_images' => ['source_key', $table === 'artwork_items' ? 'item_id' : 'poster_id'],
            'artwork_external_bindings' => ['source_key', 'item_id'],
            'artwork_external_searches' => ['source_key', 'query_key'],
            'artwork_external_assets', 'artwork_external_bodies' => ['source_key', 'asset_key'],
            default => [in_array('id', $columns, true) ? 'id' : $columns[0]],
        };
        $expectedSourceCount = (int) $this->source->getDibi()
            ->select('COUNT(*)')->from($table)->fetchSingle();
        $batchSize = in_array($table, ['artwork_images', 'artwork_external_bodies'], true)
            ? self::BLOB_BATCH_SIZE
            : self::BATCH_SIZE;
        [$sourceCount, $sourceDigest] = $this->copyRows($table, $columns, $orderBy, $destination, $batchSize);
        if ($sourceCount !== $expectedSourceCount) {
            throw new \RuntimeException("Source row count changed while copying {$table}.");
        }

        if ($table === 'play_history' && !in_array('notified', $sourceColumns, true)) {
            $destination->query('UPDATE `play_history` SET `notified` = 1');
        }

        [$destinationCount, $destinationDigest] = $this->tableDigest($destination, $table, $columns, $orderBy, $batchSize);
        if ($sourceCount !== $destinationCount || !hash_equals($sourceDigest, $destinationDigest)) {
            throw new \RuntimeException("Verification failed for {$table}.");
        }

        return $sourceCount;
    }

    /**
     * @param list<string> $sourceColumns
     * @param list<string> $destinationColumns
     */
    private function assertColumnContract(string $table, array $sourceColumns, array $destinationColumns): void
    {
        $unexpected = array_values(array_diff($sourceColumns, $destinationColumns));
        if ($unexpected !== []) {
            throw new \RuntimeException(sprintf(
                'Unsupported source column(s) in %s: %s. Upgrade Jellydash before migrating so no data is discarded.',
                $table,
                implode(', ', $unexpected),
            ));
        }

        $missing = array_values(array_diff(self::REQUIRED_SOURCE_COLUMNS[$table], $sourceColumns));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'The %s source table is missing required column(s): %s.',
                $table,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @param list<string> $columns
     * @param list<string> $orderBy
     * @return array{int, string}
     */
    private function copyRows(
        string $table,
        array $columns,
        array $orderBy,
        \Dibi\Connection $destination,
        int $batchSize,
    ): array {
        $source = $this->source->getDibi();
        $hash = hash_init('sha256');
        $count = 0;
        $offset = 0;

        do {
            $rows = $this->selectBatch($source, $table, $columns, $orderBy, $offset, $batchSize);
            foreach ($rows as $row) {
                $data = $row->toArray();
                $this->updateDigest($hash, $data, $columns);
                if ($table === 'artwork_images') {
                    $destination->query(
                        'INSERT INTO `artwork_images` (`source_key`, `poster_id`, `body`, `content_type`, `tag`, `body_size`, `sha256`, `saved_at`) VALUES (%s, %s, %bin, %s, %s, %i, %s, %i)',
                        $data['source_key'],
                        $data['poster_id'],
                        $data['body'],
                        $data['content_type'],
                        $data['tag'],
                        $data['body_size'],
                        $data['sha256'],
                        $data['saved_at'],
                    );
                } elseif ($table === 'artwork_external_bodies') {
                    $destination->query(
                        'INSERT INTO `artwork_external_bodies` (`source_key`, `asset_key`, `body`) VALUES (%s, %s, %bin)',
                        $data['source_key'],
                        $data['asset_key'],
                        $data['body'],
                    );
                } else {
                    $destination->insert($table, $data)->execute();
                }
                ++$count;
            }
            $offset += count($rows);
        } while (count($rows) === $batchSize);

        return [$count, hash_final($hash)];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $orderBy
     * @return array{int, string}
     */
    private function tableDigest(
        \Dibi\Connection $connection,
        string $table,
        array $columns,
        array $orderBy,
        int $batchSize,
    ): array {
        $hash = hash_init('sha256');
        $count = 0;
        $offset = 0;

        do {
            $rows = $this->selectBatch($connection, $table, $columns, $orderBy, $offset, $batchSize);
            foreach ($rows as $row) {
                $this->updateDigest($hash, $row->toArray(), $columns);
                ++$count;
            }
            $offset += count($rows);
        } while (count($rows) === $batchSize);

        return [$count, hash_final($hash)];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $orderBy
     * @return list<\Dibi\Row>
     */
    private function selectBatch(
        \Dibi\Connection $connection,
        string $table,
        array $columns,
        array $orderBy,
        int $offset,
        int $batchSize,
    ): array {
        $driver = $connection->getDriver();
        $columnSql = implode(', ', array_map($driver->escapeIdentifier(...), $columns));

        $selection = $connection->select($columnSql)
            ->from($driver->escapeIdentifier($table));
        foreach ($orderBy as $column) {
            $selection->orderBy($driver->escapeIdentifier($column));
        }

        return $selection->limit($batchSize)
            ->offset($offset)
            ->fetchAll();
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    private function updateDigest(\HashContext $hash, array $row, array $columns): void
    {
        $encoded = '';
        foreach ($columns as $column) {
            $value = $row[$column];
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if ($value === null) {
                $encoded .= 'N';
            } else {
                $value = (string) $value;
                $encoded .= 'V' . pack('N', strlen($value)) . $value;
            }
        }
        hash_update($hash, $encoded);
    }

    private function removeDestination(string $path): void
    {
        @unlink($path . '-shm');
        @unlink($path . '-wal');
        @unlink($path);
    }
}
