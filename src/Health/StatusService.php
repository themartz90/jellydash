<?php

declare(strict_types=1);

namespace Mk\Framework\Health;

use Mk\Framework\Config;
use Mk\Framework\Notifications\DiscordChannel;
use Mk\Framework\Notifications\PushoverChannel;
use Mk\Framework\Notifications\TelegramChannel;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Push\WebPushSender;
use Mk\Framework\View;

/** Reads recorded worker outcomes without contacting any configured service. */
final class StatusService
{
    /**
     * @param \Closure(): array<string, array<string, mixed>>|null $readWorkers
     * @param \Closure(bool, bool, int): array{pending_retries: int, in_flight: int, stalled: int}|null $readQueue
     * @param \Closure(): int|null $countSubscriptions
     */
    public function __construct(
        private ?\Closure $readWorkers = null,
        private ?\Closure $readQueue = null,
        private ?\Closure $countSubscriptions = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $now = null): array
    {
        $now ??= time();
        $rows = ($this->readWorkers ?? static fn (): array => (new WorkerStatusRepository())->all())();
        $workersEnabled = Config::bool('POLLER_ENABLED', true);
        $historyInterval = $this->interval('POLL_INTERVAL', 30);
        $requestInterval = $this->interval('SEERR_POLL_INTERVAL', 120);
        $jellyfin = $this->configuration(
            Config::get('JELLYFIN_URL'),
            Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY')),
        );
        $jellyseerr = $this->configuration(Config::get('JELLYSEER_URL'), Config::get('JELLYSEER_API_TOKEN'));
        $components = [
            $this->worker('history', 'Jellyfin collection', $rows, $jellyfin, $historyInterval, $workersEnabled, $now),
            $this->worker('jellyseerr', 'Jellyseerr sync', $rows, $jellyseerr, $requestInterval, $workersEnabled, $now),
            $this->worker('libraries', 'Library refresh', $rows, $jellyfin, $this->interval('LIBRARIES_CACHE_TTL', 300), $workersEnabled, $now),
        ];

        $httpChannel = (new TelegramChannel())->isConfigured()
            || (new PushoverChannel())->isConfigured() || (new DiscordChannel())->isConfigured();
        $webPush = (new WebPushSender())->isConfigured();
        $notificationsConfigured = $httpChannel || $webPush;
        $incompleteNotifications = false;
        foreach ([['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'], ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID'], ['PUSHOVER_APP_TOKEN', 'PUSHOVER_USER_KEY']] as [$first, $second]) {
            if ((Config::get($first) !== null) !== (Config::get($second) !== null)) {
                $incompleteNotifications = true;
            }
        }
        $notificationsEnabled = Config::bool('PUSH_ENABLED', true);
        $hasDevices = !$notificationsEnabled || !$webPush || $httpChannel
            || ($this->countSubscriptions ?? static fn (): int => (new PushSubscriptionRepository())->count())() > 0;

        foreach ([
            ['playback_notifications', 'Playback notifications', 'playback_delivery', $historyInterval, $jellyfin === 'configured'],
            ['request_notifications', 'Request notifications', 'request_delivery', $requestInterval, $jellyseerr === 'configured' && Config::bool('SEERR_NOTIFY_ENABLED', true)],
        ] as [$id, $label, $deliveryId, $interval, $sourceEnabled]) {
            $enabled = $notificationsEnabled && $sourceEnabled && $notificationsConfigured;
            $component = $this->worker($id, $label, $rows, $enabled ? 'configured' : 'disabled', $interval, $workersEnabled, $now);
            if (!$enabled) {
                $component['message'] = $notificationsEnabled && $sourceEnabled
                    ? 'No notification channel is configured.' : 'Notifications are disabled for this source.';
            } elseif (!$hasDevices) {
                $component['state'] = 'unknown';
                $component['message'] = 'Web Push is configured, but no devices are subscribed.';
            } else {
                $queue = ($this->readQueue ?? static fn (bool $plays, bool $requests, int $epoch): array => (new NotificationQueueStatus())->snapshot($plays, $requests, $epoch))(
                    $id === 'playback_notifications',
                    $id === 'request_notifications',
                    $now,
                );
                $delivery = $rows[$deliveryId] ?? [];
                if (($delivery['status'] ?? null) === 'failed') {
                    $component['state'] = 'failed';
                    $component['message'] = 'The last delivery attempt failed. Check the configured channels.';
                } elseif ($queue['stalled'] > 0) {
                    $component['state'] = 'delayed';
                    $component['message'] = 'A delivery attempt stopped before finishing. The next worker run can recover it.';
                } elseif ($queue['pending_retries'] > 0 && !in_array($component['state'], ['failed', 'delayed'], true)) {
                    $component['state'] = 'delayed';
                    $component['message'] = 'Delivery retries are waiting for the next attempt.';
                } elseif ($component['state'] === 'healthy') {
                    $component['message'] = 'The notification worker is running. No delivery retries are waiting.';
                }
                $component['pending_retries'] = max(0, $queue['pending_retries']);
                $component['in_flight'] = max(0, $queue['in_flight']);
                $component['stalled'] = max(0, $queue['stalled']);
                $component['last_delivery_at'] = $this->epoch($delivery['last_finished_at'] ?? null);
            }
            if ($notificationsEnabled && $sourceEnabled && $incompleteNotifications) {
                $component['state'] = 'failed';
                $component['message'] = 'Some notification settings are incomplete. Check the credentials for each configured channel.';
            }
            $components[] = $component;
        }

        $states = array_column($components, 'state');
        $state = array_intersect($states, ['failed', 'delayed']) !== [] ? 'attention'
            : (array_intersect($states, ['unknown', 'checking']) !== [] || !in_array('healthy', $states, true) ? 'unknown' : 'healthy');

        // Build the export from known fields only. Never serialize repository
        // rows, environment values, exceptions, or integration responses.
        $diagnostics = [
            'jellydash_version' => View::version(),
            'php_version' => PHP_VERSION,
            'database' => in_array(strtolower((string) Config::get('DB_DRIVER', 'mysqli')), ['sqlite', 'sqlite3'], true) ? 'SQLite' : 'MariaDB',
            'timezone' => Config::timezone(),
            'generated_at' => $now,
            'source' => 'default',
            'state' => $state,
            'components' => array_map(static fn (array $component): array => array_intersect_key($component, array_flip([
                'id', 'state', 'last_attempt_at', 'last_success_at', 'interval_seconds',
                'pending_retries', 'in_flight', 'stalled', 'last_delivery_at',
            ])), $components),
        ];

        return ['generated_at' => $now, 'state' => $state, 'components' => $components, 'diagnostics' => $diagnostics];
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function worker(string $id, string $label, array $rows, string $configured, int $interval, bool $enabled, int $now): array
    {
        $row = $rows[$id] ?? [];
        $started = $this->epoch($row['last_started_at'] ?? null);
        $finished = $this->epoch($row['last_finished_at'] ?? null);
        $success = $this->epoch($row['last_success_at'] ?? null);
        $state = 'unknown';
        $message = 'No background check has been recorded yet. Check the worker schedule if this continues.';
        $delayAfter = max(90, $interval * 3);

        if ($configured !== 'configured') {
            $state = $configured === 'incomplete' ? 'failed' : 'disabled';
            $message = $configured === 'incomplete'
                ? 'The service URL or API token is missing. Check the configuration.' : 'This service is not configured.';
        } elseif (!$enabled && $row === []) {
            $state = 'disabled';
            $message = 'Built-in workers are disabled. An external schedule can still run this check.';
        } elseif ($started !== null && $finished !== null && $finished < $started) {
            $message = 'Recorded times are inconsistent. Check the worker and server clocks.';
        } elseif (max($started ?? 0, $finished ?? 0, $success ?? 0) > $now + 60) {
            $message = 'The recorded time is ahead of this server. Check the worker and server clocks.';
        } elseif (($row['status'] ?? null) === 'running' && $started !== null) {
            $state = $now - $started > $delayAfter ? 'delayed' : 'checking';
            $message = $state === 'delayed' ? 'The last check has not finished. Check the background worker.' : 'A background check is running.';
        } elseif (($row['status'] ?? null) === 'failed') {
            $state = 'failed';
            $message = match ($row['error_code'] ?? null) {
                'authentication_failed' => 'The service rejected access. Check its API token and permissions.',
                'timeout' => 'The service did not respond in time. Check its connection and worker logs.',
                'database_failed' => 'The worker could not complete a database operation. Check the database and worker logs.',
                default => 'The last check failed. Check the service connection and worker logs.',
            };
        } elseif ($finished !== null && $now - $finished > $delayAfter) {
            $state = 'delayed';
            $message = 'No recent background check. Check that the worker is running on schedule.';
        } elseif (($row['status'] ?? null) === 'success' && $finished !== null && $success !== null) {
            $state = 'healthy';
            $message = $id === 'history' ? 'Jellyfin was reached and collection completed, including when nothing was playing.' : 'The last background check completed successfully.';
        }

        return [
            'id' => $id, 'label' => $label, 'state' => $state, 'message' => $message,
            'last_attempt_at' => $started, 'last_success_at' => $success, 'interval_seconds' => $interval,
        ];
    }

    private function configuration(?string $url, ?string $token): string
    {
        $urlSet = trim($url ?? '') !== '';
        $tokenSet = trim($token ?? '') !== '';

        return $urlSet && $tokenSet ? 'configured' : ($urlSet || $tokenSet ? 'incomplete' : 'disabled');
    }

    private function interval(string $key, int $default): int
    {
        $value = filter_var(Config::get($key, (string) $default), FILTER_VALIDATE_INT);

        return $value !== false && $value >= 1 && $value <= 86400 ? $value : $default;
    }

    private function epoch(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
