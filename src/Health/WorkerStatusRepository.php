<?php

declare(strict_types=1);

namespace Mk\Framework\Health;

use Mk\Framework\Container;
use Mk\Framework\Database;

final class WorkerStatusRepository
{
    /** @var list<string> */
    private const COMPONENTS = [
        'history',
        'jellyseerr',
        'playback_notifications',
        'request_notifications',
        'playback_delivery',
        'request_delivery',
        'libraries',
    ];

    /** @var list<string> */
    private const ERROR_CODES = [
        'request_failed',
        'delivery_failed',
        'timeout',
        'authentication_failed',
        'database_failed',
    ];

    private readonly Database $database;
    private readonly \Dibi\Connection $connection;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?? Container::db();
        $this->connection = $this->database->getDibi();
        self::ensureSchema($this->database);
    }

    public static function ensureSchema(Database $database): void
    {
        $database->getPlatform()->createTable(
            'CREATE TABLE IF NOT EXISTS `system_status` (
                `source_id` varchar(64) NOT NULL,
                `component` varchar(64) NOT NULL,
                `attempt_sequence` bigint NOT NULL DEFAULT 0,
                `attempt_token` char(64) DEFAULT NULL,
                `status` varchar(16) NOT NULL,
                `error_code` varchar(64) DEFAULT NULL,
                `last_started_at` bigint DEFAULT NULL,
                `last_finished_at` bigint DEFAULT NULL,
                `last_success_at` bigint DEFAULT NULL,
                PRIMARY KEY (`source_id`, `component`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `system_status` (
                `source_id` TEXT NOT NULL,
                `component` TEXT NOT NULL,
                `attempt_sequence` INTEGER NOT NULL DEFAULT 0,
                `attempt_token` TEXT DEFAULT NULL,
                `status` TEXT NOT NULL,
                `error_code` TEXT DEFAULT NULL,
                `last_started_at` INTEGER DEFAULT NULL,
                `last_finished_at` INTEGER DEFAULT NULL,
                `last_success_at` INTEGER DEFAULT NULL,
                PRIMARY KEY (`source_id`, `component`)
            )'
        );

        $platform = $database->getPlatform();
        $columns = [
            'attempt_sequence' => ['`attempt_sequence` bigint NOT NULL DEFAULT 0', '`attempt_sequence` INTEGER NOT NULL DEFAULT 0'],
            'attempt_token' => ['`attempt_token` char(64) DEFAULT NULL', '`attempt_token` TEXT DEFAULT NULL'],
            'status' => ['`status` varchar(16) NOT NULL DEFAULT \'failed\'', '`status` TEXT NOT NULL DEFAULT \'failed\''],
            'error_code' => ['`error_code` varchar(64) DEFAULT NULL', '`error_code` TEXT DEFAULT NULL'],
            'last_started_at' => ['`last_started_at` bigint DEFAULT NULL', '`last_started_at` INTEGER DEFAULT NULL'],
            'last_finished_at' => ['`last_finished_at` bigint DEFAULT NULL', '`last_finished_at` INTEGER DEFAULT NULL'],
            'last_success_at' => ['`last_success_at` bigint DEFAULT NULL', '`last_success_at` INTEGER DEFAULT NULL'],
        ];
        foreach ($columns as $name => [$mariaDb, $sqlite]) {
            if (!$platform->columnExists('system_status', $name)) {
                $platform->addColumn('system_status', $mariaDb, $sqlite);
            }
        }
    }

    public function start(string $component, string $source = 'default', ?int $now = null): string
    {
        $this->assertComponent($component);
        $source = $this->normalizeSource($source);
        $now ??= time();
        $token = bin2hex(random_bytes(32));

        try {
            $this->connection->insert('system_status', [
                'source_id' => $source,
                'component' => $component,
                'attempt_sequence' => 1,
                'attempt_token' => $token,
                'status' => 'running',
                'error_code' => null,
                'last_started_at' => $now,
                'last_finished_at' => null,
                'last_success_at' => null,
            ])->execute();

            return $token;
        } catch (\Throwable $error) {
            if (!$this->rowExists($component, $source)) {
                throw $error;
            }
        }

        $this->connection->query(
            'UPDATE `system_status` SET `attempt_sequence` = `attempt_sequence` + 1, `attempt_token` = %s, `status` = %s, `error_code` = NULL, `last_started_at` = %i, `last_finished_at` = NULL WHERE `source_id` = %s AND `component` = %s AND (`last_started_at` IS NULL OR `last_started_at` <= %i)',
            $token,
            'running',
            $now,
            $source,
            $component,
            $now,
        );

        return $token;
    }

    public function succeed(string $component, string $token, string $source = 'default', ?int $now = null): void
    {
        $this->complete($component, $token, $source, 'success', null, $now);
    }

    public function fail(string $component, string $token, string $errorCode = 'request_failed', string $source = 'default', ?int $now = null): void
    {
        $errorCode = in_array($errorCode, self::ERROR_CODES, true) ? $errorCode : 'request_failed';
        $this->complete($component, $token, $source, 'failed', $errorCode, $now);
    }

    /** @return array<string, array{component:string,status:string,error_code:?string,last_started_at:?int,last_finished_at:?int,last_success_at:?int}> */
    public function all(string $source = 'default'): array
    {
        $rows = $this->connection->select('component, status, error_code, last_started_at, last_finished_at, last_success_at')
            ->from('system_status')->where('source_id = %s', $this->normalizeSource($source))->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $data = $row->toArray();
            foreach (['last_started_at', 'last_finished_at', 'last_success_at'] as $field) {
                $data[$field] = $data[$field] === null ? null : (int) $data[$field];
            }
            $result[(string) $data['component']] = $data;
        }

        return $result;
    }

    private function complete(string $component, string $token, string $source, string $status, ?string $errorCode, ?int $now): void
    {
        $this->assertComponent($component);
        $source = $this->normalizeSource($source);
        $now ??= time();
        $successSql = $status === 'success' ? ', `last_success_at` = %i' : '';
        $arguments = [$status, $errorCode, $now];
        if ($status === 'success') {
            $arguments[] = $now;
        }
        $arguments[] = $source;
        $arguments[] = $component;
        $arguments[] = $token;
        $this->connection->query(
            'UPDATE `system_status` SET `status` = %s, `error_code` = %s, `last_finished_at` = %i' . $successSql . ', `attempt_token` = NULL WHERE `source_id` = %s AND `component` = %s AND `attempt_token` = %s',
            ...$arguments,
        );
    }

    private function rowExists(string $component, string $source): bool
    {
        return (bool) $this->connection->select('1')->from('system_status')
            ->where('source_id = %s AND component = %s', $source, $component)->fetchSingle();
    }

    private function assertComponent(string $component): void
    {
        if (!in_array($component, self::COMPONENTS, true)) {
            throw new \InvalidArgumentException('Unknown worker status component.');
        }
    }

    private function normalizeSource(string $source): string
    {
        $source = trim($source);
        if ($source === '' || strlen($source) > 64) {
            throw new \InvalidArgumentException('Invalid worker status source.');
        }

        return $source;
    }
}
