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
