<?php

declare(strict_types=1);

namespace Mk\Framework\Health;

use Mk\Framework\Config;
use Mk\Framework\Downloads\Feature;
use Mk\Framework\Jellyfin\HistoryPollDeferredException;
use Mk\Framework\Notifications\DiscordChannel;
use Mk\Framework\Notifications\GotifyChannel;
use Mk\Framework\Notifications\NotificationEndpoint;
use Mk\Framework\Notifications\NtfyChannel;
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
        private ?\Closure $readDownloads = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $now = null): array
    {
        $now ??= time();
        $rows = ($this->readWorkers ?? static fn (): array => (new WorkerStatusRepository())->all())();
        $workersEnabled = Config::bool('POLLER_ENABLED', true);
        $historyInterval = Config::interval('POLL_INTERVAL', 30);
        $requestInterval = Config::interval('SEERR_POLL_INTERVAL', 120);
        $jellyfin = $this->configuration(
            Config::get('JELLYFIN_URL'),
            Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY')),
        );
        $jellyseerr = $this->configuration(Config::get('JELLYSEER_URL'), Config::get('JELLYSEER_API_TOKEN'));
        $components = [
            $this->worker('history', 'Jellyfin collection', $rows, $jellyfin, $historyInterval, $workersEnabled, $now),
            $this->worker('jellyseerr', 'Jellyseerr sync', $rows, $jellyseerr, $requestInterval, $workersEnabled, $now),
            $this->worker('libraries', 'Library refresh', $rows, $jellyfin, Config::interval('LIBRARIES_CACHE_TTL', 300), $workersEnabled, $now),
        ];
        if ($historyInterval >= 120 && $jellyfin === 'configured' && $components[0]['state'] !== 'disabled') {
            if ($components[0]['state'] === 'healthy') {
                $components[0]['state'] = 'delayed';
            }
            $components[0]['message'] .= ' POLL_INTERVAL is 120 seconds or more, so measured watch time may miss playback between checks. Set it below 120 seconds.';
        }

        try {
            if (!Feature::enabled()) {
                if ((new \Mk\Framework\Integrations\ConnectionRepository())->all() !== []) {
                    $components[] = ['id' => 'downloads', 'label' => 'Downloads collection', 'state' => 'disabled',
                        'message' => 'Downloads is turned off in Settings.',
                        'last_attempt_at' => null, 'last_success_at' => null,
                        'interval_seconds' => \Mk\Framework\Downloads\Collector::INTERVAL];
                }
            } else {
                $downloads = ($this->readDownloads ?? static fn (): array => (new \Mk\Framework\Downloads\OverviewService(
                    new \Mk\Framework\Integrations\ConnectionRepository(),
                    new \Mk\Framework\Downloads\DownloadRepository(),
                ))->snapshot($now, true))();
                if ($downloads['configured'] ?? false) {
                    $enabledClients = array_values(array_filter($downloads['connections'], static fn (array $client): bool => $client['enabled']));
                    $component = $this->worker('downloads', 'Downloads collection', $rows,
                        $enabledClients === [] ? 'disabled' : 'configured',
                        \Mk\Framework\Downloads\Collector::INTERVAL, $workersEnabled, $now);
                    $healthy = count(array_filter($enabledClients, static fn (array $client): bool => $client['status'] === 'connected'));
                    if ($enabledClients === []) {
                        $component['message'] = 'All download clients are disabled.';
                    } elseif ($healthy > 0 && $healthy < count($enabledClients)) {
                        $component['state'] = 'delayed';
                        $component['message'] = 'Some download clients have no current status. Open Downloads for details.';
                    } elseif ($healthy === count($enabledClients)) {
                        $component['state'] = $downloads['partial'] ? 'delayed' : 'healthy';
                        $component['message'] = $downloads['partial'] ? 'Some current downloads may be missing. Open Downloads for details.' : 'All enabled download clients have current status.';
                    } elseif (array_intersect(array_column($enabledClients, 'status'), ['offline', 'stale']) !== []) {
                        $component['state'] = 'failed';
                        $component['message'] = 'Download clients have no current status. Open Downloads for details.';
                    } else {
                        $component['state'] = $workersEnabled ? 'unknown' : 'disabled';
                        $component['message'] = 'Waiting for the first download update. Check the collector schedule if this continues.';
                    }
                    $components[] = $component;
                }
            }
        } catch (\Throwable) {
            $components[] = ['id' => 'downloads', 'label' => 'Downloads collection', 'state' => 'failed',
                'message' => 'Download status could not be read from the database.',
                'last_attempt_at' => null, 'last_success_at' => null, 'interval_seconds' => \Mk\Framework\Downloads\Collector::INTERVAL];
        }

        $discord = new DiscordChannel();
        $httpChannel = (new TelegramChannel())->isConfigured()
            || (new PushoverChannel())->isConfigured() || $discord->isConfigured();
        $webPush = (new WebPushSender())->isConfigured();
        $incompleteNotifications = [];
        if (Config::get('DISCORD_WEBHOOK_URL') !== null && !$discord->isConfigured()) {
            $incompleteNotifications[] = 'Discord';
        }
        $appUrl = NotificationEndpoint::setting('APP_URL');
        if ($appUrl !== '' && NotificationEndpoint::baseUrl($appUrl) === null) {
            $incompleteNotifications[] = 'APP_URL';
        }
        foreach (['ntfy' => new NtfyChannel(), 'Gotify' => new GotifyChannel()] as $label => $channel) {
            $configured = $channel->isConfigured();
            $httpChannel = $httpChannel || $configured;
            if ($channel->hasConfiguration() && !$configured) {
                $incompleteNotifications[] = $label;
            }
        }
        $notificationsConfigured = $httpChannel || $webPush;
        foreach ([
            'Web Push' => ['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'],
            'Telegram' => ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID'],
            'Pushover' => ['PUSHOVER_APP_TOKEN', 'PUSHOVER_USER_KEY'],
        ] as $label => [$first, $second]) {
            if ((Config::get($first) !== null) !== (Config::get($second) !== null)) {
                $incompleteNotifications[] = $label;
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
                } elseif ($component['state'] === 'checking' && $queue['pending_retries'] > 0) {
                    $component['message'] = 'A delivery attempt is running. ' . $queue['pending_retries']
                        . ' ' . ($queue['pending_retries'] === 1 ? 'retry is' : 'retries are') . ' queued.';
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
            if ($notificationsEnabled && $sourceEnabled && $incompleteNotifications !== []) {
                $component['state'] = 'failed';
                $component['message'] = 'Incomplete or invalid notification settings: '
                    . implode(', ', $incompleteNotifications)
                    . '. Complete these settings or clear them if unused.';
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
        } elseif (($row['status'] ?? null) === 'deferred') {
            $state = 'delayed';
            $message = match ($row['error_code'] ?? null) {
                HistoryPollDeferredException::CAPTURE => 'History was busy before this check could capture playback. The next check will try again.',
                HistoryPollDeferredException::DRAIN => 'Playback was captured and is waiting to be recorded in History.',
                HistoryPollDeferredException::CLASSIFICATION => 'Playback was recorded in History. Theme classification was deferred.',
                default => 'History was busy during the last check. The next check will try again.',
            };
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

    private function epoch(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
