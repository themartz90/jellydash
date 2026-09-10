<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

interface WebPushTransport
{
    /**
     * @param list<array{endpoint: string, p256dh: string, auth: string}> $subscriptions
     * @return iterable<array{endpoint: string, success: bool, expired: bool}>
     */
    public function send(array $subscriptions, ?string $payload): iterable;
}
