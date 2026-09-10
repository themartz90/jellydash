<?php

declare(strict_types=1);

use Mk\Framework\RequestContext;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase
{
    public function testUntrustedForwardedHeadersCannotChangeClientOrScheme(): void
    {
        $context = new RequestContext(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertSame('198.51.100.20', $context->clientIp($server));
        self::assertFalse($context->isHttps($server));
        self::assertTrue($context->secureCookies($server), 'Legacy forwarded HTTPS remains a cookie-only compatibility signal.');
    }

    public function testTrustedProxyChainIsWalkedFromTheNearestPeer(): void
    {
        $context = new RequestContext(['10.0.0.0/8', '2001:db8:abcd::/48']);

        self::assertSame('203.0.113.9', $context->clientIp([
            'REMOTE_ADDR' => '10.0.0.4',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.3',
        ]));
        self::assertSame('2001:db8::10', $context->clientIp([
            'REMOTE_ADDR' => '2001:db8:abcd::2',
            'HTTP_X_FORWARDED_FOR' => '2001:db8::10',
        ]));
    }

    public function testMalformedForwardedChainFallsBackToImmediatePeer(): void
    {
        $context = new RequestContext(['10.0.0.0/8']);

        self::assertSame('10.0.0.4', $context->clientIp([
            'REMOTE_ADDR' => '10.0.0.4',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, malformed',
        ]));
    }

    public function testTrustedForwardedSchemeAndDirectTlsAreRecognized(): void
    {
        $context = new RequestContext(['10.0.0.0/8']);

        self::assertTrue($context->isHttps(['REMOTE_ADDR' => '10.0.0.4', 'HTTP_X_FORWARDED_PROTO' => 'https']));
        self::assertFalse($context->isHttps(['REMOTE_ADDR' => '10.0.0.4', 'HTTP_X_FORWARDED_PROTO' => 'http']));
        self::assertTrue($context->isHttps(['REMOTE_ADDR' => '198.51.100.20', 'HTTPS' => 'on']));
        self::assertTrue($context->isHttps(['REMOTE_ADDR' => '198.51.100.20', 'SERVER_PORT' => '443']));
    }

    public function testForcedHttpsUsesOnlyTheConfiguredCanonicalOrigin(): void
    {
        $context = new RequestContext([], true, 'https://dashboard.example.test/jellydash');
        $url = $context->httpsRedirectUrl([
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_HOST' => 'attacker.invalid',
            'REQUEST_URI' => '/history?range=week',
        ]);

        self::assertSame('https://dashboard.example.test/jellydash/history?range=week', $url);
        self::assertNull($context->httpsRedirectUrl(['REMOTE_ADDR' => '198.51.100.20', 'HTTPS' => 'on']));
    }

    public function testForceHttpsRequiresAnHttpsApplicationUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestContext([], true, 'http://dashboard.example.test');
    }

    public function testForcedHttpsPreservesIpv6HostBracketsAndPort(): void
    {
        $context = new RequestContext([], true, 'https://[2001:db8::1]:8443');

        self::assertSame('https://[2001:db8::1]:8443/history?range=all', $context->httpsRedirectUrl([
            'REQUEST_URI' => '/history?range=all',
        ]));
    }

    public function testForceHttpsRejectsCredentialsAndFragmentsInApplicationUrl(): void
    {
        foreach (['https://user@dashboard.example.test', 'https://dashboard.example.test/#fragment'] as $url) {
            try {
                new RequestContext([], true, $url);
                self::fail('Unsafe APP_URL should be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
