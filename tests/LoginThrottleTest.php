<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\LoginThrottle;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private string $path;
    private Database $database;
    private \Dibi\Connection $dibi;
    private int $now = 1_800_000_000;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-throttle-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->database = Database::sqlite($this->path);
        $this->database->ensureAuthSchema();
        $this->dibi = $this->database->getDibi();
        $this->throttle = new LoginThrottle($this->database, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        unset($this->throttle, $this->dibi, $this->database);
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $path) {
            @unlink($path);
        }
    }

    public function testPairLocksOnTheFifthFailureAndExpiresAsOneWindow(): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->throttle->failure('viewer', '203.0.113.10');
            self::assertFalse($this->throttle->blocked('viewer', '203.0.113.10'));
        }
        $this->throttle->failure('viewer', '203.0.113.10');
        self::assertTrue($this->throttle->blocked('viewer', '203.0.113.10'));

        $this->now += 901;
        self::assertFalse($this->throttle->blocked('viewer', '203.0.113.10'));
        $this->throttle->failure('viewer', '203.0.113.10');
        $row = $this->row(LoginThrottle::pairIdentifier('viewer', '203.0.113.10'));
        self::assertSame(1, (int) $row['attempts']);
        self::assertNull($row['locked_until']);
    }

    public function testSourceBudgetStopsUsernameSprayingWithoutLockingOtherSources(): void
    {
        for ($attempt = 1; $attempt <= 30; ++$attempt) {
            $this->throttle->failure('viewer-' . $attempt, '203.0.113.20');
        }

        self::assertTrue($this->throttle->blocked('new-viewer', '203.0.113.20'));
        self::assertFalse($this->throttle->blocked('new-viewer', '203.0.113.21'));
    }

    public function testActiveLegacyPairLockRemainsEffective(): void
    {
        $this->dibi->insert('login_attempts', [
            'identifier' => 'legacy-user|203.0.113.30',
            'attempts' => 5,
            'locked_until' => $this->storedDate($this->now + 60),
            'updated_at' => $this->storedDate($this->now - 60),
        ])->execute();

        self::assertTrue($this->throttle->blocked('legacy-user', '203.0.113.30'));
        $this->throttle->clearPair('legacy-user', '203.0.113.30');
        self::assertFalse($this->throttle->blocked('legacy-user', '203.0.113.30'));
    }

    public function testExpiredRowsArePrunedInBoundedBatches(): void
    {
        foreach (range(1, 3) as $index) {
            $this->dibi->insert('login_attempts', [
                'identifier' => 'expired:' . $index,
                'attempts' => 1,
                'locked_until' => null,
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now - 90_000 - $index),
            ])->execute();
        }

        self::assertSame(2, $this->throttle->pruneExpired(2));
        self::assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('login_attempts')->fetchSingle());
    }

    public function testAdmissionAccountsForPendingAndFinishedAttemptsAcrossConnections(): void
    {
        $sqlite = \Mk\Framework\DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI);
        $firstDb = $sqlite ? Database::sqlite($this->path) : new Database();
        $secondDb = $sqlite ? Database::sqlite($this->path) : new Database();
        $first = new LoginThrottle($firstDb, fn (): int => $this->now);
        $second = new LoginThrottle($secondDb, fn (): int => $this->now);
        $ip = '2001:db8::' . bin2hex(random_bytes(2)) . ':' . bin2hex(random_bytes(2));
        $otherIp = '2001:db8:1::' . bin2hex(random_bytes(2));
        $tokens = [];
        for ($i = 0; $i < 5; ++$i) {
            $tokens[] = $first->admit('viewer', $ip);
            self::assertNotNull($tokens[$i]);
        }
        self::assertNull($second->admit('viewer', $ip));
        self::assertFalse($second->finish('different-user', $ip, $tokens[0], true));
        for ($i = 0; $i < 24; ++$i) {
            $user = 'other-' . $i;
            $token = $second->admit($user, $ip);
            self::assertNotNull($token);
            self::assertTrue($second->finish($user, $ip, $token, false));
        }
        $last = $second->admit('last', $ip);
        self::assertNotNull($last);
        self::assertNull($first->admit('next', $ip));
        self::assertTrue($second->finish('viewer', $ip, $tokens[0], true));
        self::assertFalse($second->finish('viewer', $ip, $tokens[0], false));
        $replacement = $second->admit('viewer', $ip);
        self::assertNotNull($replacement);
        self::assertNull($first->admit('next', $ip));
        foreach ([...array_slice($tokens, 1), $replacement] as $token) {
            self::assertTrue($second->finish('viewer', $ip, $token, false));
        }
        self::assertNull($first->admit('viewer', $ip));
        self::assertFalse($first->blocked('next', $ip));
        self::assertTrue($first->finish('last', $ip, $last, false));
        self::assertTrue($second->blocked('next', $ip));

        $abandoned = $first->admit('viewer', $otherIp);
        self::assertNotNull($abandoned);
        $this->now += 900;
        self::assertFalse($second->finish('viewer', $otherIp, $abandoned, false));
        self::assertNotNull($second->admit('viewer', $otherIp));
        self::assertNotNull($first->admit('viewer', $ip));
        $firstDb->getDibi()->disconnect();
        $secondDb->getDibi()->disconnect();
    }

    /** @return array<string, mixed> */
    private function row(string $identifier): array
    {
        $row = $this->dibi->select('*')->from('login_attempts')->where('identifier = %s', $identifier)->fetch();
        self::assertNotFalse($row);

        return $row->toArray();
    }

    private function storedDate(int $epoch): string
    {
        return (new \DateTimeImmutable('@' . $epoch))
            ->setTimezone(new \DateTimeZone(\Mk\Framework\Config::timezone()))
            ->format('Y-m-d H:i:s');
    }
}
