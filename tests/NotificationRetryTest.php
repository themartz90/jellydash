<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\RequestNotifier;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Notifications\ClaimedNotificationDelivery;
use Mk\Framework\Notifications\NotificationChannel;
use Mk\Framework\Notifications\NotificationDispatcher;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Push\WebPushSender;
use PHPUnit\Framework\TestCase;

final class NotificationRetryTest extends TestCase
{
    private Database $database;
    private SeerrRequestRepository $repository;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';

    protected function setUp(): void
    {
        $this->database = $this->isolatedDatabase();
        Container::reset();
        Container::set('db', $this->database);
        $this->repository = new SeerrRequestRepository($this->database);
        $this->insertRequest();
    }

    protected function tearDown(): void
    {
        Container::reset();
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^jellydash_phpunit_notification_[a-z0-9_]+$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
    }

    public function testTotalFailureRetriesWithBackoffAndStopsAfterThreeAttempts(): void
    {
        $first = $this->claimAt(1000);
        $this->repository->failNotificationClaim((int) $first['id'], (string) $first['notification_claim_token'], 1000);
        $this->assertState(0, 1, 1060);
        $this->assertSame([], $this->repository->claimUnnotified(1059));

        $second = $this->claimAt(1060);
        $this->repository->failNotificationClaim((int) $second['id'], (string) $second['notification_claim_token'], 1060);
        $this->assertState(0, 2, 1360);

        $third = $this->claimAt(1360);
        $this->repository->failNotificationClaim((int) $third['id'], (string) $third['notification_claim_token'], 1360);
        $this->assertState(1, 3, null);
        $this->assertSame([], $this->repository->claimUnnotified(2000));
    }

    public function testPartialSuccessAcknowledgesClaimWithoutRetry(): void
    {
        $claim = $this->claimAt(1000);
        $delivery = new ClaimedNotificationDelivery();
        $sent = $delivery->deliver(
            static fn (): int => 1,
            function () use ($claim): void {
                $this->repository->acknowledgeNotificationClaim((int) $claim['id'], (string) $claim['notification_claim_token']);
            },
            function () use ($claim): void {
                $this->repository->failNotificationClaim((int) $claim['id'], (string) $claim['notification_claim_token'], 1000);
            },
        );

        $this->assertTrue($sent);
        $this->assertState(1, 1, null);
        $this->assertSame([], $this->repository->claimUnnotified(2000));
    }

    public function testExpiredLeaseIsRecoveredButForeignTokenCannotCompleteIt(): void
    {
        $first = $this->claimAt(1000);
        $second = $this->claimAt(1301);
        $this->assertNotSame($first['notification_claim_token'], $second['notification_claim_token']);
        $this->assertSame(2, (int) $second['notification_attempts']);

        $this->repository->acknowledgeNotificationClaim((int) $first['id'], (string) $first['notification_claim_token']);
        $this->assertState(0, 2, null, (string) $second['notification_claim_token']);
    }

    public function testRequestNotifierUsesFailureResultToScheduleOneRetry(): void
    {
        $channel = new CountingFailureChannel();
        $dispatcher = new NotificationDispatcher(
            new WebPushSender(),
            new PushSubscriptionRepository($this->database),
            [$channel],
        );
        $notifier = new RequestNotifier($this->repository, $dispatcher);
        $before = time();

        $this->assertSame(0, $notifier->dispatch());
        $this->assertSame(1, $channel->calls);
        $row = $this->database->getDibi()->select('*')->from('seerr_requests')->fetch();
        $this->assertSame(0, (int) $row['notified']);
        $this->assertSame(1, (int) $row['notification_attempts']);
        $this->assertGreaterThanOrEqual($before + 60, (int) $row['notification_next_attempt_at_epoch']);

        $this->assertSame(0, $notifier->dispatch());
        $this->assertSame(1, $channel->calls);
    }

    public function testDelayedClaimCannotBypassBackoffScheduledByAnotherWorker(): void
    {
        $other = new SeerrRequestRepository($this->database);
        $interleaved = false;
        $listener = function (\Dibi\Event $event) use ($other, &$interleaved): void {
            if (!$interleaved && preg_match('/\bFROM\s+[`"\[]?seerr_requests\b/i', (string) $event->sql) === 1) {
                $interleaved = true;
                $claims = $other->claimUnnotified(1000);
                $this->assertCount(1, $claims);
                $other->failNotificationClaim(
                    (int) $claims[0]['id'],
                    (string) $claims[0]['notification_claim_token'],
                    1000,
                );
            }
        };
        $this->database->getDibi()->onEvent[] = $listener;
        try {
            $claims = $this->repository->claimUnnotified(1000);
        } finally {
            $this->database->getDibi()->onEvent = array_values(array_filter(
                $this->database->getDibi()->onEvent,
                static fn ($callback): bool => $callback !== $listener,
            ));
        }

        $this->assertTrue($interleaved);
        $this->assertSame([], $claims);
        $this->assertState(0, 1, 1060);
    }

