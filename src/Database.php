<?php

declare(strict_types=1);

namespace Mk\Framework;

class Database
{
    private \Dibi\Connection $dibi;
    private DatabasePlatform $platform;

    public function __construct(?\Dibi\Connection $connection = null)
    {
        // Throws \Dibi\Exception on failure; handled centrally by ErrorHandler,
        // or by the caller where a graceful fallback exists (e.g. the login flow).
        $this->dibi = $connection ?? new \Dibi\Connection($this->connectionConfig());
        $this->platform = new DatabasePlatform($this->dibi);
    }

    // Return whole Dibi instance
    public function getDibi(): \Dibi\Connection
    {
        return $this->dibi;
    }

    public function getPlatform(): DatabasePlatform
    {
        return $this->platform;
    }

    public static function sqlite(string $path): self
    {
        return new self(new \Dibi\Connection(self::sqliteConnectionConfig($path)));
    }

    // Minimum length enforced when creating a user.
    public const MIN_PASSWORD_LENGTH = 8;

    /**
     * Create the auth tables when they don't exist yet. Fresh installs rely on
     * auto-created schema (no SQL import), so user management must be able to
     * bootstrap its own tables. Called from the console user commands.
     */
    public function ensureAuthSchema(): void
    {
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `users` (
                `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                `username` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `name` varchar(100) NOT NULL,
                `role` tinyint(4) NOT NULL,
                `auth_generation` bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `users` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `username` TEXT NOT NULL,
                `password` TEXT NOT NULL,
                `name` TEXT NOT NULL,
                `role` INTEGER NOT NULL,
                `auth_generation` INTEGER NOT NULL DEFAULT 0,
                UNIQUE (`username`)
            )'
        );

        if (!$this->platform->columnExists('users', 'auth_generation')) {
            try {
                $this->platform->addColumn('users', '`auth_generation` bigint NOT NULL DEFAULT 0', '`auth_generation` INTEGER NOT NULL DEFAULT 0');
            } catch (\Dibi\Exception $e) {
                if (!$this->platform->columnExists('users', 'auth_generation')) {
                    throw $e;
                }
            }
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` int NOT NULL AUTO_INCREMENT,
                `identifier` varchar(190) NOT NULL,
                `attempts` int NOT NULL DEFAULT 0,
                `locked_until` datetime DEFAULT NULL,
                `window_started_at` datetime DEFAULT NULL,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_identifier` (`identifier`),
                KEY `idx_login_attempts_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `identifier` TEXT NOT NULL,
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `locked_until` TEXT DEFAULT NULL,
                `window_started_at` TEXT DEFAULT NULL,
                `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (`identifier`)
            )'
        );
        if (!$this->platform->columnExists('login_attempts', 'window_started_at')) {
            try {
                $this->platform->addColumn(
                    'login_attempts',
                    '`window_started_at` datetime DEFAULT NULL AFTER `locked_until`',
                    '`window_started_at` TEXT DEFAULT NULL',
                );
            } catch (\Dibi\Exception $e) {
                if (!$this->platform->columnExists('login_attempts', 'window_started_at')) {
                    throw $e;
                }
            }
        }
        $this->platform->createIndexIfMissing('idx_login_attempts_updated', 'login_attempts', ['updated_at']);

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `login_admissions` (
                `token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                `source_key` varchar(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `pair_key` varchar(69) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `expires_at` bigint NOT NULL,
                KEY `idx_login_admissions_source` (`source_key`, `expires_at`),
                KEY `idx_login_admissions_expiry` (`expires_at`)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS `login_admissions` (
                `token` TEXT NOT NULL PRIMARY KEY,
                `source_key` TEXT NOT NULL,
                `pair_key` TEXT NOT NULL,
                `expires_at` INTEGER NOT NULL
            )'
        );
        $this->platform->createSqliteIndex('idx_login_admissions_source', 'login_admissions', ['source_key', 'expires_at']);
        $this->platform->createSqliteIndex('idx_login_admissions_expiry', 'login_admissions', ['expires_at']);

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `auth_remember_tokens` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(9) NOT NULL,
                `selector` char(24) NOT NULL,
                `validator_hash` char(64) NOT NULL,
                `expires_at` datetime NOT NULL,
                `created_at` datetime NOT NULL,
                `last_used_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_auth_remember_selector` (`selector`),
                KEY `idx_auth_remember_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `auth_remember_tokens` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id` INTEGER NOT NULL,
                `selector` TEXT NOT NULL,
                `validator_hash` TEXT NOT NULL,
                `expires_at` TEXT NOT NULL,
                `created_at` TEXT NOT NULL,
                `last_used_at` TEXT NOT NULL,
                UNIQUE (`selector`)
            )'
        );
        $this->platform->createSqliteIndex('idx_auth_remember_user', 'auth_remember_tokens', ['user_id']);
        foreach ([
            'auth_generation' => ['`auth_generation` bigint NOT NULL DEFAULT 0', '`auth_generation` INTEGER NOT NULL DEFAULT 0'],
            'previous_validator_hash' => ['`previous_validator_hash` char(64) DEFAULT NULL', '`previous_validator_hash` TEXT DEFAULT NULL'],
            'rotation_nonce' => ['`rotation_nonce` char(32) DEFAULT NULL', '`rotation_nonce` TEXT DEFAULT NULL'],
            'rotation_valid_until' => ['`rotation_valid_until` bigint NOT NULL DEFAULT 0', '`rotation_valid_until` INTEGER NOT NULL DEFAULT 0'],
        ] as $column => [$mariaDb, $sqlite]) {
            if (!$this->platform->columnExists('auth_remember_tokens', $column)) {
                try {
                    $this->platform->addColumn('auth_remember_tokens', $mariaDb, $sqlite);
                } catch (\Dibi\Exception $e) {
                    if (!$this->platform->columnExists('auth_remember_tokens', $column)) {
                        throw $e;
                    }
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function connectionConfig(): array
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            return self::sqliteConnectionConfig(DATABASE_NAME);
        }

        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
            'database' => DATABASE_NAME,
            'charset' => 'utf8mb4',
        ];

        if (defined('DATABASE_PORT') && DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private static function sqliteConnectionConfig(string $path): array
    {
        return [
            'driver' => 'sqlite3',
            'database' => $path,
            'formatDate' => "'Y-m-d'",
            'formatDateTime' => "'Y-m-d H:i:s'",
            'onConnect' => [
                'PRAGMA busy_timeout = 5000',
                'PRAGMA journal_mode = WAL',
            ],
        ];
    }

    /* CREATE NEW USER IN THE 'users' TABLE,
    ROLES: 1-owner, 2-admin, 3-regular, 4-guest */
    public function addAuthUser($username, $password, $name, $role): int
    {
        $username = trim(strtolower((string) $username));
        if ($username === '') {
            throw new \InvalidArgumentException('Username cannot be empty.');
        }
        if (strlen((string) $password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }

        $role = (int) $role;
        if (!Authorization::isValidRole($role)) {
            throw new \InvalidArgumentException('Role must be between 1 and 4.');
        }

        $dibi_data = [
            'username' => $username,
            'password' => password_hash((string) $password, PASSWORD_DEFAULT),
            'name' => ucfirst($name),
            'role' => $role,
        ];

        // Returns the new row id; throws \Dibi\Exception on failure.
        return (int) $this->dibi->insert('users', $dibi_data)->execute(\dibi::IDENTIFIER);
    }

    // Set (reset) a user's password. Returns false if no such user.
    public function setUserPassword(string $username, string $password): bool
    {
        $this->ensureAuthSchema();

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }

        $userId = $this->dibi->select('id')->from('users')
            ->where('username = %s', trim(strtolower($username)))->fetchSingle();
        if ($userId === null || $userId === false) {
            return false;
        }

        $this->dibi->begin();
        try {
            $this->dibi->query('UPDATE users SET password = %s, auth_generation = auth_generation + 1 WHERE id = %i',
                password_hash($password, PASSWORD_DEFAULT), $userId);
            $this->dibi->delete('auth_remember_tokens')->where('user_id = %i', $userId)->execute();
            $this->dibi->commit();
        } catch (\Throwable $e) {
            $this->dibi->rollback();
            throw $e;
        }

        return true;
    }

    public function setUserRole(string $username, int $role): bool
    {
        $this->ensureAuthSchema();
        if (!Authorization::isValidRole($role)) {
            throw new \InvalidArgumentException('Role must be between 1 and 4.');
        }

        $this->dibi->update('users', ['role' => $role])
            ->where('username = %s', trim(strtolower($username)))
            ->execute();

        return $this->dibi->getAffectedRows() > 0;
    }

    public function getUser($id): ?array
    {
        $row = $this->dibi->select('id, username, name, role, auth_generation')
            ->from('users')->where('id = %i', $id)->limit(1)->fetch();

        return $row?->toArray();
    }
}
