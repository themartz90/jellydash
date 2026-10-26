<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Health\WorkerMonitor;
use Mk\Framework\Health\WorkerStatusRepository;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\RequestNotifier;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Notifications\ClaimedNotificationDelivery;
use Mk\Framework\Notifications\GotifyChannel;
use Mk\Framework\Notifications\NotificationChannel;
use Mk\Framework\Notifications\NotificationDispatcher;
use Mk\Framework\Notifications\NtfyChannel;
use Mk\Framework\Push\NotificationLibraryExclusions;
use Mk\Framework\Push\PlaybackNotifier;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Push\WebPushSender;
use Mk\Framework\Push\WebPushTransport;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

    public function testOldMirroredRequestIsRetiredWithoutAnAlertWhenNotificationsResume(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone(Config::timezone()));
        $this->database->getDibi()->update('seerr_requests', [
            'requested_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            'requested_at_epoch' => $now->getTimestamp() - 86400,
        ])->where('request_id = %i', 901)->execute();
        $channel = new CountingSuccessChannel();
        $notifier = new RequestNotifier($this->repository, new NotificationDispatcher(
            new WebPushSender(),
            new PushSubscriptionRepository($this->database),
            [$channel],
        ));

        self::assertSame(0, $notifier->dispatch());
        self::assertSame(0, $channel->calls);
        $old = $this->database->getDibi()->select('notified, notification_attempts')
            ->from('seerr_requests')->where('request_id = %i', 901)->fetch();
        self::assertSame(1, (int) $old['notified']);
        self::assertSame(0, (int) $old['notification_attempts']);

        $this->database->getDibi()->insert('seerr_requests', [
            'request_id' => 902,
            'media_type' => 'movie',
            'tmdb_id' => 903,
            'title' => 'New request',
            'request_status' => 1,
            'media_status' => 2,
            'requested_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
            'requested_at_epoch' => $now->getTimestamp() - 60,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'notified' => 0,
        ])->execute();

        self::assertSame(1, $notifier->dispatch());
        self::assertSame(1, $channel->calls);
        self::assertSame(1, (int) $this->database->getDibi()->select('notified')
            ->from('seerr_requests')->where('request_id = %i', 902)->fetchSingle());
    }

    public function testCurrentDeviceConfirmationUsesOnlyTheSuppliedWebPushSubscription(): void
    {
        $transport = new CurrentDeviceRecordingTransport();
        $channel = new CountingFailureChannel();
        $dispatcher = new NotificationDispatcher(
            new WebPushSender($transport, 'public-key', 'private-key'),
            new PushSubscriptionRepository($this->database),
            [$channel],
        );
        $subscription = [
            'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/current-confirmation',
            'p256dh' => rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '='),
            'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
        ];

        $report = $dispatcher->testCurrentWebPush($subscription, ['title' => 'Current device']);

        $this->assertSame(1, $report['sent']);
        $this->assertSame(0, $channel->calls);
        $this->assertSame([$subscription], $transport->subscriptions);
    }

    public function testPushDeliveryRecordsDeviceOutcomesAndReclaimsUnusableRows(): void
    {
        $previous = getenv('AUTH_ENABLED');
        putenv('AUTH_ENABLED=false');
        try {
            $subscriptions = new PushSubscriptionRepository($this->database);
            $good = 'https://updates.push.services.mozilla.com/wpush/v2/good-delivery';
            $failed = 'https://updates.push.services.mozilla.com/wpush/v2/failed-delivery';
            $expired = 'https://updates.push.services.mozilla.com/wpush/v2/expired-delivery';
            $invalid = 'https://updates.push.services.mozilla.com/wpush/v2/invalid-delivery';
            $key = rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '=');
            $secret = rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '=');
            foreach ([$good, $failed, $expired, $invalid] as $endpoint) {
                $subscriptions->save($endpoint, $key, $secret, null);
            }
            $this->database->getDibi()->update('push_subscriptions', ['auth' => 'invalid'])
                ->where('endpoint_hash = %s', hash('sha256', $invalid))->execute();
            $transport = new OutcomeWebPushTransport([
                ['endpoint' => $good, 'success' => true, 'expired' => false],
                ['endpoint' => $failed, 'success' => false, 'expired' => false],
                ['endpoint' => $expired, 'success' => false, 'expired' => true],
            ]);
            $dispatcher = new NotificationDispatcher(
                new WebPushSender($transport, 'public-key', 'private-key'),
                $subscriptions,
                [],
            );

            self::assertSame(1, $dispatcher->send(['title' => 'Fixture']));
            self::assertSame(2, $subscriptions->count());
            $goodRow = $this->database->getDibi()->select('last_success_at, failure_count')
                ->from('push_subscriptions')->where('endpoint_hash = %s', hash('sha256', $good))->fetch();
            $failedRow = $this->database->getDibi()->select('last_success_at, failure_count')
                ->from('push_subscriptions')->where('endpoint_hash = %s', hash('sha256', $failed))->fetch();
            self::assertNotFalse($goodRow);
            self::assertNotNull($goodRow['last_success_at']);
            self::assertSame(0, (int) $goodRow['failure_count']);
            self::assertNotFalse($failedRow);
            self::assertSame(1, (int) $failedRow['failure_count']);

            $transport->reports = [['endpoint' => $failed, 'success' => true, 'expired' => false]];
            self::assertSame(1, $dispatcher->send(['title' => 'Retry']));
            $recovered = $this->database->getDibi()->select('last_success_at, failure_count')
                ->from('push_subscriptions')->where('endpoint_hash = %s', hash('sha256', $failed))->fetch();
            self::assertNotFalse($recovered);
            self::assertNotNull($recovered['last_success_at']);
            self::assertSame(0, (int) $recovered['failure_count']);
        } finally {
            putenv($previous === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previous);
        }
    }

    public function testWebPushDatabaseFailureDoesNotSkipOtherChannels(): void
    {
        $subscriptions = new PushSubscriptionRepository($this->database);
        $this->database->getDibi()->query('DROP TABLE push_subscriptions');
        $logs = new TestHandler();
        Container::set('logger', new Logger('test', [$logs]));
        $channel = new CountingSuccessChannel();
        $dispatcher = new NotificationDispatcher(
            new WebPushSender(new CurrentDeviceRecordingTransport(), 'public-key', 'private-key'),
            $subscriptions,
            [$channel],
        );

        self::assertSame(1, $dispatcher->send(['title' => 'Test']));
        self::assertSame(1, $channel->calls);
        self::assertTrue($logs->hasErrorRecords());
    }

    public function testSelfHostedRequestDeliveryPreservesAggregateRetryContract(): void
    {
        $values = ['NTFY_URL' => 'http://ntfy.invalid', 'NTFY_TOPIC' => 'test', 'NTFY_TOKEN' => '', 'GOTIFY_URL' => 'http://gotify.invalid', 'GOTIFY_APP_TOKEN' => 'test-token'];
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $accepted = false;
            $attempts = [];
            $ntfy = new NtfyChannel(static function () use (&$attempts): array {
                $attempts[] = 'ntfy';
                return ['status' => 503, 'body' => '{}'];
            });
            $gotify = new GotifyChannel(static function ($url, $payload) use (&$attempts, &$accepted): array {
                $attempts[] = 'gotify';
                self::assertNotEmpty($payload['title']);
                self::assertNotEmpty($payload['message']);
                return $accepted ? ['status' => 200, 'body' => '{"id":1}'] : ['status' => 503, 'body' => '{}'];
            });
            $notifier = new RequestNotifier($this->repository, new NotificationDispatcher(
                new WebPushSender(),
                new PushSubscriptionRepository($this->database),
                [$ntfy, $gotify],
            ));
            self::assertSame(0, $notifier->dispatch());
            self::assertSame(['ntfy', 'gotify'], $attempts);
            $row = $this->database->getDibi()->select('*')->from('seerr_requests')->fetch();
            self::assertSame(0, (int) $row['notified']);
            self::assertSame(1, (int) $row['notification_attempts']);
            self::assertGreaterThan(time(), (int) $row['notification_next_attempt_at_epoch']);
            $this->database->getDibi()->update('seerr_requests', ['notification_next_attempt_at_epoch' => 0])->execute();
            $accepted = true;
            self::assertSame(1, $notifier->dispatch());
            $row = $this->database->getDibi()->select('*')->from('seerr_requests')->fetch();
            self::assertSame(1, (int) $row['notified']);
            self::assertSame(2, (int) $row['notification_attempts']);
            self::assertSame(0, $notifier->dispatch());
            self::assertSame(['ntfy', 'gotify', 'ntfy', 'gotify'], $attempts);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    public function testDeliveryHealthPersistsAcrossIdleAndRecoversAfterSuccess(): void
    {
        $statuses = new WorkerStatusRepository($this->database);
        $monitor = new WorkerMonitor($statuses);
        $failure = new CountingFailureChannel();
        $failedNotifier = new RequestNotifier(
            $this->repository,
            new NotificationDispatcher(new WebPushSender(), new PushSubscriptionRepository($this->database), [$failure]),
            null,
            $monitor,
        );

        $this->assertSame(0, $failedNotifier->dispatch());
        $this->assertSame('failed', $statuses->all()['request_delivery']['status']);
        $this->assertSame('delivery_failed', $statuses->all()['request_delivery']['error_code']);
        $this->assertSame(0, $failedNotifier->dispatch());
        $this->assertSame('failed', $statuses->all()['request_delivery']['status']);

        $this->database->getDibi()->update('seerr_requests', ['notification_next_attempt_at_epoch' => null])->execute();
        $success = new CountingSuccessChannel();
        $recoveredNotifier = new RequestNotifier(
            $this->repository,
            new NotificationDispatcher(new WebPushSender(), new PushSubscriptionRepository($this->database), [$success]),
            null,
            $monitor,
        );
        $this->assertSame(1, $recoveredNotifier->dispatch());
        $this->assertSame('success', $statuses->all()['request_delivery']['status']);
        $this->assertNull($statuses->all()['request_delivery']['error_code']);
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

    public function testPlaybackClaimsRemainBoundToTheirOwnPlayWhenAReplayStarts(): void
    {
        $history = new PlayHistoryRepository($this->database);
        $start = new \DateTimeImmutable('2026-09-08 12:00:00');
        $history->logActiveStreams([$this->stream(3500)], $start);
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
        $rows = $this->database->getDibi()->select('*')->from('play_history')->orderBy('started_at')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertStringStartsWith('2026-09-08 12:00:00', (string) $rows[0]['started_at']);
        $this->assertSame(3500, (int) $rows[0]['watched_sec']);
        $this->assertStringStartsWith('2026-09-08 15:00:00', (string) $rows[1]['started_at']);
        $this->assertSame(120, (int) $rows[1]['watched_sec']);
        $this->assertNotSame((int) $rows[0]['id'], (int) $rows[1]['id']);
        $this->assertNotEmpty($claims);
        foreach ($claims as $claim) {
            $stored = $this->database->getDibi()->select('*')->from('play_history')->where('id = %i', (int) $claim['id'])->fetch();
            $this->assertSame((string) $claim['started_at'], (string) $stored['started_at']);
            $this->assertSame((int) $claim['watched_sec'], (int) $stored['watched_sec']);
            $this->assertSame($claim['notification_claim_token'], $stored['notification_claim_token']);
        }
    }

    public function testPlaybackNotifierUsesSavedLibraryExclusionsWithoutHidingPlays(): void
    {
        $history = new PlayHistoryRepository($this->database);
        $now = (new DateTimeImmutable('now', new DateTimeZone(Config::timezone())))->format('Y-m-d H:i:s');
        foreach (['Music, Podcasts', 'Movies'] as $library) {
            $this->database->getDibi()->insert('play_history', [
                'session_key' => 'notification-library-' . $library,
                'item_id' => 'notification-item-' . $library,
                'item_type' => $library === 'Music, Podcasts' ? 'Audio' : 'Movie',
                'item_name' => $library === 'Music, Podcasts' ? 'A song' : 'A film',
                'user_name' => 'Viewer',
                'library' => $library,
                'library_resolved_at' => $now,
                'play_method' => 'DirectPlay',
                'watched_sec' => 0,
                'runtime_sec' => 3600,
                'started_at' => $now,
                'updated_at' => $now,
                'is_finished' => 0,
                'notified' => 0,
            ])->execute();
        }

        AppSettings::set('push_ignore_libraries', NotificationLibraryExclusions::storedValue(
            NotificationLibraryExclusions::fromForm(['Music, Podcasts'], '')
        ));
        AppSettings::set('push_ignore_users', '');
        $previous = getenv('PUSH_ENABLED');
        putenv('PUSH_ENABLED=1');
        try {
            $channel = new CountingSuccessChannel();
            $notifier = new PlaybackNotifier(
                $history,
                new NotificationDispatcher(new WebPushSender(), new PushSubscriptionRepository($this->database), [$channel]),
            );

            $this->assertSame(1, $notifier->dispatch());
            $this->assertSame(1, $channel->calls);
            $this->assertStringContainsString('started watching', (string) $channel->notifications[0]['title']);
            $rows = $this->database->getDibi()->select('library, notified')->from('play_history')->fetchPairs('library', 'notified');
            $this->assertSame(1, (int) $rows['Music, Podcasts']);
            $this->assertSame(1, (int) $rows['Movies']);
            $this->assertSame(2, (int) $this->database->getDibi()->select('COUNT(*)')->from('play_history')->fetchSingle());
        } finally {
            putenv($previous === false ? 'PUSH_ENABLED' : 'PUSH_ENABLED=' . $previous);
            AppSettings::set('push_ignore_libraries', null);
            AppSettings::set('push_ignore_users', null);
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
        $now = (new DateTimeImmutable('now', new DateTimeZone(Config::timezone())))->format('Y-m-d H:i:s');
        $this->database->getDibi()->insert('seerr_requests', [
            'request_id' => 901,
            'media_type' => 'movie',
            'tmdb_id' => 902,
            'title' => 'Retry Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => $now,
            'requested_at_epoch' => time(),
            'notified' => 0,
            'created_at' => $now,
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

final class CountingSuccessChannel implements NotificationChannel
{
    public int $calls = 0;
    /** @var array<int, array<string, mixed>> */
    public array $notifications = [];

    public function name(): string
    {
        return 'success';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(array $notification): bool
    {
        ++$this->calls;
        $this->notifications[] = $notification;

        return true;
    }
}

final class CurrentDeviceRecordingTransport implements WebPushTransport
{
    /** @var list<array{endpoint: string, p256dh: string, auth: string}> */
    public array $subscriptions = [];

    public function send(array $subscriptions, ?string $payload): iterable
    {
        $this->subscriptions = $subscriptions;
        foreach ($subscriptions as $subscription) {
            yield [
                'endpoint' => $subscription['endpoint'],
                'success' => true,
                'expired' => false,
            ];
        }
    }
}

final class OutcomeWebPushTransport implements WebPushTransport
{
    /** @param list<array{endpoint: string, success: bool, expired: bool}> $reports */
    public function __construct(public array $reports)
    {
    }

    public function send(array $subscriptions, ?string $payload): iterable
    {
        yield from $this->reports;
    }
}
