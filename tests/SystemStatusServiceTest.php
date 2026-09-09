<?php

declare(strict_types=1);

use Mk\Framework\Health\StatusService;
use PHPUnit\Framework\TestCase;

final class SystemStatusServiceTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach ([
            'JELLYFIN_URL' => 'http://fixture.invalid', 'JELLYFIN_API_TOKEN' => 'private-token',
            'JELLYFIN_API_KEY' => '', 'JELLYSEER_URL' => '', 'JELLYSEER_API_TOKEN' => '',
            'VAPID_PUBLIC_KEY' => '', 'VAPID_PRIVATE_KEY' => '', 'TELEGRAM_BOT_TOKEN' => '',
            'TELEGRAM_CHAT_ID' => '', 'DISCORD_WEBHOOK_URL' => '', 'PUSHOVER_APP_TOKEN' => '',
            'PUSHOVER_USER_KEY' => '', 'PUSH_ENABLED' => 'true', 'SEERR_NOTIFY_ENABLED' => 'true',
            'POLLER_ENABLED' => 'true', 'POLL_INTERVAL' => '30', 'SEERR_POLL_INTERVAL' => '120',
            'LIBRARIES_CACHE_TTL' => '300',
        ] as $key => $value) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testIdleSuccessAndDisabledIntegrationsNeedNoNetworkOrQueueReads(): void
    {
        $service = new StatusService(
            fn (): array => ['history' => $this->success(), 'libraries' => $this->success()],
            static function (): never {
                throw new RuntimeException('Disabled queues must not be read.');
            },
            static function (): never {
                throw new RuntimeException('Disabled Web Push must not be read.');
            },
        );
        $result = $service->snapshot(1000);
        self::assertSame('healthy', $result['state']);
        self::assertSame('healthy', $result['components'][0]['state']);
        self::assertSame('disabled', $result['components'][1]['state']);
        self::assertSame(990, $result['components'][0]['last_success_at']);
    }

    public function testNeverRunIsUnknownAndPausedDockerWorkersAreDisabled(): void
    {
        self::assertSame('unknown', $this->history([])['state']);
        putenv('POLLER_ENABLED=false');
        self::assertSame('disabled', $this->history([])['state']);
        self::assertSame('healthy', $this->history($this->success())['state'], 'External scheduling can still run workers.');
    }

    public function testFreshnessUsesTheConfiguredWorkerInterval(): void
    {
        $row = $this->success(800);
        self::assertSame('delayed', $this->history($row)['state']);
        putenv('POLL_INTERVAL=120');
        self::assertSame('healthy', $this->history($row)['state']);
    }

    public function testRunningStalledFailedRecoveredAndFutureClockStates(): void
    {
        self::assertSame('checking', $this->history(['status' => 'running', 'last_started_at' => 990])['state']);
        self::assertSame('delayed', $this->history(['status' => 'running', 'last_started_at' => 800])['state']);
        self::assertSame('failed', $this->history(['status' => 'failed', 'last_finished_at' => 990, 'last_success_at' => 700])['state']);
        self::assertSame('healthy', $this->history($this->success())['state']);
        self::assertSame('unknown', $this->history($this->success(1200))['state']);
        self::assertSame('unknown', $this->history(['status' => 'success', 'last_started_at' => 990, 'last_finished_at' => 950, 'last_success_at' => 950])['state']);
    }

    public function testIncompleteServiceSetupDoesNotLookHealthy(): void
    {
        putenv('JELLYFIN_API_TOKEN=');
        self::assertSame('failed', $this->history($this->success())['state']);
        putenv('JELLYFIN_API_KEY=legacy-token');
        self::assertSame('healthy', $this->history($this->success())['state']);
    }

    public function testRetryBackoffAndExpiredClaimsAreVisible(): void
    {
        putenv('DISCORD_WEBHOOK_URL=https://private.invalid/secret');
        foreach ([['pending_retries' => 2, 'in_flight' => 0, 'stalled' => 0], ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 1]] as $queue) {
            $service = new StatusService(fn (): array => ['playback_notifications' => $this->success()], static fn (): array => $queue);
            $result = $service->snapshot(1000);
            self::assertSame('attention', $result['state']);
            self::assertSame('delayed', $result['components'][3]['state']);
        }
    }

    public function testLastDeliveryFailureSurvivesAnIdleWorkerCheck(): void
    {
        putenv('DISCORD_WEBHOOK_URL=https://private.invalid/secret');
        $rows = ['playback_notifications' => $this->success(), 'playback_delivery' => ['status' => 'failed', 'last_finished_at' => 700]];
        $service = new StatusService(static fn (): array => $rows, static fn (): array => ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0]);
        self::assertSame('failed', $service->snapshot(1000)['components'][3]['state']);
        $rows['playback_delivery'] = $this->success();
        $service = new StatusService(static fn (): array => $rows, static fn (): array => ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0]);
        self::assertSame('healthy', $service->snapshot(1000)['components'][3]['state']);
    }

    public function testWebPushWithoutSubscribersDoesNotClaimDeliveryHealth(): void
    {
        putenv('VAPID_PUBLIC_KEY=fixture-public');
        putenv('VAPID_PRIVATE_KEY=fixture-private');
        $service = new StatusService(fn (): array => ['playback_notifications' => $this->success()], null, static fn (): int => 0);
        self::assertSame('unknown', $service->snapshot(1000)['components'][3]['state']);
    }

    public function testPartialNotificationSettingsNeedAttentionEvenWithAnotherConfiguredChannel(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=fixture-token');
        $service = new StatusService(fn (): array => ['playback_notifications' => $this->success()], static fn (): array => ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0]);
        self::assertSame('failed', $service->snapshot(1000)['components'][3]['state']);
        putenv('DISCORD_WEBHOOK_URL=https://fixture.invalid/secret');
        self::assertSame('failed', $service->snapshot(1000)['components'][3]['state']);
        putenv('TELEGRAM_CHAT_ID=fixture-chat');
        self::assertSame('healthy', $service->snapshot(1000)['components'][3]['state']);
    }

    public function testExportUsesAnAllowlistAndDoesNotExposeSecretsOrRawErrors(): void
    {
        $row = $this->success() + ['token' => 'lease-secret', 'error_code' => 'https://private.invalid/token', 'username' => 'private-person', 'item_title' => 'private-title'];
        $result = (new StatusService(static fn (): array => ['history' => $row, 'unknown-private-component' => $row]))->snapshot(1000);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['fixture.invalid', 'private-token', 'lease-secret', 'private.invalid', 'private-person', 'private-title', 'unknown-private-component'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(['id', 'state', 'last_attempt_at', 'last_success_at', 'interval_seconds'], array_keys($result['diagnostics']['components'][0]));
    }

    /** @return array<string, mixed> */
    private function success(int $epoch = 990): array
    {
        return ['status' => 'success', 'last_started_at' => $epoch - 1, 'last_finished_at' => $epoch, 'last_success_at' => $epoch];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function history(array $row): array
    {
        return (new StatusService(static fn (): array => $row === [] ? [] : ['history' => $row]))->snapshot(1000)['components'][0];
    }
}
