<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RequestBootstrapTest extends TestCase
{
    public function testPagesAndDirectApisRedirectBeforeStartingASession(): void
    {
        foreach (['public/index.php', 'public/api/history-export.php', 'public/api/push/subscribe.php'] as $entrypoint) {
            $this->assertBootstrapResponse($entrypoint, [], 308);
        }
    }

    public function testInvalidHttpsConfigurationStopsPagesAndApisBeforeSessionStartup(): void
    {
        foreach (['public/index.php', 'public/api/history-export.php'] as $entrypoint) {
            foreach ([['APP_URL' => 'http://dashboard.example.test'], ['TRUSTED_PROXIES' => 'invalid-proxy']] as $overrides) {
                $this->assertBootstrapResponse($entrypoint, $overrides, 503);
            }
        }
    }

    /** @param array<string, string> $overrides */
    private function assertBootstrapResponse(string $entrypoint, array $overrides, int $expectedStatus): void
    {
        $environment = array_replace(getenv(), [
            'FORCE_HTTPS' => 'true',
            'APP_URL' => 'https://dashboard.example.test',
            'TRUSTED_PROXIES' => '',
            'APP_DEBUG' => 'false',
            'DB_DRIVER' => 'sqlite3',
            'DB_NAME' => ':memory:',
            'AUTH_ENABLED' => 'false',
        ], $overrides);
        $code = <<<'PHP'
            $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
            $_SERVER['REQUEST_URI'] = '/history?range=all';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['SERVER_PORT'] = '80';
            $_SERVER['HTTPS'] = 'off';
            register_shutdown_function(static function (): void {
                echo "\nPROBE:", json_encode([
                    'status' => http_response_code(),
                    'session' => session_status(),
                ], JSON_THROW_ON_ERROR);
            });
            require $argv[1];
            echo 'ENTRYPOINT_CONTINUED';
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $entrypoint],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertStringNotContainsString('ENTRYPOINT_CONTINUED', $output);
        $parts = explode("\nPROBE:", $output);
        self::assertCount(2, $parts, $output);
        $result = json_decode($parts[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expectedStatus, $result['status'], $entrypoint);
        self::assertSame(PHP_SESSION_NONE, $result['session'], $entrypoint);
        self::assertSame($expectedStatus === 503
            ? 'Jellydash request configuration is invalid. Check the server configuration.'
            : '', $parts[0]);
    }
}
