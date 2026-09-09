<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * DB-backed application settings (the Settings page writes these).
 *
 * Distinct from Config (environment): env carries infrastructure (DB
 * credentials, service URLs, secrets) while these are user preferences
 * editable from the UI. A key that has never been saved returns null, letting
 * callers fall back to a legacy env var; an explicitly saved empty string is a
 * real value ("user cleared this") and does NOT fall through.
 *
 * Reads are cached for the request; a DB outage degrades to defaults instead
 * of breaking the page. Privacy-sensitive callers can require a successful read.
 */
final class AppSettings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;
    private static bool $available = true;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public static function get(string $key, ?string $default = null, bool $requireAvailable = false): ?string
    {
        $all = self::load();
        if ($requireAvailable && !self::$available) {
            throw new \RuntimeException('Application settings are unavailable.');
        }

        return $all[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $database = Container::db();
        $db = $database->getDibi();
        self::ensureSchema($database);

        if ($value === null) {
            $db->delete('app_settings')->where('setting_key = %s', $key)->execute();
        } else {
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            try {
                $db->insert('app_settings', [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_at' => $now,
                ])->execute();
            } catch (\Dibi\UniqueConstraintViolationException) {
                $db->update('app_settings', ['setting_value' => $value, 'updated_at' => $now])
                    ->where('setting_key = %s', $key)
                    ->execute();
            }
        }

        if (self::$cache !== null) {
            if ($value === null) {
                unset(self::$cache[$key]);
            } else {
                self::$cache[$key] = $value;
            }
        }
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $database = Container::db();
            $db = $database->getDibi();
            self::ensureSchema($database);
            $rows = $db->select('setting_key, setting_value')->from('app_settings')->fetchPairs('setting_key', 'setting_value');
            self::$cache = array_map('strval', $rows);
            self::$available = true;
        } catch (\Throwable $e) {
            self::$available = false;
            Log::logException($e);
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function ensureSchema(Database $database): void
    {
        $db = $database->getDibi();
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$db])) {
            return;
        }

        (new DatabasePlatform($db))->createTable(
            'CREATE TABLE IF NOT EXISTS `app_settings` (
                `setting_key` varchar(64) NOT NULL,
                `setting_value` text NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `app_settings` (
                `setting_key` TEXT NOT NULL PRIMARY KEY,
                `setting_value` TEXT NOT NULL,
                `updated_at` TEXT NOT NULL
            )'
        );

        self::$schemaConnections[$db] = true;
    }
}
