<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Health\WorkerMonitor;
use Mk\Framework\Health\WorkerStatusRepository;
use PHPUnit\Framework\TestCase;

final class WorkerStatusRepositoryTest extends TestCase
{
    private Database $database;
    private WorkerStatusRepository $repository;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';

    protected function setUp(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI) && !extension_loaded('sqlite3')) {
            $this->markTestSkipped('The sqlite3 extension is not available.');
        }
        $this->database = $this->isolatedDatabase();
        $this->repository = new WorkerStatusRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^jellydash_phpunit_worker_status_[a-z0-9_]+$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
    }

    public function testRecordsSuccessFailureAndRecoveryWithoutExposingClaimState(): void
    {
        $this->assertSame([], $this->repository->all());

        $first = $this->repository->start('history', now: 100);
        $this->repository->succeed('history', $first, now: 110);
        $this->repository->fail('history', $this->repository->start('history', now: 120), 'not-safe', now: 130);

        $failed = $this->repository->all()['history'];
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('request_failed', $failed['error_code']);
        $this->assertSame(110, $failed['last_success_at']);
        $this->assertArrayNotHasKey('attempt_token', $failed);
        $this->assertArrayNotHasKey('attempt_sequence', $failed);

        $this->repository->succeed('history', $this->repository->start('history', now: 140), now: 150);
        $recovered = $this->repository->all()['history'];
        $this->assertSame('success', $recovered['status']);
        $this->assertNull($recovered['error_code']);
        $this->assertSame(150, $recovered['last_success_at']);
    }

    public function testNewestSameSecondAttemptWinsAndStaleCompletionDoesNothing(): void
    {
        $older = $this->repository->start('jellyseerr', now: 200);
        $newer = $this->repository->start('jellyseerr', now: 200);
        $this->assertNotSame($older, $newer);

        $this->repository->fail('jellyseerr', $older, now: 210);
        $this->assertSame('running', $this->repository->all()['jellyseerr']['status']);

        $this->repository->succeed('jellyseerr', $newer, now: 220);
        $this->repository->fail('jellyseerr', $older, now: 230);
        $row = $this->repository->all()['jellyseerr'];
        $this->assertSame('success', $row['status']);
        $this->assertSame(220, $row['last_finished_at']);

        $future = $this->repository->start('libraries', now: 400);
        $outOfOrder = $this->repository->start('libraries', now: 300);
        $this->repository->fail('libraries', $outOfOrder, now: 500);
        $this->repository->succeed('libraries', $future, now: 410);
        $this->assertSame('success', $this->repository->all()['libraries']['status']);
    }

    public function testSourcesAreIndependent(): void
    {
        $this->repository->succeed('libraries', $this->repository->start('libraries', 'second', 10), 'second', 11);
        $this->assertSame([], $this->repository->all());
        $this->assertSame('success', $this->repository->all('second')['libraries']['status']);
    }

    public function testMonitorPreservesWorkerResultAndOriginalFailureWhenDiagnosticsBreak(): void
    {
        $monitor = new WorkerMonitor($this->repository);
        $this->database->getDibi()->query('DROP TABLE `system_status`');
        $this->assertSame(42, $monitor->run('history', static fn (): int => 42));

        $original = new RuntimeException('worker failed');
        try {
            $monitor->run('history', static function () use ($original): never {
                throw $original;
            });
            $this->fail('The worker exception was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($original, $caught);
        }
    }

    public function testMonitorConstructionDoesNotOpenDatabaseAndFactoryFailureIsIsolated(): void
    {
        $monitor = new WorkerMonitor(repositoryFactory: static function (): WorkerStatusRepository {
            throw new RuntimeException('diagnostics unavailable');
        });

        $this->assertSame('ran', $monitor->run('history', static fn (): string => 'ran'));
    }

    public function testObserveTreatsOnlyFalseAsAReportedFailure(): void
    {
        $monitor = new WorkerMonitor($this->repository);
        $this->assertFalse($monitor->observe('playback_delivery', static fn (): bool => false));
        $this->assertSame('delivery_failed', $this->repository->all()['playback_delivery']['error_code']);
        $this->assertSame(0, $monitor->observe('request_delivery', static fn (): int => 0));
        $this->assertSame('success', $this->repository->all()['request_delivery']['status']);
    }

    private function isolatedDatabase(): Database
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            return Database::sqlite(':memory:');
        }

        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
        ];
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }
        $this->admin = new \Dibi\Connection($config);
        $this->databaseName = 'jellydash_phpunit_worker_status_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
        $config['database'] = $this->databaseName;

        return new Database(new \Dibi\Connection($config));
    }
}
