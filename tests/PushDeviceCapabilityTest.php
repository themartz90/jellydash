<?php

declare(strict_types=1);

use Mk\Framework\Push\PushDeviceCapability;
use PHPUnit\Framework\TestCase;

final class PushDeviceCapabilityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $previousCookies;

    protected function setUp(): void
    {
        $this->previousCookies = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->previousCookies;
    }

    public function testEnrollmentIssuesPersistentHttpOnlyCapabilityAndReturnsOnlyItsHash(): void
    {
        $writes = [];
        $capability = new PushDeviceCapability(
            cookieWriter: static function (string $name, string $value, array $options) use (&$writes): bool {
                $writes[] = compact('name', 'value', 'options');

                return true;
            },
            randomBytes: static fn (int $bytes): string => str_repeat("\x01", $bytes),
            clock: static fn (): int => 1_800_000_000,
            secure: true,
        );

        $hash = $capability->hashForEnrollment();
        $token = rtrim(strtr(base64_encode(str_repeat("\x01", 32)), '+/', '-_'), '=');

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertSame($token, $_COOKIE[PushDeviceCapability::COOKIE_NAME]);
        $this->assertCount(1, $writes);
        $this->assertSame(PushDeviceCapability::COOKIE_NAME, $writes[0]['name']);
        $this->assertSame($token, $writes[0]['value']);
        $this->assertSame([
            'expires' => 1_831_536_000,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $writes[0]['options']);
    }

    public function testExistingValidCapabilityIsReusedWithoutRewritingCookie(): void
    {
        $token = rtrim(strtr(base64_encode(str_repeat("\x02", 32)), '+/', '-_'), '=');
        $_COOKIE[PushDeviceCapability::COOKIE_NAME] = $token;
        $writes = 0;
        $capability = new PushDeviceCapability(
            cookieWriter: static function () use (&$writes): bool {
                ++$writes;

                return true;
            },
        );

        $this->assertSame(hash('sha256', $token), $capability->hashForEnrollment());
        $this->assertSame(0, $writes);
    }

    public function testExistingHashDoesNotCreateACapabilityAndClearExpiresIt(): void
    {
        $writes = [];
        $capability = new PushDeviceCapability(
            cookieWriter: static function (string $name, string $value, array $options) use (&$writes): bool {
                $writes[] = compact('name', 'value', 'options');

                return true;
            },
            clock: static fn (): int => 1_800_000_000,
            secure: false,
        );

        $this->assertNull($capability->existingHash());
        $this->assertSame([], $writes);

        $token = rtrim(strtr(base64_encode(str_repeat("\x03", 32)), '+/', '-_'), '=');
        $_COOKIE[PushDeviceCapability::COOKIE_NAME] = $token;
        $this->assertSame(hash('sha256', $token), $capability->existingHash());
        $capability->clear();

        $this->assertArrayNotHasKey(PushDeviceCapability::COOKIE_NAME, $_COOKIE);
        $this->assertSame('', $writes[0]['value']);
        $this->assertSame(1_799_996_400, $writes[0]['options']['expires']);
        $this->assertTrue($writes[0]['options']['httponly']);
    }
}
