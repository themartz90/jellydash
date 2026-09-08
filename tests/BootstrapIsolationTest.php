<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapIsolationTest extends TestCase
{
    public function testNoDatabaseOverrideCreatesAnIsolatedSqliteDatabase(): void
    {
        $environment = $this->environmentWithoutDatabaseOverride();
        $payload = $this->runProbe($environment);

        $this->assertSame('sqlite3', $payload['driver']);
        $this->assertStringStartsWith('jellydash-phpunit-', basename((string) $payload['name']));
        $this->assertSame('', $payload['jellyfin']);
        $this->assertSame('', $payload['jellyseerr']);
    }

    public function testExplicitDatabaseOverrideIsPreserved(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-explicit-' . bin2hex(random_bytes(8)) . '.sqlite';
        $environment = $this->environmentWithoutDatabaseOverride();
        $environment['DB_DRIVER'] = 'sqlite3';
        $environment['DB_NAME'] = $path;

        $payload = $this->runProbe($environment);

        $this->assertSame('sqlite3', $payload['driver']);
        $this->assertSame($path, $payload['name']);
    }

    public function testEmptyDatabaseOverrideUsesAnIsolatedSqliteDatabase(): void
    {
        $environment = $this->environmentWithoutDatabaseOverride();
        $environment['DB_DRIVER'] = '';
        $environment['DB_NAME'] = '';

        $payload = $this->runProbe($environment);

        $this->assertSame('sqlite3', $payload['driver']);
        $this->assertStringStartsWith('jellydash-phpunit-', basename((string) $payload['name']));
    }

    public function testGeneratedDatabaseIsRemovedAfterARealConnectionCloses(): void
    {
        $environment = $this->environmentWithoutDatabaseOverride();
        $environment['JELLYDASH_TEST_PROBE_CREATE_DB'] = '1';

        $payload = $this->runProbe($environment);
        $path = (string) $payload['name'];

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path . '-wal');
        $this->assertFileDoesNotExist($path . '-shm');
    }

    public function testChildProcessInheritsTheCurrentTestDatabase(): void
    {
        $environment = getenv();

        $payload = $this->runProbe($environment);

        $this->assertSame(DATABASE_DRIVER_DIBI, $payload['driver']);
        $this->assertSame(DATABASE_NAME, $payload['name']);
    }

    /** @return array<string, string> */
    private function environmentWithoutDatabaseOverride(): array
    {
        $environment = getenv();
        foreach (['DB_DRIVER', 'DB_NAME', 'JELLYDASH_TEST_DB_OWNER_PID'] as $key) {
            unset($environment[$key]);
        }

        return $environment;
    }

    /** @param array<string, string> $environment @return array<string, mixed> */
    private function runProbe(array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/bootstrap-environment-probe.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $this->assertSame(0, $exitCode, (string) $error);

        $payload = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
