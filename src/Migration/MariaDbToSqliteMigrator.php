<?php

declare(strict_types=1);

namespace Mk\Framework\Migration;

use Mk\Framework\Database;
use Mk\Framework\DatabaseSchemaInitializer;

final class MariaDbToSqliteMigrator
{
    private const BATCH_SIZE = 500;

    /** @var list<string> */
    private const TABLES = [
        'users',
        'login_attempts',
        'auth_remember_tokens',
        'app_settings',
        'play_history',
        'push_subscriptions',
        'seerr_requests',
        'system_status',
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
        $columns = array_values(array_intersect($destinationColumns, $sourceColumns));
        if ($columns === []) {
            throw new \RuntimeException("No compatible columns found for {$table}.");
        }

        $orderBy = $table === 'system_status'
            ? ['source_id', 'component']
            : [in_array('id', $columns, true) ? 'id' : $columns[0]];
        [$sourceCount, $sourceDigest] = $this->copyRows($table, $columns, $orderBy, $destination);

        if ($table === 'play_history' && !in_array('notified', $sourceColumns, true)) {
            $destination->query('UPDATE `play_history` SET `notified` = 1');
        }

        [$destinationCount, $destinationDigest] = $this->tableDigest($destination, $table, $columns, $orderBy);
        if ($sourceCount !== $destinationCount || !hash_equals($sourceDigest, $destinationDigest)) {
            throw new \RuntimeException("Verification failed for {$table}.");
        }

        return $sourceCount;
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
    ): array {
        $source = $this->source->getDibi();
        $hash = hash_init('sha256');
        $count = 0;
        $offset = 0;

        do {
            $rows = $this->selectBatch($source, $table, $columns, $orderBy, $offset);
            foreach ($rows as $row) {
                $data = $row->toArray();
                $this->updateDigest($hash, $data, $columns);
                $destination->insert($table, $data)->execute();
                ++$count;
            }
            $offset += count($rows);
        } while (count($rows) === self::BATCH_SIZE);

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
    ): array {
        $hash = hash_init('sha256');
        $count = 0;
        $offset = 0;

        do {
            $rows = $this->selectBatch($connection, $table, $columns, $orderBy, $offset);
            foreach ($rows as $row) {
                $this->updateDigest($hash, $row->toArray(), $columns);
                ++$count;
            }
            $offset += count($rows);
        } while (count($rows) === self::BATCH_SIZE);

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
    ): array {
        $driver = $connection->getDriver();
        $columnSql = implode(', ', array_map($driver->escapeIdentifier(...), $columns));

        $selection = $connection->select($columnSql)
            ->from($driver->escapeIdentifier($table));
        foreach ($orderBy as $column) {
            $selection->orderBy($driver->escapeIdentifier($column));
        }

        return $selection->limit(self::BATCH_SIZE)
            ->offset($offset)
            ->fetchAll();
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    private function updateDigest(\HashContext $hash, array $row, array $columns): void
    {
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif ($value !== null) {
                $value = (string) $value;
            }
            $values[] = $value;
        }

        $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        hash_update($hash, pack('N', strlen($encoded)) . $encoded);
    }

    private function removeDestination(string $path): void
    {
        @unlink($path . '-shm');
        @unlink($path . '-wal');
        @unlink($path);
    }
}
