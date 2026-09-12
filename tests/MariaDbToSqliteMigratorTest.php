<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\DatabaseSchemaInitializer;
use Mk\Framework\Migration\MariaDbToSqliteMigrator;
use PHPUnit\Framework\TestCase;

final class MariaDbToSqliteMigratorTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_migration_';

    private string $databaseName = '';
    private string $destinationPath = '';
    private \Dibi\Connection $admin;
    private \Dibi\Connection $sourceConnection;
    private Database $source;

    protected function setUp(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->markTestSkipped('This migration test requires a MariaDB source.');
        }

        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
        ];
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }

        try {
            $this->admin = new \Dibi\Connection($config);
            $this->databaseName = self::DATABASE_PREFIX . getmypid() . '_' . bin2hex(random_bytes(4));
            $this->admin->query(
                'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->databaseName,
            );

            $config['database'] = $this->databaseName;
            $this->sourceConnection = new \Dibi\Connection($config);
            $this->source = new Database($this->sourceConnection);
        } catch (\Throwable $e) {
            $this->dropTemporaryDatabase();
            if (Config::env() === 'testing') {
                throw $e;
            }
            $this->markTestSkipped('Temporary MariaDB database unavailable: ' . $e->getMessage());
        }

        $this->destinationPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'jellydash_migration_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        $this->removeDestination();
        $this->dropTemporaryDatabase();
    }

    public function testCopiesAndVerifiesEveryOwnedTableWithoutChangingMariaDb(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->seedSource();

        $result = $this->runConsoleMigration(true);
        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $this->assertStringContainsString('Migration verified successfully', $result['output']);
        $this->assertStringContainsString('play_history: 1 row(s)', $result['output']);
        $this->assertFileExists($this->destinationPath);

        $destination = Database::sqlite($this->destinationPath);
        $sqlite = $destination->getDibi();
        $this->assertSame(101, (int) $sqlite->select('id')->from('users')->fetchSingle());
        $this->assertSame('migration-value', (string) $sqlite->select('setting_value')->from('app_settings')->fetchSingle());
        $this->assertStringStartsWith(
            '2026-08-11 11:55:00',
            (string) $sqlite->select('window_started_at')->from('login_attempts')->fetchSingle(),
        );
        $history = $sqlite->select('id, notified, library, library_resolved_at, watch_duration_sec, started_at_epoch, updated_at_epoch, ended_at_epoch, library_resolved_at_epoch, notification_attempts, notification_claim_token, notification_claimed_at_epoch, notification_next_attempt_at_epoch')->from('play_history')->fetch();
        $this->assertNotFalse($history);
        $this->assertSame(104, (int) $history['id']);
        $this->assertSame(0, (int) $history['notified']);
        $this->assertSame('Movies', (string) $history['library']);
        $this->assertStringStartsWith('2026-08-11 12:00:00', (string) $history['library_resolved_at']);
        $this->assertSame(275, (int) $history['watch_duration_sec']);
        $sample = $sqlite->select('last_sample_epoch, last_sample_position_sec, last_sample_paused, last_sample_rate')->from('play_history')->fetch();
        $this->assertNotFalse($sample);
        $this->assertSame(1786442700, (int) $sample['last_sample_epoch']);
        $this->assertSame(300, (int) $sample['last_sample_position_sec']);
        $this->assertSame(0, (int) $sample['last_sample_paused']);
        $this->assertSame(0.5, (float) $sample['last_sample_rate']);
        $this->assertSame(1786442400, (int) $history['started_at_epoch']);
        $this->assertSame(1786442700, (int) $history['updated_at_epoch']);
        $this->assertSame(1786442700, (int) $history['ended_at_epoch']);
        $this->assertSame(1786442400, (int) $history['library_resolved_at_epoch']);
        $this->assertSame(2, (int) $history['notification_attempts']);
        $this->assertSame(str_repeat('c', 64), (string) $history['notification_claim_token']);
        $this->assertSame(1786442710, (int) $history['notification_claimed_at_epoch']);
        $this->assertSame(1786443000, (int) $history['notification_next_attempt_at_epoch']);
        $classifications = $sqlite->select('server_key, item_id, item_type, classification, attempts, next_retry_epoch, updated_at_epoch')
            ->from('theme_item_classifications')->orderBy('server_key, item_id')->fetchAll();
        $this->assertCount(2, $classifications);
        $this->assertSame(str_repeat('1', 64), (string) $classifications[0]['server_key']);
        $this->assertSame('a-theme-item', (string) $classifications[0]['item_id']);
        $this->assertSame('Audio', (string) $classifications[0]['item_type']);
        $this->assertSame('theme', (string) $classifications[0]['classification']);
        $this->assertSame(3, (int) $classifications[0]['attempts']);
        $this->assertSame(1786443300, (int) $classifications[0]['next_retry_epoch']);
        $this->assertSame(1786443200, (int) $classifications[0]['updated_at_epoch']);
        $this->assertSame('z-ordinary-item', (string) $classifications[1]['item_id']);
        $this->assertSame('ordinary', (string) $classifications[1]['classification']);
        $this->assertNull($classifications[1]['next_retry_epoch']);
        $this->assertSame(
            7,
            (int) $sqlite->select('revision')->from('theme_classification_state')
                ->where('server_key = %s', str_repeat('1', 64))->fetchSingle(),
        );
        $this->assertSame('Migration Movie', (string) $sqlite->select('title')->from('seerr_requests')->fetchSingle());
        $status = $sqlite->select('status, error_code, last_started_at, last_finished_at, last_success_at')
            ->from('system_status')->where('source_id = %s AND component = %s', 'default', 'history')->fetch();
        $this->assertNotFalse($status);
        $this->assertSame('failed', (string) $status['status']);
        $this->assertSame('request_failed', (string) $status['error_code']);
        $this->assertSame(1786442400, (int) $status['last_started_at']);
        $this->assertSame(
            ['history', 'libraries', 'request_notifications'],
            array_map(
                static fn (\Dibi\Row $row): string => (string) $row['component'],
                $sqlite->select('component')->from('system_status')->where('source_id = %s', 'default')->orderBy('component')->fetchAll(),
            ),
        );
        $this->assertSame(
            str_repeat('a', 24),
            (string) $sqlite->select('selector')->from('auth_remember_tokens')->fetchSingle(),
        );
        $remember = $sqlite->select('previous_validator_hash, rotation_nonce, rotation_valid_until')
            ->from('auth_remember_tokens')->fetch();
        $this->assertNotFalse($remember);
        $this->assertSame(str_repeat('e', 64), (string) $remember['previous_validator_hash']);
        $this->assertSame(str_repeat('f', 32), (string) $remember['rotation_nonce']);
        $this->assertSame(1786442410, (int) $remember['rotation_valid_until']);
        $this->assertSame(
            str_repeat('9', 64),
            (string) $sqlite->select('device_capability_hash')->from('push_subscriptions')->fetchSingle(),
        );
        $this->assertSame(101, (int) $sqlite->select('user_id')->from('push_subscriptions')->fetchSingle());
        $this->assertSame(102, $destination->addAuthUser('after-migration', 'password-123', 'After Migration', 2));
        $sqlite->disconnect();

        foreach (['users', 'login_attempts', 'auth_remember_tokens', 'app_settings', 'play_history', 'theme_item_classifications', 'theme_classification_state', 'push_subscriptions', 'seerr_requests', 'system_status'] as $table) {
            $expected = match ($table) {
                'system_status' => 3,
                'theme_item_classifications' => 2,
                default => 1,
            };
            $this->assertSame($expected, (int) $this->sourceConnection->select('COUNT(*)')->from($table)->fetchSingle());
        }
    }

    public function testConsoleCommandRequiresStoppedConfirmation(): void
    {
        $result = $this->runConsoleMigration(false);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('--confirm-stopped', $result['error']);
        $this->assertFileDoesNotExist($this->destinationPath);
    }

    public function testMigrationCrossesTheBatchBoundary(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->sourceConnection->begin();
        for ($row = 1; $row <= 501; ++$row) {
            $this->sourceConnection->insert('app_settings', [
                'setting_key' => sprintf('batch-%03d', $row),
                'setting_value' => "value-{$row}",
                'updated_at' => '2026-08-11 12:00:00',
            ])->execute();
        }
        $this->sourceConnection->commit();

        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $this->assertSame(501, $counts['app_settings']);

        $destination = Database::sqlite($this->destinationPath);
        $this->assertSame(501, (int) $destination->getDibi()
            ->select('COUNT(*)')->from('app_settings')->fetchSingle());
        $destination->getDibi()->disconnect();
    }

    public function testRejectsAnUnknownSourceColumnInsteadOfDroppingItsData(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->sourceConnection->query('ALTER TABLE `app_settings` ADD COLUMN `future_private_value` varchar(64) DEFAULT NULL');
        $this->sourceConnection->insert('app_settings', [
            'setting_key' => 'future-column-fixture',
            'setting_value' => 'known value',
            'future_private_value' => 'must not be discarded',
            'updated_at' => '2026-09-10 12:00:00',
        ])->execute();

        try {
            (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
            $this->fail('An unknown source column must stop the migration.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('future_private_value', $e->getMessage());
            $this->assertStringContainsString('no data is discarded', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->destinationPath);
        $this->assertSame(
            'must not be discarded',
            (string) $this->sourceConnection->select('future_private_value')
                ->from('app_settings')->where('setting_key = %s', 'future-column-fixture')->fetchSingle(),
        );
    }

    public function testOlderHistoryWithoutNotificationColumnIsMigratedSafely(): void
    {
        $this->sourceConnection->query(
            'CREATE TABLE `play_history` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `session_key` varchar(128) NOT NULL,
                `item_id` varchar(64) NOT NULL,
                `item_type` varchar(16) NOT NULL,
                `play_method` varchar(32) NOT NULL,
                `watched_sec` int NOT NULL DEFAULT 0,
                `runtime_sec` int NOT NULL DEFAULT 0,
                `started_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `is_finished` tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_session_item` (`session_key`, `item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->sourceConnection->insert('play_history', [
            'id' => 77,
            'session_key' => 'legacy-session',
            'item_id' => 'legacy-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-11 12:00:00',
            'updated_at' => '2026-08-11 12:10:00',
            'is_finished' => 0,
        ])->execute();

        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $this->assertSame(0, $counts['users']);
        $this->assertSame(1, $counts['play_history']);
        $this->assertSame(0, $counts['theme_item_classifications']);
        $this->assertSame(0, $counts['theme_classification_state']);

        $destination = Database::sqlite($this->destinationPath);
        $row = $destination->getDibi()->select('id, notified')->from('play_history')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(77, (int) $row['id']);
        $this->assertSame(1, (int) $row['notified']);
        $destination->getDibi()->disconnect();
    }

    public function testFailedCopyRemovesDestinationAndLeavesSourceUntouched(): void
    {
        $this->sourceConnection->query(
            'CREATE TABLE `users` (
                `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                `username` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `name` varchar(100) NOT NULL,
                `role` tinyint(4) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        foreach ([1, 2] as $id) {
            $this->sourceConnection->insert('users', [
                'id' => $id,
                'username' => 'duplicate-user',
                'password' => 'password-hash',
                'name' => "Duplicate {$id}",
                'role' => 2,
            ])->execute();
        }

        try {
            (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
            $this->fail('The incompatible source should make the copy fail.');
        } catch (\Dibi\UniqueConstraintViolationException) {
        }

        $this->assertFileDoesNotExist($this->destinationPath);
        $this->assertSame(2, (int) $this->sourceConnection->select('COUNT(*)')->from('users')->fetchSingle());
    }

    public function testRefusesToOverwriteAnExistingDestination(): void
    {
        file_put_contents($this->destinationPath, 'keep me');

        try {
            (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
            $this->fail('An existing destination must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame('keep me', file_get_contents($this->destinationPath));
    }

    public function testUsesExclusiveFileCreationWhenWritableProbeIsUnreliable(): void
    {
        $directory = ROOT_DIR . '/var/cache';
        if (is_writable($directory)) {
            $this->markTestSkipped('This platform does not reproduce the Windows is_writable false-negative.');
        }

        $probePath = $directory . '/migration-write-probe-' . bin2hex(random_bytes(4));
        $this->assertNotFalse(file_put_contents($probePath, 'probe'));
        $this->assertTrue(unlink($probePath));

        $this->destinationPath = $directory
            . '/jellydash-migration-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        DatabaseSchemaInitializer::initialize($this->source);

        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);

        $this->assertSame(0, $counts['play_history']);
        $this->assertFileExists($this->destinationPath);
    }

    private function seedSource(): void
    {
        $this->sourceConnection->insert('users', [
            'id' => 101,
            'username' => 'migration-user',
            'password' => 'password-hash',
            'name' => 'Migration User',
            'role' => 2,
        ])->execute();
        $this->sourceConnection->insert('login_attempts', [
            'id' => 102,
            'identifier' => 'migration-user|127.0.0.1',
            'attempts' => 3,
            'locked_until' => null,
            'window_started_at' => '2026-08-11 11:55:00',
            'updated_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('auth_remember_tokens', [
            'id' => 107,
            'user_id' => 101,
            'selector' => str_repeat('a', 24),
            'validator_hash' => str_repeat('b', 64),
            'expires_at' => '2026-11-11 12:00:00',
            'created_at' => '2026-08-11 12:00:00',
            'last_used_at' => '2026-08-11 12:00:00',
            'previous_validator_hash' => str_repeat('e', 64),
            'rotation_nonce' => str_repeat('f', 32),
            'rotation_valid_until' => 1786442410,
        ])->execute();
        $this->sourceConnection->insert('app_settings', [
            'setting_key' => 'migration-key',
            'setting_value' => 'migration-value',
            'updated_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('play_history', [
            'id' => 104,
            'session_key' => 'migration-session',
            'item_id' => 'migration-item',
            'item_type' => 'Movie',
            'item_name' => 'Migration Movie',
            'library' => 'Movies',
            'library_resolved_at' => '2026-08-11 12:00:00',
            'play_method' => 'DirectPlay',
            'watched_sec' => 300,
            'watch_duration_sec' => 275,
            'last_sample_epoch' => 1786442700,
            'last_sample_position_sec' => 300,
            'last_sample_paused' => 0,
            'last_sample_rate' => 0.5,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-11 12:00:00',
            'started_at_epoch' => 1786442400,
            'updated_at' => '2026-08-11 12:05:00',
            'updated_at_epoch' => 1786442700,
            'ended_at' => '2026-08-11 12:05:00',
            'ended_at_epoch' => 1786442700,
            'library_resolved_at_epoch' => 1786442400,
            'notified' => 0,
            'notification_attempts' => 2,
            'notification_claim_token' => str_repeat('c', 64),
            'notification_claimed_at_epoch' => 1786442710,
            'notification_next_attempt_at_epoch' => 1786443000,
        ])->execute();
        foreach ([
            [
                'server_key' => str_repeat('1', 64),
                'item_id' => 'z-ordinary-item',
                'item_type' => 'Video',
                'classification' => 'ordinary',
                'attempts' => 0,
                'next_retry_epoch' => null,
                'updated_at_epoch' => 1786443100,
            ],
            [
                'server_key' => str_repeat('1', 64),
                'item_id' => 'a-theme-item',
                'item_type' => 'Audio',
                'classification' => 'theme',
                'attempts' => 3,
                'next_retry_epoch' => 1786443300,
                'updated_at_epoch' => 1786443200,
            ],
        ] as $classification) {
            $this->sourceConnection->insert('theme_item_classifications', $classification)->execute();
        }
        $this->sourceConnection->insert('theme_classification_state', [
            'server_key' => str_repeat('1', 64),
            'revision' => 7,
        ])->execute();
        $this->sourceConnection->insert('push_subscriptions', [
            'id' => 105,
            'endpoint' => 'https://push.example.test/migration',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/migration'),
            'p256dh' => 'migration-key',
            'auth' => 'migration-auth',
            'device_capability_hash' => str_repeat('9', 64),
            'user_id' => 101,
            'failure_count' => 0,
            'created_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('seerr_requests', [
            'id' => 106,
            'request_id' => 9001,
            'media_type' => 'movie',
            'tmdb_id' => 42,
            'title' => 'Migration Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => '2026-08-11 12:00:00',
            'notified' => 1,
            'created_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('system_status', [
            'source_id' => 'default',
            'component' => 'history',
            'attempt_sequence' => 2,
            'attempt_token' => null,
            'status' => 'failed',
            'error_code' => 'request_failed',
            'last_started_at' => 1786442400,
            'last_finished_at' => 1786442410,
            'last_success_at' => 1786442300,
        ])->execute();
        foreach (['request_notifications', 'libraries'] as $component) {
            $this->sourceConnection->insert('system_status', [
                'source_id' => 'default',
                'component' => $component,
                'attempt_sequence' => 1,
                'attempt_token' => null,
                'status' => 'success',
                'error_code' => null,
                'last_started_at' => 1786442500,
                'last_finished_at' => 1786442510,
                'last_success_at' => 1786442510,
            ])->execute();
        }
    }

    private function dropTemporaryDatabase(): void
    {
        if (!isset($this->admin) || $this->databaseName === '') {
            return;
        }

        if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/', $this->databaseName) !== 1) {
            throw new \RuntimeException('Refusing to drop an unsafe temporary database name.');
        }

        if (isset($this->sourceConnection) && $this->sourceConnection->isConnected()) {
            $this->sourceConnection->disconnect();
        }
        $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
        $this->databaseName = '';
    }

    /** @return array{exitCode: int, output: string, error: string} */
    private function runConsoleMigration(bool $confirmStopped): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_DRIVER' => (string) DATABASE_DRIVER_DIBI,
            'DB_HOST' => (string) DATABASE_HOST,
            'DB_PORT' => (string) DATABASE_PORT,
            'DB_NAME' => $this->databaseName,
            'DB_USER' => (string) DATABASE_USERNAME,
            'DB_PASS' => (string) DATABASE_PASSWORD,
        ]);

        $arguments = [
            PHP_BINARY,
            ROOT_DIR . '/bin/console.php',
            'database:migrate-to-sqlite',
            $this->destinationPath,
        ];
        if ($confirmStopped) {
            $arguments[] = '--confirm-stopped';
        }

        $pipes = [];
        $process = proc_open(
            $arguments,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);

        $output = trim((string) stream_get_contents($pipes[1]));
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'output' => $output,
            'error' => $error,
        ];
    }

    private function removeDestination(): void
    {
        if ($this->destinationPath === '') {
            return;
        }

        @unlink($this->destinationPath . '-shm');
        @unlink($this->destinationPath . '-wal');
        @unlink($this->destinationPath);
    }
}
