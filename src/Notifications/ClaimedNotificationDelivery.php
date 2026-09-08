<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

/**
 * Completes or releases a notification claim after one fan-out attempt.
 *
 * A process can still die after an external channel accepts a message but
 * before the claim is acknowledged. The lease makes that work recoverable,
 * but exactly-once delivery cannot be guaranteed across that crash boundary.
 */
final class ClaimedNotificationDelivery
{
    /**
     * @param \Closure(): int  $send
     * @param \Closure(): void $acknowledge
     * @param \Closure(): void $fail
     */
    public function deliver(\Closure $send, \Closure $acknowledge, \Closure $fail): bool
    {
        if ($send() > 0) {
            $acknowledge();

            return true;
        }

        $fail();

        return false;
    }
}
