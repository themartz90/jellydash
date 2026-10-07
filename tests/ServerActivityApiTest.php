<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServerActivityApiTest extends TestCase
{
    public function testLiveCapabilityOverridesTheRoleInTheSession(): void
    {
        foreach ([1 => 422, 2 => 422, 3 => 403, 4 => 403, 0 => 401] as $role => $status) {
            $result = $this->request($role, 'GET', ['section' => 'invalid']);
            self::assertSame($status, $result['status']);
            self::assertIsString($result['body']['error']);
            self::assertArrayNotHasKey('data', $result['body']);
        }
    }

    public function testEndpointMethodValidationAndUnconfiguredStates(): void
    {
        self::assertSame(405, $this->request(1, 'POST', [])['status']);
        foreach ([['section' => ['overview']], ['section' => 'activity', 'range' => 'invalid'], ['section' => 'activity', 'page' => 2]] as $query) {
            self::assertSame(422, $this->request(1, 'GET', $query)['status']);
        }
        $overview = $this->request(1, 'GET', []);
        self::assertSame(200, $overview['status']);
        self::assertSame('unconfigured', $overview['body']['server']['state']);
        self::assertNull($overview['body']['server']['data']);
        self::assertSame('unconfigured', $overview['body']['tasks']['state']);
    }

    /** @param array<string, mixed> $query @return array{status: int, body: array<string, mixed>} */
    private function request(int $role, string $method, array $query): array
    {
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $db = Mk\Framework\Database::sqlite(':memory:');
            $db->ensureAuthSchema();
            Mk\Framework\Container::set('db', $db);
            $sessionFile = null;
            if ((int) $argv[2] !== 0) {
                $id = $db->addAuthUser('activity-api', 'test-password-123', 'Tester', (int) $argv[2]);
                $sessionId = 'activitytest' . bin2hex(random_bytes(12));
                $directory = $argv[1] . '/var/sessions';
                if (!is_dir($directory)) { mkdir($directory, 0770, true); }
                $sessionFile = $directory . '/sess_' . $sessionId;
                file_put_contents($sessionFile, 'auth_user|' . serialize(['id' => $id, 'username' => 'activity-api', 'name' => 'Tester', 'role' => 1]) . 'auth_login_time|' . serialize(time()) . 'auth_last_activity|' . serialize(time()));
                $_COOKIE['jellydash_session'] = $sessionId;
            }
            $_SERVER['REQUEST_METHOD'] = $argv[3];
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['REQUEST_URI'] = '/api/server-activity.php';
            $_SERVER['SERVER_PORT'] = '80';
            $_GET = json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR);
            http_response_code(200);
            register_shutdown_function(static function () use ($sessionFile): void {
                echo "\nPROBE:", http_response_code();
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                if ($sessionFile !== null && is_file($sessionFile)) { unlink($sessionFile); }
            });
            require $argv[1] . '/public/api/server-activity.php';
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, ROOT_DIR, (string) $role, $method, json_encode($query, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            array_replace(getenv(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'true', 'AUTH_ENABLED' => 'true', 'FORCE_HTTPS' => 'false',
                'DB_DRIVER' => 'sqlite3', 'DB_NAME' => ':memory:', 'JELLYFIN_URL' => 'unconfigured-fixture',
                'JELLYFIN_API_TOKEN' => 'fixture-only-token', 'JELLYFIN_API_KEY' => 'fixture-only-token', 'TRUSTED_PROXIES' => ' ',
            ]),
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);
        [$body, $status] = explode("\nPROBE:", $output, 2);
        return ['status' => (int) $status, 'body' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
}
