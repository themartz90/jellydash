<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class MinishlinkWebPushTransport implements WebPushTransport
{
    private WebPush $webPush;

    /**
     * @param array{subject: string, publicKey: string, privateKey: string} $vapid
     * @param array<string, mixed> $clientOptions
     */
    public function __construct(array $vapid, array $clientOptions)
    {
        $this->webPush = new WebPush(['VAPID' => $vapid], [], 30, $clientOptions);
    }

    public function send(array $subscriptions, ?string $payload): iterable
    {
        foreach ($subscriptions as $subscription) {
            try {
                $this->webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription['endpoint'],
                        'keys' => [
                            'p256dh' => $subscription['p256dh'],
                            'auth' => $subscription['auth'],
                        ],
                    ]),
                    $payload,
                );
            } catch (\Throwable) {
                yield [
                    'endpoint' => $subscription['endpoint'],
                    'success' => false,
                    'expired' => false,
                ];
            }
        }

        foreach ($this->webPush->flush() as $report) {
            yield [
                'endpoint' => $report->getEndpoint(),
                'success' => $report->isSuccess(),
                'expired' => $report->isSubscriptionExpired(),
            ];
        }
    }
}
