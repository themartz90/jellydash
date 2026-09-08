<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Keeps the small amount of schema SQL that differs between MariaDB and
 * SQLite in one place. Application queries remain handled by Dibi.
 */
final class DatabasePlatform
{
    private bool $sqlite;

    public function __construct(private readonly \Dibi\Connection $connection)
    {
        $this->sqlite = self::isSqliteDriver((string) $connection->getConfig('driver'));
    }

    public static function isSqliteDriver(string $driver): bool
    {
        return in_array(strtolower($driver), ['sqlite', 'sqlite3'], true);
    }

    public function isSqlite(): bool
    {
        return $this->sqlite;
    }

    public function createTable(string $mariaDbSql, string $sqliteSql): void
    {
        $this->connection->query($this->sqlite ? $sqliteSql : $mariaDbSql);
    }

    /** @phpstan-impure */
    public function columnExists(string $table, string $column): bool
    {
        return $this->connection->getDatabaseInfo()
            ->getTable($table)
            ->hasColumn($column);
    }

    public function addColumn(
        string $table,
        string $mariaDbDefinition,
        string $sqliteDefinition,
    ): void {
        $escapedTable = $this->connection->getDriver()->escapeIdentifier($table);
        $definition = $this->sqlite ? $sqliteDefinition : $mariaDbDefinition;
        $this->connection->query('ALTER TABLE ' . $escapedTable . ' ADD COLUMN ' . $definition);
    }

    /**
     * MariaDB schemas declare their indexes inside CREATE TABLE. SQLite needs
     * separate CREATE INDEX statements.
     *
     * @param list<string> $columns
     */
    public function createSqliteIndex(string $name, string $table, array $columns): void
    {
        if (!$this->sqlite) {
            return;
        }

        $driver = $this->connection->getDriver();
        $escapedColumns = array_map($driver->escapeIdentifier(...), $columns);

        $this->connection->query(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
            $driver->escapeIdentifier($name),
            $driver->escapeIdentifier($table),
            implode(', ', $escapedColumns),
        ));
    }
}
