<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Mk\Framework\Config;
use Mk\Framework\Log;

/**
 * Thin wrapper over minishlink/web-push: signs each message with our VAPID
 * keypair, encrypts the payload for each subscription, and reports which
 * endpoints are dead so the caller can prune them.
 */
final class WebPushSender
{
    private ?string $publicKey;
    private ?string $privateKey;
    private string $subject;
    private ?WebPushTransport $transport;

    public function __construct(
        ?WebPushTransport $transport = null,
        ?string $publicKey = null,
        ?string $privateKey = null,
        ?string $subject = null,
    ) {
        $this->transport = $transport;
        $this->publicKey = $publicKey ?? Config::get('VAPID_PUBLIC_KEY');
        $this->privateKey = $privateKey ?? Config::get('VAPID_PRIVATE_KEY');
        $this->subject = $subject
            ?? Config::get('VAPID_SUBJECT', 'mailto:admin@example.com')
            ?? 'mailto:admin@example.com';
    }

    /**
     * True once a VAPID keypair is configured; otherwise sending is impossible
     * and callers should no-op quietly.
     */
    public function isConfigured(): bool
    {
        return $this->publicKey !== null && $this->privateKey !== null;
    }

    /**
     * Send one payload to every given subscription.
     *
     * @param array<int, array{endpoint: string, p256dh: string, auth: string}> $subscriptions
     * @param array<string, mixed> $payload
     * @return array{sent: int, failed: int, expired: array<int, string>, ineligible: int}
     *   `expired` holds endpoints the push service rejected as gone (404/410),
     *   which the caller should delete.
     */
    public function send(array $subscriptions, array $payload): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'expired' => [], 'ineligible' => 0];

        if (!$this->isConfigured() || $subscriptions === []) {
            return $result;
        }

        $eligible = [];
        foreach ($subscriptions as $sub) {
            if (!PushSubscriptionValidator::isValid($sub['endpoint'], $sub['p256dh'], $sub['auth'])) {
                ++$result['failed'];
                ++$result['ineligible'];
                continue;
            }
            $eligible[] = $sub;
        }
        if ($eligible === []) {
            return $result;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $reported = 0;
        try {
            foreach ($this->transport()->send($eligible, $json !== false ? $json : null) as $report) {
                ++$reported;
                if ($report['success']) {
                    ++$result['sent'];
                    continue;
                }

                ++$result['failed'];
                if ($report['expired']) {
                    $result['expired'][] = $report['endpoint'];
                }
            }
        } catch (\Throwable) {
            $result['failed'] += max(0, count($eligible) - $reported);
            Log::logErrorMessage('Web Push transport failed.', self::class);
        }

        return $result;
    }

    private function transport(): WebPushTransport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        if ($this->publicKey === null || $this->privateKey === null) {
            throw new \LogicException('Web Push is not configured.');
        }

        return $this->transport = new MinishlinkWebPushTransport([
            'subject' => $this->subject,
            'publicKey' => $this->publicKey,
            'privateKey' => $this->privateKey,
        ], self::clientOptions());
    }

    /** @return array{allow_redirects: false, verify: true, connect_timeout: int} */
    private static function clientOptions(): array
    {
        return [
            'allow_redirects' => false,
            'verify' => true,
            'connect_timeout' => 10,
        ];
    }
}