    public function testPlaybackClaimCannotAttachToRestartedRowGeneration(): void
    {
        $history = new PlayHistoryRepository($this->database);
        $start = new \DateTimeImmutable('2026-09-08 12:00:00');
        $history->logActiveStreams([$this->stream(1800)], $start);
        $interleaved = false;
        $listener = function (\Dibi\Event $event) use ($history, $start, &$interleaved): void {
            if (!$interleaved && preg_match('/\bFROM\s+[`"\[]?play_history\b/i', (string) $event->sql) === 1) {
                $interleaved = true;
                $history->logActiveStreams([$this->stream(120)], $start->modify('+3 hours'));
            }
        };
        $this->database->getDibi()->onEvent[] = $listener;
        try {
            $claims = $history->claimUnnotifiedPlays([], 20000, $start->modify('+1 second'));
        } finally {
            $this->database->getDibi()->onEvent = array_values(array_filter(
                $this->database->getDibi()->onEvent,
                static fn ($callback): bool => $callback !== $listener,
            ));
        }

        $this->assertTrue($interleaved);
        $row = $this->database->getDibi()->select('started_at, notification_attempts, notification_claim_token')
            ->from('play_history')->fetch();
        $this->assertStringStartsWith('2026-09-08 15:00:00', (string) $row['started_at']);
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI) && $claims !== []) {
            // SQLite can defer reading the result until after the event. In
            // that case the selected payload already belongs to the new play.
            $this->assertCount(1, $claims);
            $this->assertSame('2026-09-08 15:00:00', (string) $claims[0]['started_at']);
            $this->assertSame(120, (int) $claims[0]['watched_sec']);
            $this->assertSame(1, (int) $row['notification_attempts']);
        } else {
            $this->assertSame([], $claims);
            $this->assertSame(0, (int) $row['notification_attempts']);
            $this->assertNull($row['notification_claim_token']);
        }
    }

    /** @return \Dibi\Row */
    private function claimAt(int $epoch): \Dibi\Row
    {
        $claims = $this->repository->claimUnnotified($epoch);
        $this->assertCount(1, $claims);

        return $claims[0];
    }

    private function assertState(int $notified, int $attempts, ?int $nextAttempt, ?string $token = null): void
    {
        $row = $this->database->getDibi()->select('*')->from('seerr_requests')->fetch();
        $this->assertSame($notified, (int) $row['notified']);
        $this->assertSame($attempts, (int) $row['notification_attempts']);
        $this->assertSame($nextAttempt, $row['notification_next_attempt_at_epoch'] === null ? null : (int) $row['notification_next_attempt_at_epoch']);
        $this->assertSame($token, $row['notification_claim_token']);
    }

    private function insertRequest(): void
    {
        $this->database->getDibi()->insert('seerr_requests', [
            'request_id' => 901,
            'media_type' => 'movie',
            'tmdb_id' => 902,
            'title' => 'Retry Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => '2026-09-08 12:00:00',
            'notified' => 0,
            'created_at' => '2026-09-08 12:00:00',
        ])->execute();
    }

    /** @return array<string, mixed> */
    private function stream(int $watchedSec): array
    {
        return [
            'id' => 'notification-retry-session',
            'itemId' => 'notification-retry-item',
            'itemType' => 'Movie',
            'itemName' => 'Retry Movie',
            'user' => 'Retry User',
            'playMethod' => 'DirectPlay',
            'watchedSec' => $watchedSec,
            'runtimeSec' => 3600,
        ];
    }

    private function isolatedDatabase(): Database
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            return Database::sqlite(':memory:');
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

        $this->admin = new \Dibi\Connection($config);
        $this->databaseName = 'jellydash_phpunit_notification_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
        $config['database'] = $this->databaseName;

        return new Database(new \Dibi\Connection($config));
    }
}

final class CountingFailureChannel implements NotificationChannel
{
    public int $calls = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(array $notification): bool
    {
        ++$this->calls;

        return false;
    }
}
