<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Config;
use Mk\Framework\Health\WorkerMonitor;
use Mk\Framework\Notifications\ClaimedNotificationDelivery;
use Mk\Framework\Notifications\NotificationDispatcher;

/**
 * Announces newly-seen Jellyseerr requests through the notification
 * dispatcher, so they reach every configured channel.
 */
final class RequestNotifier
{
    public function __construct(
        private ?SeerrRequestRepository $requests = null,
        private ?NotificationDispatcher $dispatcher = null,
        private ?ClaimedNotificationDelivery $delivery = null,
        private ?WorkerMonitor $monitor = null,
    ) {
    }

    /**
     * Notify every configured channel about requests not yet announced.
     * Returns the number of requests that reached at least one channel.
     */
    public function dispatch(): int
    {
        if (!Config::bool('PUSH_ENABLED', true) || !Config::bool('SEERR_NOTIFY_ENABLED', true)) {
            return 0;
        }

        $dispatcher = $this->dispatcher ?? new NotificationDispatcher();
        if (!$dispatcher->hasAnyChannel()) {
            return 0;
        }

        $repo = $this->requests ?? new SeerrRequestRepository();
        $pending = $repo->claimUnnotified();
        if ($pending === []) {
            return 0;
        }

        $notified = 0;
        ($this->monitor ?? new WorkerMonitor())->observe('request_delivery', function () use ($pending, $dispatcher, $repo, &$notified): bool {
            $allDelivered = true;
            foreach ($pending as $request) {
                $id = (int) $request['id'];
                $token = (string) $request['notification_claim_token'];
                if (($this->delivery ?? new ClaimedNotificationDelivery())->deliver(
                    fn (): int => $dispatcher->send($this->payloadFor($request)),
                    function () use ($repo, $id, $token): void {
                        $repo->acknowledgeNotificationClaim($id, $token);
                    },
                    function () use ($repo, $id, $token): void {
                        $repo->failNotificationClaim($id, $token);
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
     * @param \Dibi\Row|array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function payloadFor($request): array
    {
        $who = trim((string) ($request['requested_by'] ?? '')) ?: 'Someone';
        $title = trim((string) ($request['title'] ?? '')) ?: 'Unknown title';
        $year = trim((string) ($request['year'] ?? ''));
        $isTv = (string) ($request['media_type'] ?? '') === 'tv';

        $line = $year !== '' ? $title . ' (' . $year . ')' : $title;
        $kind = $isTv ? 'Series' : 'Movie';
        if ((int) ($request['is_4k'] ?? 0) === 1) {
            $kind .= ' · 4K';
        }

        return [
            'title' => '🎬 New request from ' . $who,
            'body' => $line . "\n" . $kind,
            'tag' => 'jellydash-seerr-' . (string) ($request['request_id'] ?? ''),
            'url' => '/jellyseerr',
        ];
    }
}
