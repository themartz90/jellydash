<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Config;
use Mk\Framework\Log;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Push\WebPushSender;

/**
 * Fans one notification out to every configured delivery channel: Web Push
 * subscriptions plus the configured HTTP channels.
 * Producers (playback and Jellyseerr alerts) only build the message; where it
 * goes is decided here, purely by which env config exists.
 */
final class NotificationDispatcher
{
    /** @var array<int, NotificationChannel> */
    private array $channels;

    private WebPushSender $webPush;
    private ?PushSubscriptionRepository $subscriptions;

    /**
     * @param array<int, NotificationChannel>|null $channels
     */
    public function __construct(
        ?WebPushSender $webPush = null,
        ?PushSubscriptionRepository $subscriptions = null,
        ?array $channels = null,
    ) {
        $this->webPush = $webPush ?? new WebPushSender();
        $this->subscriptions = $subscriptions;
        $this->channels = $channels ?? [
            new TelegramChannel(),
            new PushoverChannel(),
            new DiscordChannel(),
            new NtfyChannel(),
            new GotifyChannel(),
        ];
    }

    /**
     * True when at least one delivery path is configured, so producers can
     * skip claiming work that could never be delivered.
     */
    public function hasAnyChannel(): bool
    {
        if ($this->webPush->isConfigured()) {
            return true;
        }

        foreach ($this->channels as $channel) {
            if ($channel->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deliver to everything configured. Returns how many channels accepted the
     * message (Web Push counts once when at least one device got it).
     *
     * @param array<string, mixed> $notification keys: title, body, tag, url
     */
    public function send(array $notification): int
    {
        $notification = $this->withAbsoluteUrl($notification);
        $delivered = 0;

        if ($this->webPush->isConfigured()) {
            try {
                $subs = $this->subscriptions()->deliverySubscriptions(Config::bool('AUTH_ENABLED', false));
                if ($subs !== []) {
                    $result = $this->webPush->send($subs, $notification);
                    if ($result['sent'] > 0) {
                        $delivered++;
                    }
                    $this->recordWebPushOutcome($result);
                }
            } catch (\Throwable) {
                Log::logErrorMessage('Web Push delivery failed. Check the database and Web Push settings.', self::class);
            }
        }

        foreach ($this->channels as $channel) {
            if (!$channel->isConfigured()) {
                continue;
            }

            try {
                if ($channel->send($notification)) {
                    $delivered++;
                }
            } catch (\Throwable $e) {
                // A broken channel must never take down the poller or the rest
                // of the fan-out.
                Log::logException($e);
            }
        }

        return $delivered;
    }

    /**
     * Per-channel test delivery, for the console command and the in-app test.
     *
     * @param array<string, mixed> $notification
     * @return array<string, array<string, mixed>>
     */
    public function test(array $notification): array
    {
        $notification = $this->withAbsoluteUrl($notification);
        $report = [];

        $webPush = [
            'configured' => $this->webPush->isConfigured(),
            'subscriptions' => 0, 'sent' => 0, 'failed' => 0, 'ineligible' => 0,
        ];
        if ($webPush['configured']) {
            try {
                $subs = $this->subscriptions()->deliverySubscriptions(Config::bool('AUTH_ENABLED', false));
                $webPush['subscriptions'] = count($subs);
                if ($subs !== []) {
                    $result = $this->webPush->send($subs, $notification);
                    foreach (['sent', 'failed', 'ineligible'] as $field) {
                        $webPush[$field] = $result[$field];
                    }
                    $this->recordWebPushOutcome($result);
                }
            } catch (\Throwable $e) {
                Log::logException($e);
                $webPush['error'] = 'Web Push test could not finish. Check the application log for details.';
            }
        }
        $report['webpush'] = $webPush;

        foreach ($this->channels as $channel) {
            $entry = ['configured' => $channel->isConfigured(), 'sent' => false];
            if ($channel->isConfigured()) {
                try {
                    $entry['sent'] = $channel->send($notification);
                } catch (\Throwable $e) {
                    Log::logException($e);
                }
            }
            $report[$channel->name()] = $entry;
        }

        return $report;
    }

    /**
     * Send the Web Push confirmation only to the browser that just enrolled.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @param array<string, mixed>                                  $notification
     * @return array{configured: bool, sent: int, failed: int, ineligible: int}
     */
    public function testCurrentWebPush(array $subscription, array $notification): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'expired' => [], 'ineligible' => 0,
            'succeeded' => [], 'failed_endpoints' => [], 'ineligible_endpoints' => []];
        if ($this->webPush->isConfigured()) {
            $result = $this->webPush->send([$subscription], $this->withAbsoluteUrl($notification));
            $this->recordWebPushOutcome($result);
        }

        return [
            'configured' => $this->webPush->isConfigured(),
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'ineligible' => $result['ineligible'],
        ];
    }

    /**
     * @param array{sent: int, failed: int, expired: list<string>, ineligible: int, succeeded: list<string>, failed_endpoints: list<string>, ineligible_endpoints: list<string>} $result
     */
    private function recordWebPushOutcome(array $result): void
    {
        foreach ($result['succeeded'] as $endpoint) {
            $this->subscriptions()->markSuccess($endpoint);
        }
        foreach ($result['failed_endpoints'] as $endpoint) {
            $this->subscriptions()->markFailure($endpoint);
        }
        foreach (array_merge($result['expired'], $result['ineligible_endpoints']) as $endpoint) {
            $this->subscriptions()->delete($endpoint);
        }
    }

    private function subscriptions(): PushSubscriptionRepository
    {
        return $this->subscriptions ??= new PushSubscriptionRepository();
    }

    /**
     * Web Push opens relative URLs inside the PWA, but external services need
     * an absolute link. APP_URL (optional) provides the public base.
     *
     * @param array<string, mixed> $notification
     * @return array<string, mixed>
     */
    private function withAbsoluteUrl(array $notification): array
    {
        $base = NotificationEndpoint::baseUrl(NotificationEndpoint::setting('APP_URL'));
        $path = (string) ($notification['url'] ?? '');

        if ($base !== null && $path !== '' && str_starts_with($path, '/')) {
            $notification['absolute_url'] = rtrim($base, '/') . $path;
        }

        return $notification;
    }
}
