<?php

declare(strict_types=1);

use Mk\Framework\Push\PushSubscriptionValidator;
use Mk\Framework\Push\WebPushSender;
use Mk\Framework\Push\WebPushTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushSecurityTest extends TestCase
{
    #[DataProvider('supportedProviderEndpoints')]
    public function testAcceptsSupportedProviderEndpoints(string $endpoint): void
    {
        $this->assertTrue(PushSubscriptionValidator::isValid(
            $endpoint,
            $this->base64Url("\x04" . str_repeat('p', 64)),
            $this->base64Url(str_repeat('a', 16))
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function supportedProviderEndpoints(): iterable
    {
        yield 'Firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/example-token'];
        yield 'Chromium FCM' => ['https://fcm.googleapis.com/wp/example-token'];
        yield 'legacy Chromium FCM' => ['https://fcm.googleapis.com/fcm/send/example-token'];
        yield 'Safari' => ['https://web.push.apple.com/example-token'];
        yield 'Windows notification service' => ['https://wns2-par02p.notify.windows.com/w/example-token'];
    }

    public function testRejectsUnsafeEndpointsAndMalformedKeys(): void
    {
        $publicKey = $this->base64Url("\x04" . str_repeat('p', 64));
        $auth = $this->base64Url(str_repeat('a', 16));

        $this->assertFalse(PushSubscriptionValidator::isValid('http://push.example.test/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://user:pass@push.example.test/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.example.test/send#fragment', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://attacker.invalid/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://fcm.googleapis.com.attacker.invalid/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.apple.com.attacker.invalid/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://127.0.0.1/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://[::1]/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://fcm.googleapis.com:8443/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://fcm.googleapis.com./send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.example.test/send', 'not-a-public-key', $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.example.test/send', $publicKey, 'not-an-auth-secret'));
    }

    public function testDestinationPolicyIsSeparateFromUrlSyntax(): void
    {
        $this->assertTrue(PushSubscriptionValidator::hasValidEndpointSyntax('https://attacker.invalid/send'));
        $this->assertFalse(PushSubscriptionValidator::isAllowedDestination('https://attacker.invalid/send'));
        $this->assertTrue(PushSubscriptionValidator::hasValidEndpointSyntax('https://127.0.0.1/send'));
        $this->assertFalse(PushSubscriptionValidator::isAllowedDestination('https://127.0.0.1/send'));
        $this->assertTrue(PushSubscriptionValidator::hasValidEndpointSyntax('https://fcm.googleapis.com:8443/send'));
        $this->assertFalse(PushSubscriptionValidator::isAllowedDestination('https://fcm.googleapis.com:8443/send'));
    }

    public function testWebPushClientDisablesRedirects(): void
    {
        $options = (new \ReflectionClass(WebPushSender::class))
            ->getMethod('clientOptions')
            ->invoke(null);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('allow_redirects', $options);
        $this->assertFalse($options['allow_redirects']);
        $this->assertArrayHasKey('verify', $options);
        $this->assertTrue($options['verify']);
        $this->assertArrayHasKey('connect_timeout', $options);
        $this->assertSame(10, $options['connect_timeout']);
    }

    public function testStoredUnsupportedEndpointIsReportedIneligibleWithoutTransportOrDeletion(): void
    {
        $transport = new RecordingWebPushTransport([
            ['endpoint' => 'https://fcm.googleapis.com/wp/valid', 'success' => true, 'expired' => false],
        ]);
        $sender = new WebPushSender($transport, 'public', 'private', 'mailto:test@example.test');

        $result = $sender->send([
            $this->subscription('https://attacker.invalid/stored-row'),
            $this->subscription('https://fcm.googleapis.com/wp/valid'),
        ], ['title' => 'Fixture']);

        $this->assertCount(1, $transport->subscriptions);
        $this->assertSame('https://fcm.googleapis.com/wp/valid', $transport->subscriptions[0]['endpoint']);
        $this->assertSame([
            'sent' => 1,
            'failed' => 1,
            'expired' => [],
            'ineligible' => 1,
        ], $result);
    }

    public function testCertificateOrConnectionFailureFailsClosed(): void
    {
        $transport = new RecordingWebPushTransport([], true);
        $sender = new WebPushSender($transport, 'public', 'private', 'mailto:test@example.test');

        $result = $sender->send([
            $this->subscription('https://updates.push.services.mozilla.com/wpush/v2/failure'),
        ], ['title' => 'Fixture']);

        $this->assertSame([
            'sent' => 0,
            'failed' => 1,
            'expired' => [],
            'ineligible' => 0,
        ], $result);
    }

    /** @return array{endpoint: string, p256dh: string, auth: string} */
    private function subscription(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'p256dh' => $this->base64Url("\x04" . str_repeat('p', 64)),
            'auth' => $this->base64Url(str_repeat('a', 16)),
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

final class RecordingWebPushTransport implements WebPushTransport
{
    /** @var list<array{endpoint: string, p256dh: string, auth: string}> */
    public array $subscriptions = [];

    /**
     * @param list<array{endpoint: string, success: bool, expired: bool}> $reports
     */
    public function __construct(private array $reports, private bool $fail = false)
    {
    }

    public function send(array $subscriptions, ?string $payload): iterable
    {
        $this->subscriptions = $subscriptions;
        if ($this->fail) {
            throw new RuntimeException('Synthetic certificate failure.');
        }

        yield from $this->reports;
    }
}
