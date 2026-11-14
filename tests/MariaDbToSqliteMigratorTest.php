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
            'charset' => 'utf8mb4',
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
        $this->assertStringContainsString('movie_replacements: 3 row(s)', $result['output']);
        $this->assertStringContainsString('movie_identity_items: 3 row(s)', $result['output']);
        $this->assertStringContainsString('movie_identity_state: 2 row(s)', $result['output']);
        $this->assertFileExists($this->destinationPath);

        $destination = Database::sqlite($this->destinationPath);
        $sqlite = $destination->getDibi();
        $this->assertSame(101, (int) $sqlite->select('id')->from('users')->fetchSingle());
        $this->assertSame(7, (int) $sqlite->select('auth_generation')->from('users')->fetchSingle());
        $this->assertSame(7, (int) $sqlite->select('auth_generation')->from('auth_remember_tokens')->fetchSingle());
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
        $this->assertSame(7, (int) $sqlite->select('observation_version')->from('theme_item_classifications')->where('item_id = %s', 'a-theme-item')->fetchSingle());
        $this->assertSame('z-ordinary-item', (string) $classifications[1]['item_id']);
        $this->assertSame('ordinary', (string) $classifications[1]['classification']);
        $this->assertNull($classifications[1]['next_retry_epoch']);
        $this->assertSame(
            7,
            (int) $sqlite->select('revision')->from('theme_classification_state')
                ->where('server_key = %s', str_repeat('1', 64))->fetchSingle(),
        );
        $this->assertSame('Migration Movie', (string) $sqlite->select('title')->from('seerr_requests')->fetchSingle());
        $replacementRows = static fn (\Dibi\Connection $connection): array => array_map(
            static fn (\Dibi\Row $row): array => $row->toArray(),
            $connection->select('server_key, item_id, replacement_id')->from('movie_replacements')
                ->orderBy('server_key, item_id')->fetchAll(),
        );
        $this->assertSame($replacementRows($this->sourceConnection), $replacementRows($sqlite));
        foreach (['movie_identity_items', 'movie_identity_state'] as $table) {
            $identityRows = static fn (\Dibi\Connection $connection): array => array_map(
                static fn (\Dibi\Row $row): array => $row->toArray(),
                $connection->select('*')->from($table)->orderBy($table === 'movie_identity_items' ? 'server_key, item_id' : 'server_key')->fetchAll(),
            );
            $this->assertSame($identityRows($this->sourceConnection), $identityRows($sqlite));
        }
        $this->assertSame(1786449600, (int) $sqlite->select('requested_at_epoch')->from('seerr_requests')->fetchSingle());
        $metadata = $sqlite->select('metadata_pending, metadata_attempts, metadata_retry_at_epoch')->from('seerr_requests')->fetch();
        $this->assertSame(1, (int) $metadata['metadata_pending']);
        $this->assertSame(3, (int) $metadata['metadata_attempts']);
        $this->assertSame(1786450000, (int) $metadata['metadata_retry_at_epoch']);
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
        $connection = $sqlite->select('provider, endpoint, credential_envelope, config_revision')
            ->from('integration_connections')->where('id = %s', 'migration-download-client')->fetch();
        $this->assertNotFalse($connection);
        $this->assertSame('qbittorrent', (string) $connection['provider']);
        $this->assertSame('https://downloads.example.test', (string) $connection['endpoint']);
        $this->assertSame('{"encrypted":"credential"}', (string) $connection['credential_envelope']);
        $this->assertSame(4, (int) $connection['config_revision']);
        $this->assertSame('{"encrypted":"session"}', (string) $sqlite->select('session_envelope')
            ->from('download_connection_state')->fetchSingle());
        $completion = $sqlite->select('source_id, title, category, tags_json, status, completed_at, observed_at')
            ->from('download_completions')->fetch();
        $this->assertNotFalse($completion);
        $this->assertSame('CaseSensitiveHash', (string) $completion['source_id']);
        $this->assertSame('Migration Download', (string) $completion['title']);
        $this->assertSame(str_repeat('é', 129), (string) $completion['category']);
        $this->assertSame([str_repeat('界', 129)], json_decode((string) $completion['tags_json'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('failed', (string) $completion['status']);
        $this->assertSame(1786443600, (int) $completion['completed_at']);
        $this->assertSame(1786443610, (int) $completion['observed_at']);
        $this->assertSame(102, $destination->addAuthUser('after-migration', 'password-123', 'After Migration', 2));
        $sqlite->disconnect();

        foreach (['users', 'login_attempts', 'auth_remember_tokens', 'app_settings', 'play_history', 'theme_item_classifications', 'theme_classification_state', 'movie_replacements', 'movie_identity_items', 'movie_identity_state', 'push_subscriptions', 'seerr_requests', 'system_status', 'integration_connections', 'download_connection_state', 'download_completions'] as $table) {
            $expected = match ($table) {
                'system_status', 'movie_replacements', 'movie_identity_items' => 3,
                'theme_item_classifications', 'movie_identity_state' => 2,
                default => 1,
            };
            $this->assertSame($expected, (int) $this->sourceConnection->select('COUNT(*)')->from($table)->fetchSingle());
        }
    }

    public function testLegacyCompletionWithoutOutcomeMigratesAsCompleted(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->sourceConnection->query('ALTER TABLE `download_completions` DROP COLUMN `status`');
        $this->sourceConnection->insert('download_completions', [
            'connection_id' => 'legacy-client',
            'source_id_digest' => hash('sha256', 'legacy-item'),
            'source_id' => 'legacy-item',
            'title' => 'Legacy completion',
            'category' => '',
            'tags_json' => '[]',
            'size_bytes' => null,
            'completed_at' => 1_800_000_000,
            'observed_at' => 1_800_000_001,
            'last_seen_at' => 1_800_000_001,
        ])->execute();

        (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $destination = Database::sqlite($this->destinationPath);
        self::assertSame('completed', (string) $destination->getDibi()
            ->select('status')->from('download_completions')->fetchSingle());
        $destination->getDibi()->disconnect();
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

    public function testMigratesArtworkImagesAsBinaryAcrossSmallBatches(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $sourceKey = hash('sha256', 'artwork-scope-different-from-destination-config');
        $observedAt = 1_800_000_000_123_456;
        $observation = json_encode([
            'source' => $sourceKey,
            'server' => 'art-server',
            'series' => null,
            'tag' => 'poster-tag-4',
            'observedAt' => $observedAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->sourceConnection->insert('artwork_sources', [
            'source_key' => $sourceKey,
            'server_id' => 'art-server',
            'backfill_cursor' => 41,
            'legacy_confirmed' => 1,
            'anchored_at' => $observedAt - 10,
            'fresh_cursor' => 83,
            'next_attempt' => 1_800_000_120,
            'last_error' => 'request_failed',
            'external_preference' => 1,
            'external_revision' => 7,
            'external_discovery_after' => 1_800_000_180,
        ])->execute();
        $this->sourceConnection->insert('play_history', [
            'session_key' => 'artwork-observation-migration',
            'user_id' => 'migration-user',
            'user_name' => 'Artwork Migration',
            'item_id' => 'item-1',
            'item_type' => 'Movie',
            'item_name' => 'Binary artwork fixture',
            'library' => 'Movies',
            'play_method' => 'DirectPlay',
            'watched_sec' => 120,
            'watch_duration_sec' => 120,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-11 12:00:00',
            'updated_at' => '2026-08-11 12:02:00',
            'started_at_epoch' => 1_800_000_000,
            'updated_at_epoch' => 1_800_000_120,
            'is_finished' => 0,
            'notified' => 1,
            'artwork_observation' => $observation,
            'artwork_observed_at' => $observedAt,
        ])->execute();
        $playbackSessionRows = [
            [
                'source_key' => $sourceKey,
                'connection_key' => hash('sha256', 'migration-open-connection'),
                'owner_key' => hash('sha256', 'migration-open-owner'),
                'server_key' => hash('sha256', 'migration-open-server'),
                'journal_key' => str_repeat('a', 32),
                'capture_order' => 41,
                'observed_at' => 1_800_000_000_123_456,
                'is_open' => 1,
                'item_id' => 'movie-open',
                'stream_key' => 'jd-view-open-stream',
                'pending_item_id' => 'pending-theme-item',
                'pending_stream_key' => 'pending-theme-stream',
                'pending_owner_key' => hash('sha256', 'migration-pending-owner'),
                'pending_server_key' => hash('sha256', 'migration-pending-server'),
            ],
            [
                'source_key' => $sourceKey,
                'connection_key' => hash('sha256', 'migration-closed-connection'),
                'owner_key' => hash('sha256', 'migration-closed-owner'),
                'server_key' => hash('sha256', 'migration-closed-server'),
                'journal_key' => str_repeat('b', 32),
                'capture_order' => 42,
                'observed_at' => 1_800_000_060_654_321,
                'is_open' => 0,
                'item_id' => null,
                'stream_key' => 'jd-view-closed-stream',
                'pending_item_id' => null,
                'pending_stream_key' => null,
                'pending_owner_key' => null,
                'pending_server_key' => null,
            ],
        ];
        foreach ($playbackSessionRows as $playbackSessionRow) {
            $this->sourceConnection->insert('history_playback_sessions', $playbackSessionRow)->execute();
        }
        $captureReceiptRows = [
            ['source_key' => $sourceKey, 'receipt_id' => str_repeat('c', 32), 'observed_at' => 1_800_000_000_123_456],
            ['source_key' => $sourceKey, 'receipt_id' => str_repeat('d', 32), 'observed_at' => 1_800_000_060_654_321],
        ];
        foreach ($captureReceiptRows as $captureReceiptRow) {
            $this->sourceConnection->insert('history_capture_receipts', $captureReceiptRow)->execute();
        }
        $this->sourceConnection->insert('artwork_items', [
            'source_key' => $sourceKey,
            'item_id' => 'item-1',
            'item_type' => 'Movie',
            'title' => 'Binary artwork fixture',
            'series_name' => '',
            'poster_id' => 'poster-1',
            'observed_at' => 1_800_000_000,
            'evidence' => 'item',
            'status' => 'ready',
            'next_attempt' => 0,
        ])->execute();
        for ($row = 1; $row <= 11; ++$row) {
            $this->sourceConnection->insert('artwork_posters', [
                'source_key' => $sourceKey,
                'poster_id' => 'poster-' . $row,
                'kind' => 'Primary',
                'requested_tag' => null,
                'observed_at' => 1_800_000_000,
                'revision' => 1,
                'attempts' => 0,
                'next_attempt' => 0,
                'last_checked' => 0,
                'suppressed' => 0,
                'last_error' => null,
                'provider_tmdb_id' => $row === 1 ? '202' : null,
                'provider_title' => $row === 1 ? 'Original provider title' : '',
                'provider_year' => $row === 1 ? 2001 : null,
                'provider_conflict' => $row === 1 ? 1 : 0,
                'presence' => $row === 1 ? 0 : -1,
                'presence_checked' => $row === 1 ? 1_800_000_050 : 0,
            ])->execute();
        }

        $bodies = [];
        for ($row = 1; $row <= 11; ++$row) {
            $body = match ($row) {
                1 => "\0\xFF\xC3\x28artwork",
                2 => "\x89PNG\r\n\x1A\n\0\x80",
                default => "image-{$row}\0" . chr(255 - $row) . random_bytes(8),
            };
            $bodies[$row] = $body;
            $this->sourceConnection->query(
                'INSERT INTO `artwork_images` (`source_key`, `poster_id`, `body`, `content_type`, `tag`, `body_size`, `sha256`, `saved_at`) VALUES (%s, %s, %bin, %s, %s, %i, %s, %i)',
                $sourceKey,
                'poster-' . $row,
                $body,
                'image/jpeg',
                null,
                strlen($body),
                hash('sha256', $body),
                1_800_000_000 + $row,
            );
        }

        $externalBodies = [];
        for ($row = 1; $row <= 11; ++$row) {
            $assetKey = hash('sha256', 'external-asset-' . $row);
            $body = match ($row) {
                1 => "\0\xFF\xC3\x28external-artwork",
                2 => "\x89PNG\r\n\x1A\n\0\x80",
                default => "external-{$row}\0" . chr(255 - $row) . random_bytes(8),
            };
            $externalBodies[$row] = $body;
            $this->sourceConnection->insert('artwork_external_assets', [
                'source_key' => $sourceKey,
                'asset_key' => $assetKey,
                'kind' => 'Series',
                'provider_id' => 'series-' . $row,
                'poster_path' => '/w500/poster-' . $row . '.jpg',
                'title' => 'External Show ' . $row,
                'year' => 2020 + $row,
                'requested_language' => 'en-US',
                'image_language' => 'en',
                'body_size' => strlen($body),
                'content_type' => 'image/jpeg',
                'sha256' => hash('sha256', $body),
                'saved_at' => 1_800_000_100 + $row,
            ])->execute();
            $this->sourceConnection->query(
                'INSERT INTO `artwork_external_bodies` (`source_key`, `asset_key`, `body`) VALUES (%s, %s, %bin)',
                $sourceKey,
                $assetKey,
                $body,
            );
        }
        $this->sourceConnection->insert('artwork_external_bindings', [
            'source_key' => $sourceKey,
            'item_id' => 'item-1',
            'identity_key' => hash('sha256', 'Movie:item-1'),
            'kind' => 'Movie',
            'verified_id' => 'verified-movie-1',
            'identity_conflict' => 0,
            'tmdb_id' => '101',
            'poster_path' => '/w500/poster-1.jpg',
            'selected_title' => 'Manually selected feature',
            'selected_year' => 1998,
            'asset_key' => hash('sha256', 'external-asset-1'),
            'presence' => 0,
            'state' => 'saved',
            'origin' => 'automatic',
            'suppressed' => 0,
            'suppressed_by' => 'placeholder',
            'snoozed_until' => 0,
            'revision' => 3,
            'next_attempt' => 0,
            'attempts' => 1,
            'last_error' => null,
            'discovery_after' => 1_800_000_240,
            'expires_at' => 1_800_000_300,
        ])->execute();
        $discoveryCandidates = [[
            'id' => '1429',
            'kind' => 'Series',
            'title' => '進撃の巨人',
            'year' => 2013,
            'path' => 'attack-on-titan.jpg',
            'expires_at' => 1_815_552_000,
        ]];
        $discoveryJson = json_encode($discoveryCandidates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->sourceConnection->insert('artwork_external_searches', [
            'source_key' => $sourceKey,
            'query_key' => hash('sha256', 'native-discovery-query'),
            'server_id' => 'art-server',
            'config_revision' => 7,
            'kind' => 'Series',
            'query_title' => '進撃の巨人',
            'acquired_at' => 1_800_000_120,
            'expires_at' => 1_800_604_920,
            'candidates_json' => $discoveryJson,
            'last_error' => null,
            'revision' => 2,
        ])->execute();

        $sourceBefore = $this->sourceConnection->select('source_key, poster_id, body, content_type, tag, body_size, sha256, saved_at')
            ->from('artwork_images')->orderBy('source_key, poster_id')->fetchAll();
        $externalBodiesBefore = $this->sourceConnection->select('source_key, asset_key, body')
            ->from('artwork_external_bodies')->orderBy('source_key, asset_key')->fetchAll();
        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $this->assertSame(1, $counts['play_history']);
        $this->assertSame(2, $counts['history_playback_sessions']);
        $this->assertSame(2, $counts['history_capture_receipts']);
        $this->assertSame(11, $counts['artwork_images']);
        $this->assertSame(11, $counts['artwork_posters']);
        $this->assertSame(1, $counts['artwork_external_bindings']);
        $this->assertSame(1, $counts['artwork_external_searches']);
        $this->assertSame(11, $counts['artwork_external_assets']);
        $this->assertSame(11, $counts['artwork_external_bodies']);

        $destination = Database::sqlite($this->destinationPath);
        $sqlite = $destination->getDibi();
        $rows = $sqlite->select('source_key, poster_id, body, content_type, tag, body_size, sha256, saved_at')
            ->from('artwork_images')->orderBy('source_key, poster_id')->fetchAll();
        $this->assertCount(11, $rows);
        $this->assertSame(
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $sourceBefore),
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $rows),
        );
        foreach ($rows as $row) {
            $posterNumber = (int) substr((string) $row['poster_id'], strlen('poster-'));
            $body = $bodies[$posterNumber];
            $this->assertSame($body, (string) $row['body']);
            $this->assertSame(strlen($body), (int) $row['body_size']);
            $this->assertSame(hash('sha256', $body), (string) $row['sha256']);
            $this->assertSame($sourceKey, (string) $row['source_key']);
            $this->assertSame('image/jpeg', (string) $row['content_type']);
        }
        $externalRows = $sqlite->query(
            'SELECT b.source_key, b.asset_key, b.body, a.provider_id, a.body_size, a.sha256 '
            . 'FROM artwork_external_bodies b JOIN artwork_external_assets a '
            . 'ON a.source_key = b.source_key AND a.asset_key = b.asset_key '
            . 'ORDER BY b.source_key, b.asset_key',
        )->fetchAll();
        $this->assertCount(11, $externalRows);
        $this->assertSame(
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $externalBodiesBefore),
            array_map(static fn (\Dibi\Row $row): array => [
                'source_key' => $row['source_key'],
                'asset_key' => $row['asset_key'],
                'body' => $row['body'],
            ], $externalRows),
        );
        foreach ($externalRows as $row) {
            $assetNumber = (int) substr((string) $row['provider_id'], strlen('series-'));
            $body = $externalBodies[$assetNumber];
            $this->assertSame($body, (string) $row['body']);
            $this->assertSame(strlen($body), (int) $row['body_size']);
            $this->assertSame(hash('sha256', $body), (string) $row['sha256']);
        }
        $this->assertSame('saved', (string) $sqlite->select('state')->from('artwork_external_bindings')
            ->where('source_key = %s AND item_id = %s', $sourceKey, 'item-1')->fetchSingle());
        $this->assertSame(1, (int) $sqlite->select('COUNT(*)')->from('artwork_external_assets')
            ->where('source_key = %s AND asset_key = %s', $sourceKey, hash('sha256', 'external-asset-1'))->fetchSingle());
        $externalBinding = $sqlite->select('selected_title, selected_year, suppressed_by, discovery_after, expires_at')
            ->from('artwork_external_bindings')->where('source_key = %s AND item_id = %s', $sourceKey, 'item-1')->fetch();
        $this->assertNotFalse($externalBinding);
        $this->assertSame('Manually selected feature', (string) $externalBinding['selected_title']);
        $this->assertSame(1998, (int) $externalBinding['selected_year']);
        $this->assertSame('placeholder', (string) $externalBinding['suppressed_by']);
        $this->assertSame(1_800_000_240, (int) $externalBinding['discovery_after']);
        $this->assertSame(1_800_000_300, (int) $externalBinding['expires_at']);
        $sourceDiscovery = $this->sourceConnection->select('*')->from('artwork_external_searches')
            ->where('source_key = %s', $sourceKey)->fetch();
        $destinationDiscovery = $sqlite->select('*')->from('artwork_external_searches')
            ->where('source_key = %s', $sourceKey)->fetch();
        $this->assertNotFalse($sourceDiscovery);
        $this->assertNotFalse($destinationDiscovery);
        $this->assertSame($sourceDiscovery->toArray(), $destinationDiscovery->toArray());
        $this->assertSame('進撃の巨人', (string) $destinationDiscovery['query_title']);
        $this->assertSame($discoveryJson, (string) $destinationDiscovery['candidates_json']);
        $this->assertSame(1_800_000_180, (int) $sqlite->select('external_discovery_after')->from('artwork_sources')
            ->where('source_key = %s', $sourceKey)->fetchSingle());
        $migratedPlaybackSessionRows = $sqlite->select('*')->from('history_playback_sessions')
            ->where('source_key = %s', $sourceKey)->orderBy('source_key, connection_key')->fetchAll();
        $sourcePlaybackSessionRows = $this->sourceConnection->select('*')->from('history_playback_sessions')
            ->where('source_key = %s', $sourceKey)->orderBy('source_key, connection_key')->fetchAll();
        $this->assertSame(
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $sourcePlaybackSessionRows),
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $migratedPlaybackSessionRows),
        );
        $openPlaybackSession = $sqlite->select('*')
            ->from('history_playback_sessions')->where('source_key = %s AND connection_key = %s', $sourceKey, hash('sha256', 'migration-open-connection'))->fetch();
        $closedPlaybackSession = $sqlite->select('*')
            ->from('history_playback_sessions')->where('source_key = %s AND connection_key = %s', $sourceKey, hash('sha256', 'migration-closed-connection'))->fetch();
        $this->assertNotFalse($openPlaybackSession);
        $this->assertNotFalse($closedPlaybackSession);
        $this->assertSame(1, (int) $openPlaybackSession['is_open']);
        $this->assertSame(41, (int) $openPlaybackSession['capture_order']);
        $this->assertSame(1_800_000_000_123_456, (int) $openPlaybackSession['observed_at']);
        $this->assertSame('movie-open', (string) $openPlaybackSession['item_id']);
        $this->assertSame('pending-theme-item', (string) $openPlaybackSession['pending_item_id']);
        $this->assertSame('pending-theme-stream', (string) $openPlaybackSession['pending_stream_key']);
        $this->assertSame(hash('sha256', 'migration-pending-owner'), (string) $openPlaybackSession['pending_owner_key']);
        $this->assertSame(hash('sha256', 'migration-pending-server'), (string) $openPlaybackSession['pending_server_key']);
        $this->assertSame(0, (int) $closedPlaybackSession['is_open']);
        $this->assertSame(42, (int) $closedPlaybackSession['capture_order']);
        $this->assertSame(1_800_000_060_654_321, (int) $closedPlaybackSession['observed_at']);
        $this->assertNull($closedPlaybackSession['item_id']);
        $this->assertNull($closedPlaybackSession['pending_item_id']);
        $this->assertNull($closedPlaybackSession['pending_stream_key']);
        $this->assertNull($closedPlaybackSession['pending_owner_key']);
        $this->assertNull($closedPlaybackSession['pending_server_key']);
        $migratedCaptureReceiptRows = $sqlite->select('*')->from('history_capture_receipts')
            ->where('source_key = %s', $sourceKey)->orderBy('source_key, receipt_id')->fetchAll();
        $sourceCaptureReceiptRows = $this->sourceConnection->select('*')->from('history_capture_receipts')
            ->where('source_key = %s', $sourceKey)->orderBy('source_key, receipt_id')->fetchAll();
        $this->assertSame(
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $sourceCaptureReceiptRows),
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $migratedCaptureReceiptRows),
        );
        $this->assertSame(str_repeat('c', 32), (string) $migratedCaptureReceiptRows[0]['receipt_id']);
        $this->assertSame(1_800_000_000_123_456, (int) $migratedCaptureReceiptRows[0]['observed_at']);
        $this->assertSame(str_repeat('d', 32), (string) $migratedCaptureReceiptRows[1]['receipt_id']);
        $this->assertSame(1_800_000_060_654_321, (int) $migratedCaptureReceiptRows[1]['observed_at']);
        $migratedHistoricalRow = $sqlite->select('session_key, item_id, item_type, watch_duration_sec, artwork_observed_at')
            ->from('play_history')->where('session_key = %s', 'artwork-observation-migration')->fetch();
        $this->assertNotFalse($migratedHistoricalRow);
        $this->assertSame('item-1', (string) $migratedHistoricalRow['item_id']);
        $this->assertSame('Movie', (string) $migratedHistoricalRow['item_type']);
        $this->assertSame(120, (int) $migratedHistoricalRow['watch_duration_sec']);
        $this->assertSame($observedAt, (int) $migratedHistoricalRow['artwork_observed_at']);
        $providerMetadata = $sqlite->select('provider_tmdb_id, provider_title, provider_year, provider_conflict, presence, presence_checked')
            ->from('artwork_posters')->where('source_key = %s AND poster_id = %s', $sourceKey, 'poster-1')->fetch();
        $this->assertNotFalse($providerMetadata);
        $this->assertSame('202', (string) $providerMetadata['provider_tmdb_id']);
        $this->assertSame('Original provider title', (string) $providerMetadata['provider_title']);
        $this->assertSame(2001, (int) $providerMetadata['provider_year']);
        $this->assertSame(1, (int) $providerMetadata['provider_conflict']);
        $this->assertSame(0, (int) $providerMetadata['presence']);
        $this->assertSame(1_800_000_050, (int) $providerMetadata['presence_checked']);
        $sourceAfter = $this->sourceConnection->select('source_key, poster_id, body, content_type, tag, body_size, sha256, saved_at')
            ->from('artwork_images')->orderBy('source_key, poster_id')->fetchAll();
        $this->assertSame(
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $sourceBefore),
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $sourceAfter),
        );
        $externalBodiesAfter = $this->sourceConnection->select('source_key, asset_key, body')
            ->from('artwork_external_bodies')->orderBy('source_key, asset_key')->fetchAll();
        $this->assertSame(
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $externalBodiesBefore),
            array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $externalBodiesAfter),
        );
        $this->assertSame(1, (int) $sqlite->select('COUNT(*)')->from('artwork_sources')->where('source_key = %s', $sourceKey)->fetchSingle());
        $sourceState = $this->sourceConnection->select('*')->from('artwork_sources')
            ->where('source_key = %s', $sourceKey)->fetch();
        $destinationState = $sqlite->select('*')->from('artwork_sources')
            ->where('source_key = %s', $sourceKey)->fetch();
        $this->assertNotFalse($sourceState);
        $this->assertNotFalse($destinationState);
        $this->assertSame($sourceState->toArray(), $destinationState->toArray());
        $this->assertSame(1, (int) $destinationState['external_preference']);
        $this->assertSame(7, (int) $destinationState['external_revision']);
        $sourceObservation = $this->sourceConnection->select('artwork_observation, artwork_observed_at')
            ->from('play_history')->where('session_key = %s', 'artwork-observation-migration')->fetch();
        $destinationObservation = $sqlite->select('artwork_observation, artwork_observed_at')
            ->from('play_history')->where('session_key = %s', 'artwork-observation-migration')->fetch();
        $this->assertNotFalse($sourceObservation);
        $this->assertNotFalse($destinationObservation);
        $this->assertSame((string) $sourceObservation['artwork_observation'], (string) $destinationObservation['artwork_observation']);
        $this->assertSame((int) $sourceObservation['artwork_observed_at'], (int) $destinationObservation['artwork_observed_at']);
        $this->assertSame($observedAt, (int) $destinationObservation['artwork_observed_at']);
        $this->assertSame('poster-1', (string) $sqlite->select('poster_id')->from('artwork_items')->where('item_id = %s', 'item-1')->fetchSingle());
        $this->assertSame('Primary', (string) $sqlite->select('kind')->from('artwork_posters')->where('poster_id = %s', 'poster-1')->fetchSingle());
        $sqlite->disconnect();
    }

    public function testSchemaOnlyArtworkInitializationDoesNotCreatePhantomSourceDuringMigration(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->assertSame(0, (int) $this->sourceConnection->select('COUNT(*)')->from('artwork_sources')->fetchSingle());

        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $this->assertSame(0, $counts['artwork_sources']);

        $destination = Database::sqlite($this->destinationPath);
        $sqlite = $destination->getDibi();
        $this->assertSame(0, (int) $sqlite->select('COUNT(*)')->from('artwork_sources')->fetchSingle());
        $sqlite->disconnect();
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
        $this->assertSame(0, $counts['movie_replacements']);
        $this->assertSame(0, $counts['movie_identity_items']);
        $this->assertSame(0, $counts['movie_identity_state']);

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
            'auth_generation' => 7,
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
            'auth_generation' => 7,
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
                'observation_version' => 7,
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
        foreach ([
            ['server_key' => str_repeat('1', 64), 'item_id' => 'Old-Movie', 'replacement_id' => 'Current-Movie'],
            ['server_key' => str_repeat('1', 64), 'item_id' => 'Old-Movie ', 'replacement_id' => 'Current-Movie'],
            ['server_key' => str_repeat('2', 64), 'item_id' => '12345', 'replacement_id' => 'a-B'],
        ] as $replacement) {
            $this->sourceConnection->insert('movie_replacements', $replacement)->execute();
        }
        foreach ([
            ['server_key' => str_repeat('1', 64), 'item_id' => 'Old-Movie', 'data_json' => '{"title":"Příběh 🪼","year":2026,"providers":{"Tmdb":"123"},"seenScan":"scan-A"}'],
            ['server_key' => str_repeat('1', 64), 'item_id' => 'Old-Movie ', 'data_json' => '{"title":"Separate ID","providers":{"IMDb":"tt123"}}'],
            ['server_key' => str_repeat('2', 64), 'item_id' => '12345', 'data_json' => '{"remoteServerId":"Case-Sensitive","providers":{}}'],
        ] as $identity) {
            $this->sourceConnection->insert('movie_identity_items', $identity)->execute();
        }
        foreach ([
            ['server_key' => str_repeat('1', 64), 'data_json' => '{"scan":"scan-A","offset":100,"complete":false}', 'lease_token' => str_repeat('a', 64), 'lease_until_epoch' => 1786443500],
            ['server_key' => str_repeat('2', 64), 'data_json' => '{"scan":"complete","remoteServerId":"Case-Sensitive"}', 'lease_token' => null, 'lease_until_epoch' => 0],
        ] as $identityState) {
            $this->sourceConnection->insert('movie_identity_state', $identityState)->execute();
        }
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
            'requested_at_epoch' => 1786449600,
            'metadata_pending' => 1,
            'metadata_attempts' => 3,
            'metadata_retry_at_epoch' => 1786450000,
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
        $this->sourceConnection->insert('integration_connections', [
            'id' => 'migration-download-client',
            'provider' => 'qbittorrent',
            'display_name' => 'Migration client',
            'endpoint' => 'https://downloads.example.test',
            'endpoint_hash' => hash('sha256', "qbittorrent\0https://downloads.example.test"),
            'username' => 'migration-user',
            'verify_tls' => 1,
            'enabled' => 1,
            'filter_mode' => 'selected',
            'categories_json' => '["movies"]',
            'tags_json' => '["migration"]',
            'credential_envelope' => '{"encrypted":"credential"}',
            'credential_source' => 'stored',
            'config_revision' => 4,
            'created_at_epoch' => 1786442400,
            'updated_at_epoch' => 1786443500,
        ])->execute();
        $this->sourceConnection->insert('download_connection_state', [
            'connection_id' => 'migration-download-client',
            'config_revision' => 4,
            'lease_token' => str_repeat('7', 64),
            'lease_expires_at' => 1786443700,
            'next_due_at' => 1786443700,
            'last_attempt_at' => 1786443500,
            'last_success_at' => 1786443501,
            'failure_code' => null,
            'failure_count' => 0,
            'cursor_json' => '{"offset":100}',
            'snapshot_json' => '{"items":[],"speed":0,"complete":true}',
            'snapshot_at' => 1786443501,
            'session_envelope' => '{"encrypted":"session"}',
        ])->execute();
        $sourceId = 'CaseSensitiveHash';
        $this->sourceConnection->insert('download_completions', [
            'connection_id' => 'migration-download-client',
            'source_id_digest' => hash('sha256', $sourceId),
            'source_id' => $sourceId,
            'title' => 'Migration Download',
            'category' => str_repeat('é', 129),
            'tags_json' => json_encode([str_repeat('界', 129)], JSON_THROW_ON_ERROR),
            'size_bytes' => 9876543210,
            'status' => 'failed',
            'completed_at' => 1786443600,
            'observed_at' => 1786443610,
            'last_seen_at' => 1786443620,
        ])->execute();
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
