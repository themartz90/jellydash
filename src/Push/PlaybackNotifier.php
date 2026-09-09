<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;
use Mk\Framework\Health\WorkerMonitor;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Notifications\ClaimedNotificationDelivery;
use Mk\Framework\Notifications\NotificationDispatcher;

/**
 * Turns freshly-started plays into notifications ("Sarah started watching...").
 * Driven by the background poller after it records active streams; delivery
 * goes through the dispatcher to every configured channel.
 */
final class PlaybackNotifier
{
    // Only alert on plays that started within this window, so a poller that was
    // offline for a while doesn't fire a batch of stale "started" alerts.
    private const FRESH_WINDOW_SECONDS = 600;

    public function __construct(
        private ?PlayHistoryRepository $history = null,
        private ?NotificationDispatcher $dispatcher = null,
        private ?ClaimedNotificationDelivery $delivery = null,
        private ?WorkerMonitor $monitor = null,
    ) {
    }

    /**
     * Detect new plays and notify every configured channel. Returns the number
     * of plays that reached at least one channel.
     */
    public function dispatch(): int
    {
        if (!Config::bool('PUSH_ENABLED', true)) {
            return 0;
        }

        $dispatcher = $this->dispatcher ?? new NotificationDispatcher();
        if (!$dispatcher->hasAnyChannel()) {
            return 0;
        }

        $history = $this->history ?? new PlayHistoryRepository();
        $plays = $history->claimUnnotifiedPlays($this->ignoredUsers(), self::FRESH_WINDOW_SECONDS);
        if ($plays === []) {
            return 0;
        }

        $notified = 0;
        ($this->monitor ?? new WorkerMonitor())->observe('playback_delivery', function () use ($plays, $dispatcher, $history, &$notified): bool {
            $allDelivered = true;
            foreach ($plays as $play) {
                $id = (int) $play['id'];
                $token = (string) $play['notification_claim_token'];
                if (($this->delivery ?? new ClaimedNotificationDelivery())->deliver(
                    fn (): int => $dispatcher->send($this->payloadFor($play)),
                    function () use ($history, $id, $token): void {
                        $history->acknowledgeNotificationClaim($id, $token);
                    },
                    function () use ($history, $id, $token): void {
                        $history->failNotificationClaim($id, $token);
                    },
                )) {
                    $notified++;
                } else {
                    $allDelivered = false;
                }
            }

            return $allDelivered;
        });

        return $notified;
    }

    /**
     * Send a one-off test notification through every channel and report the
     * per-channel outcome.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendTest(): array
    {
        $dispatcher = $this->dispatcher ?? new NotificationDispatcher();

        return $dispatcher->test([
            'title' => '🔔 Jellydash notifications are on',
            'body' => "You'll get an alert when someone starts playing.",
            'tag' => 'jellydash-test',
            'url' => '/now-playing',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function ignoredUsers(): array
    {
        // Settings-page value wins; a never-saved setting falls back to the
        // legacy PUSH_IGNORE_USERS env var.
        $raw = AppSettings::get('push_ignore_users')
            ?? (string) Config::get('PUSH_IGNORE_USERS', '');

        return array_values(array_filter(array_map(
            static fn (string $s): string => trim($s, " \t\n\r\0\x0B\"'"),
            explode(',', $raw)
        ), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param \Dibi\Row|array<string, mixed> $play
     * @return array<string, mixed>
     */
    private function payloadFor($play): array
    {
        $user = trim((string) ($play['user_name'] ?? '')) ?: 'Someone';
        $isAudio = strtolower((string) ($play['item_type'] ?? '')) === 'audio';
        $verb = $isAudio ? 'started listening' : 'started watching';

        return [
            'title' => '▶ ' . $user . ' ' . $verb,
            'body' => $this->describe($play),
            'tag' => 'jellydash-play-' . (string) ($play['id'] ?? ''),
            'url' => '/now-playing',
        ];
    }

    /**
     * @param \Dibi\Row|array<string, mixed> $play
     */
    private function describe($play): string
    {
        $series = trim((string) ($play['series_name'] ?? ''));
        $item = trim((string) ($play['item_name'] ?? ''));
        $seasonEp = trim((string) ($play['season_ep'] ?? ''));

        if ($series !== '') {
            $what = $seasonEp !== '' ? $series . ' · ' . $seasonEp : $series;
        } elseif ((string) ($play['item_type'] ?? '') === 'TvChannel') {
            $what = '📺 ' . ($item !== '' ? $item : 'Live TV') . ' (Live)';
        } else {
            $what = $item !== '' ? $item : 'Something';
        }

        $method = trim((string) ($play['play_method_detail'] ?? ''))
            ?: trim((string) ($play['play_method'] ?? ''));
        $where = trim((string) ($play['device'] ?? '')) ?: trim((string) ($play['client'] ?? ''));

        $meta = array_values(array_filter([$method, $where]));

        return $meta === [] ? $what : $what . "\n" . implode(' · ', $meta);
    }
}
