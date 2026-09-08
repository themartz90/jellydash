<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Push\PushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class SQLiteSchemaCompatibilityTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private Database $database;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('The sqlite3 extension is not available.');
        }

        $this->dibi = new \Dibi\Connection([
            'driver' => 'sqlite3',
            'database' => ':memory:',
            'formatDate' => "'Y-m-d'",
            'formatDateTime' => "'Y-m-d H:i:s'",
        ]);
        $this->database = new Database($this->dibi);

        Container::reset();
        Container::set('db', $this->database);
        $this->resetSchemaState();
    }

    protected function tearDown(): void
    {
        Container::reset();
        $this->resetSchemaState();
    }

    public function testFreshDatabaseCreatesEveryOwnedTableAndIndex(): void
    {
        $this->initializeAllSchemas();

        $this->assertSame([
            'app_settings',
            'auth_remember_tokens',
            'login_attempts',
            'play_history',
            'push_subscriptions',
            'seerr_requests',
            'users',
        ], $this->tableNames());

        $this->assertSame([
            'idx_library',
            'idx_started_at',
            'idx_user_name',
        ], $this->namedIndexes('play_history'));
        $this->assertSame(['idx_requested_at'], $this->namedIndexes('seerr_requests'));
        $this->assertSame(['idx_auth_remember_user'], $this->namedIndexes('auth_remember_tokens'));
    }

    public function testEnvironmentConnectionUsesSQLiteSafetySettings(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'jellydash_sqlite_');
        $this->assertNotFalse($databasePath);

        try {
            $result = $this->runConnectionProbe($databasePath);
            $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);

            $data = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($data);
            $this->assertSame('sqlite3', $data['driver']);
            $this->assertSame('wal', strtolower((string) $data['journalMode']));
            $this->assertSame(5000, $data['busyTimeout']);
            $this->assertSame('2026-08-11 12:34:56', $data['dateTime']);
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-shm');
            @unlink($databasePath . '-wal');
        }
    }

    public function testSchemaInitializationPreservesRowsAndUpgradesNotifiedColumn(): void
    {
        $this->initializeAllSchemas();
        $this->dibi->insert('users', [
            'username' => 'sqlite-schema-user',
            'password' => 'not-used',
            'name' => 'SQLite Schema User',
            'role' => 2,
        ])->execute();
        $userId = (int) $this->dibi->select('id')->from('users')->fetchSingle();
        $this->dibi->insert('auth_remember_tokens', [
            'user_id' => $userId,
            'selector' => str_repeat('c', 24),
            'validator_hash' => str_repeat('d', 64),
            'expires_at' => '2026-11-11 12:00:00',
            'created_at' => '2026-08-11 12:00:00',
            'last_used_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->dibi->insert('play_history', [
            'session_key' => 'sqlite-session',
            'item_id' => 'sqlite-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 300,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-11 12:00:00',
            'updated_at' => '2026-08-11 12:05:00',
        ])->execute();
        $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN `notified`');
        $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN `library_resolved_at`');
        foreach (['notification_attempts', 'notification_claim_token', 'notification_claimed_at_epoch', 'notification_next_attempt_at_epoch'] as $column) {
            $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN %n', $column);
            $this->dibi->query('ALTER TABLE `seerr_requests` DROP COLUMN %n', $column);
        }
        foreach (['previous_validator_hash', 'rotation_nonce', 'rotation_valid_until'] as $column) {
            $this->dibi->query('ALTER TABLE `auth_remember_tokens` DROP COLUMN %n', $column);
        }

        $this->resetSchemaState();
        $this->initializeAllSchemas();

        $row = $this->dibi->select('watched_sec, notified, library_resolved_at, notification_attempts, notification_claim_token, notification_claimed_at_epoch, notification_next_attempt_at_epoch')->from('play_history')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(300, (int) $row['watched_sec']);
        $this->assertSame(1, (int) $row['notified']);
        $this->assertNull($row['library_resolved_at']);
        $this->assertSame(0, (int) $row['notification_attempts']);
        $this->assertNull($row['notification_claim_token']);
        $this->assertNull($row['notification_claimed_at_epoch']);
        $this->assertNull($row['notification_next_attempt_at_epoch']);
        foreach (['notification_attempts', 'notification_claim_token', 'notification_claimed_at_epoch', 'notification_next_attempt_at_epoch'] as $column) {
            $this->assertTrue($this->database->getPlatform()->columnExists('seerr_requests', $column));
        }
        $remember = $this->dibi->select('previous_validator_hash, rotation_nonce, rotation_valid_until')
            ->from('auth_remember_tokens')->fetch();
        $this->assertNotFalse($remember);
        $this->assertNull($remember['previous_validator_hash']);
        $this->assertNull($remember['rotation_nonce']);
        $this->assertSame(0, (int) $remember['rotation_valid_until']);
    }

    public function testCoreWritesUseSQLiteConstraintAndAutoincrementSemantics(): void
    {
        $this->initializeAllSchemas();

        $userId = $this->database->addAuthUser('sqlite-user', 'password-123', 'SQLite User', Authorization::ROLE_ADMIN);
        $this->assertGreaterThan(0, $userId);

        AppSettings::set('sqlite_test', 'first');
        AppSettings::set('sqlite_test', 'updated');
        $this->assertSame('updated', AppSettings::get('sqlite_test'));

        $subscriptions = new PushSubscriptionRepository($this->database);
        $endpoint = 'https://push.example.test/sqlite';
        $subscriptions->save($endpoint, $this->encodedBytes(65), $this->encodedBytes(16), 'First agent');
        $subscriptions->save($endpoint, $this->encodedBytes(65), $this->encodedBytes(16), 'Updated agent');
        $this->assertSame(1, $subscriptions->count());

        $requests = new SeerrRequestRepository($this->database);
        $request = [
            'request_id' => 42,
            'media_type' => 'movie',
            'tmdb_id' => 84,
            'title' => 'SQLite Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => '2026-08-11 12:00:00',
            'notified' => 0,
            'created_at' => '2026-08-11 12:00:00',
        ];
        $requests->insert($request);
        $requests->insert($request);
        $this->assertSame(1, $requests->count());
        $this->assertCount(1, $requests->claimUnnotified());
        $this->assertSame([], $requests->claimUnnotified());
    }

    public function testRecentlyAddedPreferenceDefaultsOnAndCanBeToggled(): void
    {
        $previousValue = AppSettings::get('show_recently_added');

        try {
            AppSettings::set('show_recently_added', null);
            $this->assertTrue(AppSettings::bool('show_recently_added', true));

            AppSettings::set('show_recently_added', '0');
            $this->assertFalse(AppSettings::bool('show_recently_added', true));

            AppSettings::set('show_recently_added', '1');
            $this->assertTrue(AppSettings::bool('show_recently_added', true));
        } finally {
            AppSettings::set('show_recently_added', $previousValue);
        }
    }

    private function initializeAllSchemas(): void
    {
        $this->database->ensureAuthSchema();
        AppSettings::set('schema_test', 'ok');
        new PlayHistoryRepository($this->database);
        new PushSubscriptionRepository($this->database);
        new SeerrRequestRepository($this->database);
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $tables = $this->dibi->getDatabaseInfo()->getTableNames();
        $tables = array_values(array_filter($tables, static fn (string $table): bool => $table !== 'sqlite_sequence'));
        sort($tables);

        return $tables;
    }

    /** @return list<string> */
    private function namedIndexes(string $table): array
    {
        $names = [];
        foreach ($this->dibi->query('PRAGMA index_list(%n)', $table)->fetchAll() as $row) {
            $name = (string) $row['name'];
            if (!str_starts_with($name, 'sqlite_autoindex_')) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    private function encodedBytes(int $length): string
    {
        return rtrim(strtr(base64_encode(str_repeat('a', $length)), '+/', '-_'), '=');
    }

    /** @return array{exitCode: int, output: string, error: string} */
    private function runConnectionProbe(string $databasePath): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_DRIVER' => 'sqlite3',
            'DB_NAME' => $databasePath,
        ]);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/sqlite-connection-probe.php'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'output' => trim((string) $output),
            'error' => trim((string) $error),
        ];
    }

    private function resetSchemaState(): void
    {
        $properties = [
            [AppSettings::class, 'cache', null],
            [AppSettings::class, 'schemaConnections', null],
            [PlayHistoryRepository::class, 'schemaConnections', null],
            [PushSubscriptionRepository::class, 'schemaConnections', null],
            [SeerrRequestRepository::class, 'schemaConnections', null],
        ];

        foreach ($properties as [$class, $property, $value]) {
            (new \ReflectionClass($class))->getProperty($property)->setValue(null, $value);
        }
    }
}
