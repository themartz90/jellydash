<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\RememberTokenRepository;
use PHPUnit\Framework\TestCase;

final class RememberTokenRepositoryTest extends TestCase
{
    private const USERNAME = 'phpunit_remember_user';
    private const NOW = 1_788_192_000;

    private Database $database;
    private \Dibi\Connection $dibi;
    private RememberTokenRepository $tokens;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->database = Container::db();
            $this->database->ensureAuthSchema();
            $this->dibi = $this->database->getDibi();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $this->cleanup();
        $this->userId = $this->database->addAuthUser(
            self::USERNAME,
            'remember-password-123',
            'Remember Tester',
            Authorization::ROLE_ADMIN,
        );
        $this->tokens = new RememberTokenRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
    }

    public function testIssueStoresOnlyTheValidatorHash(): void
    {
        $token = $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);
        [$selector, $validator] = explode('.', $token, 2);
        $row = $this->dibi->select('selector, validator_hash')->from('auth_remember_tokens')
            ->where('user_id = %i', $this->userId)->fetch();

        $this->assertNotFalse($row);
        $this->assertSame($selector, (string) $row['selector']);
        $this->assertNotSame($validator, (string) $row['validator_hash']);
        $this->assertSame(hash('sha256', $validator), (string) $row['validator_hash']);
    }

    public function testConsumeRestoresTheUserAndRotatesTheValidator(): void
    {
        $token = $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);
        $remembered = $this->tokens->consume($token, self::NOW + 60, Authorization::REMEMBER_LIFETIME);

        $this->assertNotNull($remembered);
        $this->assertSame(self::USERNAME, $remembered['user']['username']);
        $this->assertNotSame($token, $remembered['token']);
        $this->assertNull($this->tokens->consume($token, self::NOW + 120, Authorization::REMEMBER_LIFETIME));
        $this->assertSame(0, $this->rememberTokenCount());
    }

    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $token = $this->tokens->issue($this->userId, self::NOW, 60);

        $this->assertNull($this->tokens->consume('not-a-token', self::NOW, 60));
        $this->assertNull($this->tokens->consume($token, self::NOW + 61, 60));
        $this->assertSame(0, $this->rememberTokenCount());
    }

    public function testConcurrentRestorationsReturnTheSameReplacement(): void
    {
        $token = $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);
        $first = $this->tokens->consume($token, self::NOW + 60, Authorization::REMEMBER_LIFETIME);
        $second = (new RememberTokenRepository($this->database))->consume($token, self::NOW + 61, Authorization::REMEMBER_LIFETIME);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first['token'], $second['token']);
        $current = $this->tokens->consume($first['token'], self::NOW + 62, Authorization::REMEMBER_LIFETIME);
        $this->assertNotNull($current);
        $this->assertSame($first['token'], $current['token']);
        $later = $this->tokens->consume($second['token'], self::NOW + 80, Authorization::REMEMBER_LIFETIME);
        $this->assertNotNull($later);
        $this->assertNotSame($second['token'], $later['token']);
    }

    public function testPreviousCookieCannotExtendTheConcurrencyWindow(): void
    {
        $token = $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);
        $this->assertNotNull($this->tokens->consume($token, self::NOW + 60, Authorization::REMEMBER_LIFETIME));
        $this->assertNotNull($this->tokens->consume($token, self::NOW + 69, Authorization::REMEMBER_LIFETIME));
        $this->assertNull($this->tokens->consume($token, self::NOW + 70, Authorization::REMEMBER_LIFETIME));
        $this->assertSame(0, $this->rememberTokenCount());
    }

    public function testAnotherConsumerCanRotateBetweenReadAndUpdate(): void
    {
        $token = $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);
        $other = new RememberTokenRepository($this->database);
        $interleaved = false;
        $replacement = null;
        $listener = function (\Dibi\Event $event) use ($token, $other, &$interleaved, &$replacement): void {
            if (!$interleaved && str_contains((string) $event->sql, 'SELECT user_id, validator_hash')) {
                $interleaved = true;
                $replacement = $other->consume($token, self::NOW + 60, Authorization::REMEMBER_LIFETIME);
            }
        };
        $this->dibi->onEvent[] = $listener;
        try {
            $remembered = $this->tokens->consume($token, self::NOW + 60, Authorization::REMEMBER_LIFETIME);
        } finally {
            $this->dibi->onEvent = array_values(array_filter($this->dibi->onEvent, static fn ($callback): bool => $callback !== $listener));
        }

        $this->assertTrue($interleaved);
        $this->assertNotNull($replacement);
        $this->assertNotNull($remembered);
        $this->assertSame($replacement['token'], $remembered['token']);
        $this->assertNotNull($this->tokens->consume($remembered['token'], self::NOW + 80, Authorization::REMEMBER_LIFETIME));
    }

    public function testRevokeUserRemovesEveryRememberedDevice(): void
    {
        $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);
        $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);

        $this->tokens->revokeUser($this->userId);

        $this->assertSame(0, $this->rememberTokenCount());
    }

    public function testChangingThePasswordRevokesEveryRememberedDevice(): void
    {
        $this->tokens->issue($this->userId, self::NOW, Authorization::REMEMBER_LIFETIME);

        $this->assertTrue($this->database->setUserPassword(self::USERNAME, 'replacement-password-123'));

        $this->assertSame(0, $this->rememberTokenCount());
        $passwordHash = (string) $this->dibi->select('password')->from('users')
            ->where('id = %i', $this->userId)->fetchSingle();
        $this->assertTrue(password_verify('replacement-password-123', $passwordHash));
    }

    private function cleanup(): void
    {
        $userId = $this->dibi->select('id')->from('users')->where('username = %s', self::USERNAME)->fetchSingle();
        if ($userId !== null && $userId !== false) {
            $this->dibi->delete('auth_remember_tokens')->where('user_id = %i', (int) $userId)->execute();
        }
        $this->dibi->delete('users')->where('username = %s', self::USERNAME)->execute();
    }

    private function rememberTokenCount(): int
    {
        return (int) $this->dibi->select('COUNT(*)')->from('auth_remember_tokens')
            ->where('user_id = %i', $this->userId)->fetchSingle();
    }
}
