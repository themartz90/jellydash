<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Health\WorkerStatusRepository;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Jellyfin\MovieIdentityRepository;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\View;
use PHPUnit\Framework\TestCase;

final class SchemaCompatibilityTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_schema_';

    private string $databaseName = '';
    private \Dibi\Connection $admin;
    private \Dibi\Connection $dibi;
    private Database $database;

    protected function setUp(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->markTestSkipped('This compatibility test uses a temporary MariaDB database.');
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
            $this->assertSafeDatabaseName($this->databaseName);
            $this->admin->query(
                'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->databaseName
            );

            $config['database'] = $this->databaseName;
            $this->dibi = new \Dibi\Connection($config);
            $this->database = $this->databaseUsing($this->dibi);
        } catch (\Throwable $e) {
            $this->dropTemporaryDatabase();
            if (Config::env() === 'testing') {
                throw $e;
            }
            $this->markTestSkipped('Temporary database unavailable: ' . $e->getMessage());
        }

        Container::reset();
        Container::set('db', $this->database);
        $this->resetSchemaState();
    }

    protected function tearDown(): void
    {
        Container::reset();
        $this->resetSchemaState();
        $this->dropTemporaryDatabase();
    }

    public function testFreshDatabaseCreatesEveryOwnedTableWithoutSqlImport(): void
    {
        $this->initializeAllSchemas();

        $this->assertSame([
            'app_settings',
            'auth_remember_tokens',
            'download_completions',
            'download_connection_state',
            'integration_connections',
            'login_attempts',
            'movie_identity_items',
            'movie_identity_state',
            'movie_replacements',
            'play_history',
            'push_subscriptions',
            'seerr_requests',
            'system_status',
            'theme_classification_state',
            'theme_item_classifications',
            'users',
        ], $this->tableNames());
    }

    public function testApplicationMariaDbConnectionUsesUtf8mb4(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->markTestSkipped('This assertion applies only to MariaDB connections.');
        }

        $connection = (new Database())->getDibi();

        $this->assertSame(
            'utf8mb4',
            strtolower((string) $connection->query('SELECT @@character_set_connection')->fetchSingle())
        );
        $this->assertSame('🪼', (string) $connection->query('SELECT %s AS value', '🪼')->fetchSingle());
    }

    public function testShortHistoryMetadataColumnsUpgradeWithoutLosingRows(): void
    {
        $this->initializeAllSchemas();
        $this->dibi->query('ALTER TABLE play_history MODIFY user_name varchar(128) DEFAULT NULL, MODIFY library varchar(64) DEFAULT NULL, MODIFY client varchar(64) DEFAULT NULL, MODIFY device varchar(64) DEFAULT NULL, MODIFY item_name varchar(255) DEFAULT NULL, MODIFY series_name varchar(255) DEFAULT NULL');
        $this->dibi->insert('play_history', [
            'session_key' => 'existing-metadata', 'item_id' => 'existing-item', 'item_type' => 'Movie',
            'user_name' => 'Existing Viewer', 'library' => 'Existing library', 'play_method' => 'DirectPlay',
            'started_at' => '2026-09-08 12:00:00', 'updated_at' => '2026-09-08 12:01:00', 'notified' => 1,
        ])->execute();
        $before = $this->dibi->select('*')->from('play_history')->fetch()->toArray();
        $this->dibi->query('ALTER TABLE theme_item_classifications DROP COLUMN observation_version');
        $this->dibi->insert('theme_item_classifications', [
            'server_key' => 'legacy-observation', 'item_id' => 'old-theme', 'item_type' => 'Audio',
            'classification' => 'theme', 'attempts' => 0, 'updated_at_epoch' => 1000,
        ])->execute();
        $this->resetSchemaState();
        $history = new PlayHistoryRepository($this->database);
        $this->assertSame(0, (int) $this->dibi->select('observation_version')->from('theme_item_classifications')->where('item_id = %s', 'old-theme')->fetchSingle());
        $this->assertSame('theme', $this->dibi->select('classification')->from('theme_item_classifications')->where('item_id = %s', 'old-theme')->fetchSingle());
        $this->assertEquals($before, $this->dibi->select('*')->from('play_history')->where('session_key = %s', 'existing-metadata')->fetch()->toArray());
        $name = str_repeat('Ž', 300);
        $history->logActiveStreams([[
            'id' => 'long-metadata', 'itemId' => 'long-item', 'itemType' => 'Movie',
            'user' => $name, 'library' => $name, 'itemName' => $name, 'seriesName' => $name,
            'client' => $name, 'device' => $name, 'playMethod' => 'DirectPlay', 'watchedSec' => 30, 'runtimeSec' => 300,
        ]], new DateTimeImmutable('2026-09-08 13:00:00'));
        $row = $this->dibi->select('*')->from('play_history')->where('session_key = %s', 'long-metadata')->fetch();
        foreach (['user_name', 'library', 'item_name', 'series_name', 'client', 'device'] as $field) {
            $this->assertSame($name, $row[$field]);
        }
    }

    public function testExistingRowsSurviveSchemaInitialization(): void
    {
        $this->initializeAllSchemas();

        $this->dibi->insert('movie_identity_items', [
            'server_key' => str_repeat('1', 64), 'item_id' => 'Identity-Movie ',
            'data_json' => '{"title":"Original movie","providers":{"Tmdb":"123"}}',
        ])->execute();
        $this->dibi->insert('movie_identity_state', [
            'server_key' => str_repeat('1', 64), 'data_json' => '{"scan":"running","offset":100}',
            'lease_token' => str_repeat('f', 64), 'lease_until_epoch' => 2000,
        ])->execute();

        $this->dibi->insert('movie_replacements', [
            'server_key' => str_repeat('1', 64),
            'item_id' => 'Old-Movie ',
            'replacement_id' => 'Current-Movie',
        ])->execute();
        $this->dibi->insert('users', [
            'username' => 'schema-user',
            'password' => 'not-used',
            'name' => 'Schema User',
            'role' => 2,
        ])->execute();
        $this->dibi->insert('login_attempts', [
            'identifier' => 'schema-user|127.0.0.1',
            'attempts' => 2,
            'locked_until' => null,
            'updated_at' => '2026-08-09 12:00:00',
        ])->execute();
        $userId = (int) $this->dibi->select('id')->from('users')->fetchSingle();
        $this->dibi->insert('auth_remember_tokens', [
            'user_id' => $userId,
            'selector' => str_repeat('a', 24),
            'validator_hash' => str_repeat('b', 64),
            'expires_at' => '2026-11-09 12:00:00',
            'created_at' => '2026-08-09 12:00:00',
            'last_used_at' => '2026-08-09 12:00:00',
        ])->execute();
        $this->dibi->insert('play_history', [
            'session_key' => 'schema-session',
            'item_id' => 'schema-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 300,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-09 12:00:00',
            'updated_at' => '2026-08-09 12:05:00',
        ])->execute();
        $this->dibi->insert('push_subscriptions', [
            'endpoint' => 'https://push.example.test/schema',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/schema'),
            'p256dh' => 'schema-key',
            'auth' => 'schema-auth',
            'failure_count' => 0,
            'created_at' => '2026-08-09 12:00:00',
        ])->execute();
        $this->dibi->insert('seerr_requests', [
            'request_id' => 9001,
            'media_type' => 'movie',
            'tmdb_id' => 42,
            'title' => 'Schema Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => '2026-08-09 12:00:00',
            'notified' => 0,
            'created_at' => '2026-08-09 12:00:00',
        ])->execute();

        // This matches an install from before playback notifications existed.
        $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN `notified`');
        $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN `library_resolved_at`');
        $this->dibi->query('ALTER TABLE `seerr_requests` DROP COLUMN `requested_at_epoch`');
        foreach (['notification_attempts', 'notification_claim_token', 'notification_claimed_at_epoch', 'notification_next_attempt_at_epoch'] as $column) {
            $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN %n', $column);
            $this->dibi->query('ALTER TABLE `seerr_requests` DROP COLUMN %n', $column);
        }
        foreach (['auth_generation', 'previous_validator_hash', 'rotation_nonce', 'rotation_valid_until'] as $column) {
            $this->dibi->query('ALTER TABLE `auth_remember_tokens` DROP COLUMN %n', $column);
        }
        $this->dibi->query('ALTER TABLE `users` DROP COLUMN `auth_generation`');
        $this->dibi->query('ALTER TABLE `login_attempts` DROP COLUMN `window_started_at`');
        $this->dibi->query('DROP INDEX `idx_login_attempts_updated` ON `login_attempts`');
        $this->dibi->query('DROP INDEX `idx_push_subscription_user` ON `push_subscriptions`');

        $this->resetSchemaState();
        $this->initializeAllSchemas();

        $replacement = $this->dibi->select('server_key, item_id, replacement_id')->from('movie_replacements')->fetch();
        $this->assertNotFalse($replacement);
        $this->assertSame('Old-Movie ', (string) $replacement['item_id']);
        $this->assertSame('Current-Movie', (string) $replacement['replacement_id']);
        $identity = $this->dibi->select('item_id, data_json')->from('movie_identity_items')->fetch();
        $this->assertNotFalse($identity);
        $this->assertSame('Identity-Movie ', (string) $identity['item_id']);
        $this->assertSame('{"title":"Original movie","providers":{"Tmdb":"123"}}', (string) $identity['data_json']);
        $identityState = $this->dibi->select('data_json, lease_token, lease_until_epoch')->from('movie_identity_state')->fetch();
        $this->assertNotFalse($identityState);
        $this->assertSame('{"scan":"running","offset":100}', (string) $identityState['data_json']);
        $this->assertSame(str_repeat('f', 64), (string) $identityState['lease_token']);
        $this->assertSame(2000, (int) $identityState['lease_until_epoch']);
        $this->assertSame('schema-user', (string) $this->dibi->select('username')->from('users')->fetchSingle());
        $this->assertSame(2, (int) $this->dibi->select('attempts')->from('login_attempts')->fetchSingle());
        $this->assertNull($this->dibi->select('window_started_at')->from('login_attempts')->fetchSingle());
        $this->assertArrayHasKey(
            'idx_login_attempts_updated',
            $this->dibi->getDatabaseInfo()->getTable('login_attempts')->getIndexes(),
        );
        $this->assertArrayHasKey(
            'idx_push_subscription_user',
            $this->dibi->getDatabaseInfo()->getTable('push_subscriptions')->getIndexes(),
        );
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('auth_remember_tokens')->fetchSingle());
        $this->assertSame(0, (int) $this->dibi->select('auth_generation')->from('users')->fetchSingle());
        $remember = $this->dibi->select('auth_generation, previous_validator_hash, rotation_nonce, rotation_valid_until')
            ->from('auth_remember_tokens')->fetch();
        $this->assertNotFalse($remember);
        $this->assertSame(0, (int) $remember['auth_generation']);
        $this->assertNull($remember['previous_validator_hash']);
        $this->assertNull($remember['rotation_nonce']);
        $this->assertSame(0, (int) $remember['rotation_valid_until']);
        $this->assertSame(300, (int) $this->dibi->select('watched_sec')->from('play_history')->fetchSingle());
        $this->assertSame(1, (int) $this->dibi->select('notified')->from('play_history')->fetchSingle());
        $this->assertNull($this->dibi->select('library_resolved_at')->from('play_history')->fetchSingle());
        $this->assertSame('ok', AppSettings::get('schema_test'));
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('push_subscriptions')->fetchSingle());
        $this->assertSame('Schema Movie', (string) $this->dibi->select('title')->from('seerr_requests')->fetchSingle());
        $this->assertNull($this->dibi->select('requested_at_epoch')->from('seerr_requests')->fetchSingle());
        $this->assertSame(0, (int) $this->dibi->select('notification_attempts')->from('play_history')->fetchSingle());
        $this->assertSame(0, (int) $this->dibi->select('notification_attempts')->from('seerr_requests')->fetchSingle());
    }

    public function testServerStatsPreferenceDefaultsOnAndCanBeToggled(): void
    {
        $previousValue = AppSettings::get('show_server_stats');

        try {
            AppSettings::set('show_server_stats', null);
            $this->assertTrue(AppSettings::bool('show_server_stats', true));
            $this->assertStringContainsString('data-server-card', $this->renderSidebar());

            AppSettings::set('show_server_stats', '0');
            $this->assertFalse(AppSettings::bool('show_server_stats', true));
            $this->assertStringNotContainsString('data-server-card', $this->renderSidebar());

            AppSettings::set('show_server_stats', '1');
            $this->assertTrue(AppSettings::bool('show_server_stats', true));
            $this->assertStringContainsString('data-server-card', $this->renderSidebar());
        } finally {
            AppSettings::set('show_server_stats', $previousValue);
        }
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

    public function testConcurrentJellyseerrWorkersClaimRequestOnlyOnce(): void
    {
        new SeerrRequestRepository($this->database);
        WorkerStatusRepository::ensureSchema($this->database);
        $now = (new DateTimeImmutable('now', new DateTimeZone(Config::timezone())))->format('Y-m-d H:i:s');
        $this->dibi->insert('seerr_requests', [
            'request_id' => 9002,
            'media_type' => 'movie',
            'tmdb_id' => 43,
            'title' => 'Concurrent Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => $now,
            'requested_at_epoch' => time(),
            'notified' => 0,
            'created_at' => $now,
        ])->execute();

        // Hold the first claim update long enough for a second worker to read
        // the same unclaimed row. A safe claim lets only one worker return it.
        $this->dibi->query(
            'CREATE TRIGGER `delay_seerr_claim`
             BEFORE UPDATE ON `seerr_requests`
             FOR EACH ROW
             SET @claim_delay = IF(OLD.notified = 0 AND NEW.notified = 1, SLEEP(1), 0)'
        );

        $first = $this->startClaimWorker();
        usleep(150_000);
        $second = $this->startClaimWorker();

        $firstResult = $this->finishClaimWorker($first);
        $secondResult = $this->finishClaimWorker($second);

        $this->assertSame('', $firstResult['error']);
        $this->assertSame('', $secondResult['error']);
        $this->assertSame(0, $firstResult['exitCode'], $firstResult['error'] . $firstResult['output']);
        $this->assertSame(0, $secondResult['exitCode'], $secondResult['error'] . $secondResult['output']);
        $this->assertSame(1, $firstResult['claimed'] + $secondResult['claimed']);
        $this->assertSame(1, (int) $this->dibi->select('notified')->from('seerr_requests')->fetchSingle());
    }

    private function initializeAllSchemas(): void
    {
        $this->database->ensureAuthSchema();
        AppSettings::set('schema_test', 'ok');
        new PlayHistoryRepository($this->database);
        new ThemePlaybackExclusions($this->database);
        new MovieReplacements($this->database);
        new MovieIdentityRepository($this->database);
        new PushSubscriptionRepository($this->database);
        new SeerrRequestRepository($this->database);
        WorkerStatusRepository::ensureSchema($this->database);
        ConnectionRepository::ensureSchema($this->database);
        DownloadRepository::ensureSchema($this->database);
    }

    private function renderSidebar(): string
    {
        $previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';

        try {
            ob_start();
            (new View())->render('_sidebar', ['layout' => ['page' => 'settings']]);
            $output = (string) ob_get_clean();
        } finally {
            if ($previousDebug === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $previousDebug;
            }
        }

        return $output;
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function startClaimWorker(): array
    {
        $pipes = [];
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_DRIVER' => (string) DATABASE_DRIVER_DIBI,
            'DB_HOST' => (string) DATABASE_HOST,
            'DB_PORT' => (string) DATABASE_PORT,
            'DB_NAME' => $this->databaseName,
            'DB_USER' => (string) DATABASE_USERNAME,
            'DB_PASS' => (string) DATABASE_PASSWORD,
        ]);

        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/seerr-claim-worker.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start Jellyseerr claim worker.');
        }

        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>} $worker
     * @return array{claimed: int, output: string, error: string, exitCode: int}
     */
    private function finishClaimWorker(array $worker): array
    {
        $output = stream_get_contents($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);

        return [
            'claimed' => (int) trim((string) $output),
            'output' => trim((string) $output),
            'error' => trim((string) $error),
            'exitCode' => proc_close($worker['process']),
        ];
    }

    /** @return array<int, string> */
    private function tableNames(): array
    {
        $tables = [];
        foreach ($this->dibi->query('SHOW TABLES')->fetchAll() as $row) {
            $values = array_values($row->toArray());
            $tables[] = (string) $values[0];
        }

        sort($tables);

        return $tables;
    }

    private function databaseUsing(\Dibi\Connection $connection): Database
    {
        return new Database($connection);
    }

    private function resetSchemaState(): void
    {
        $properties = [
            [AppSettings::class, 'cache', null],
            [AppSettings::class, 'schemaConnections', null],
            [PlayHistoryRepository::class, 'schemaConnections', null],
            [\Mk\Framework\Jellyfin\ThemePlaybackExclusions::class, 'schemaConnections', null],
            [MovieReplacements::class, 'schemaConnections', null],
            [MovieIdentityRepository::class, 'schemaConnections', null],
            [PushSubscriptionRepository::class, 'schemaConnections', null],
            [SeerrRequestRepository::class, 'schemaConnections', null],
        ];

        foreach ($properties as [$class, $property, $value]) {
            (new \ReflectionClass($class))->getProperty($property)->setValue(null, $value);
        }
    }

    private function dropTemporaryDatabase(): void
    {
        if (!isset($this->admin) || $this->databaseName === '') {
            return;
        }

        $this->assertSafeDatabaseName($this->databaseName);
        $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
        $this->databaseName = '';
    }

    private function assertSafeDatabaseName(string $name): void
    {
        if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/', $name) !== 1) {
            throw new \RuntimeException('Refusing to use an unsafe temporary database name.');
        }
    }
}
