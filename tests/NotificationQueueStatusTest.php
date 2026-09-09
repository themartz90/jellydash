<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Health\NotificationQueueStatus;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use PHPUnit\Framework\TestCase;

final class NotificationQueueStatusTest extends TestCase
{
    private Database $database;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';

    protected function setUp(): void
    {
        $this->database = $this->isolatedDatabase();
        new PlayHistoryRepository($this->database);
        new SeerrRequestRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
    }

    public function testSnapshotCountsOnlyActiveRetryQueueStates(): void
    {
        $now = 1_788_192_000;
        $active = $this->localTime($now - 300);
        $retired = $this->localTime($now - 601);
        $this->insertPlay('pending', 1, null, null, 0, $active);
        $this->insertPlay('flight', 1, 'claim', $now - 299, 0, $active);
        $this->insertPlay('stalled', 2, 'old-claim', $now - 300, 0, $active);
        $this->insertPlay('untried', 0, null, null, 0, $active);
        $this->insertPlay('retired', 2, null, null, 0, $retired);
        $this->insertPlay('terminal', 2, null, null, 1, $active);
        $this->insertRequest(1, null, null, 0);
        $this->insertRequest(2, 'request-claim', $now - 301, 0);

        $this->assertSame(
            ['pending_retries' => 2, 'in_flight' => 1, 'stalled' => 2],
            (new NotificationQueueStatus($this->database))->snapshot(true, true, $now),
        );
        $this->assertSame(
            ['pending_retries' => 1, 'in_flight' => 1, 'stalled' => 1],
            (new NotificationQueueStatus($this->database))->snapshot(true, false, $now),
        );
        $this->assertSame(
            ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0],
            (new NotificationQueueStatus($this->database))->snapshot(false, false, $now),
        );
    }

    private function insertPlay(string $suffix, int $attempts, ?string $claim, ?int $claimedAt, int $notified, string $startedAt): void
    {
        $this->database->getDibi()->insert('play_history', [
            'session_key' => 'health-' . $suffix, 'item_id' => 'health-item-' . $suffix,
            'item_type' => 'Movie', 'play_method' => 'DirectPlay', 'watched_sec' => 0,
            'runtime_sec' => 0, 'started_at' => $startedAt, 'updated_at' => $startedAt,
            'notified' => $notified, 'notification_attempts' => $attempts,
            'notification_claim_token' => $claim, 'notification_claimed_at_epoch' => $claimedAt,
        ])->execute();
    }

    private function insertRequest(int $attempts, ?string $claim, ?int $claimedAt, int $notified): void
    {
        static $id = 9000;
        ++$id;
        $this->database->getDibi()->insert('seerr_requests', [
            'request_id' => $id, 'media_type' => 'movie', 'tmdb_id' => $id,
            'title' => 'Health fixture', 'requested_at' => '2026-09-01 12:00:00',
            'created_at' => '2026-09-01 12:00:00', 'notified' => $notified,
            'notification_attempts' => $attempts, 'notification_claim_token' => $claim,
            'notification_claimed_at_epoch' => $claimedAt,
        ])->execute();
    }

    private function isolatedDatabase(): Database
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            return Database::sqlite(':memory:');
        }
        $config = ['driver' => DATABASE_DRIVER_DIBI, 'host' => DATABASE_HOST, 'username' => DATABASE_USERNAME, 'password' => DATABASE_PASSWORD];
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }
        $this->admin = new \Dibi\Connection($config);
        $this->databaseName = 'jellydash_phpunit_health_queue_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
        $config['database'] = $this->databaseName;

        return new Database(new \Dibi\Connection($config));
    }

    private function localTime(int $epoch): string
    {
        return (new DateTimeImmutable('@' . $epoch))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }
}
