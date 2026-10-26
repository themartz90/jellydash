<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthRequestGateTest extends TestCase
{
    public function testInvalidAuthenticationConfigurationStopsPageAndDirectApiStartup(): void
    {
        foreach (['public/index.php', 'public/api/server-stats.php'] as $entry) {
            $result = $this->runIsolated(
                'require ' . var_export(ROOT_DIR . '/vendor/autoload.php', true) . ';'
                . '\Mk\Framework\Container::set("logger", new \Psr\Log\NullLogger());'
                . 'register_shutdown_function(static function (): void { echo "|status=" . http_response_code(); });'
                . 'require ' . var_export(ROOT_DIR . '/' . $entry, true) . '; echo "protected-reached";',
                'invalid-auth-token',
            );
            $this->assertStringContainsString('configuration is invalid', $result, $entry);
            $this->assertStringContainsString('|status=503', $result, $entry);
            $this->assertStringNotContainsString('invalid-auth-token', $result, $entry);
            $this->assertStringNotContainsString('protected-reached', $result, $entry);
        }
    }

    public function testPasswordResetAndDeletedAccountsFailPageAndReadApiGates(): void
    {
        foreach (['reset', 'delete'] as $mutation) {
            foreach (['page', 'api'] as $gate) {
                $script = 'require ' . var_export(ROOT_DIR . '/tests/bootstrap.php', true) . ';'
                    . '$db = \Mk\Framework\Database::sqlite(":memory:"); $db->ensureAuthSchema();'
                    . '\Mk\Framework\Container::set("db", $db);'
                    . '$id = $db->addAuthUser("gate-user", "gate-password", "Gate", 2);'
                    . '$_SESSION = []; $_COOKIE = [];'
                    . '$auth = new \Mk\Framework\Authorization($db); $auth->userLogin("gate-user", "gate-password");'
                    . ($mutation === 'reset'
                        ? '$db->setUserPassword("gate-user", "replacement-password");'
                        : '$db->getDibi()->delete("users")->where("id = %i", $id)->execute();')
                    . 'register_shutdown_function(static function (): void { echo "|status=" . http_response_code(); });'
                    . ($gate === 'api'
                        ? 'require ' . var_export(ROOT_DIR . '/utils/@api-guard.php', true) . ';'
                        : '$view = (new \ReflectionClass(\Mk\Framework\View::class))->newInstanceWithoutConstructor();'
                            . '(new \Mk\Framework\Router($view))->dispatch("now-playing", null);')
                    . 'echo "protected-reached";';
                $result = $this->runIsolated($script, 'true');
                $this->assertStringContainsString('|status=' . ($gate === 'api' ? '401' : '302'), $result);
                $this->assertStringNotContainsString('protected-reached', $result);
            }
        }
    }

    public function testAuthenticationDisabledReadApiRemainsAvailable(): void
    {
        $result = $this->runIsolated(
            'require ' . var_export(ROOT_DIR . '/tests/bootstrap.php', true) . ';'
            . 'require ' . var_export(ROOT_DIR . '/utils/@api-guard.php', true) . '; echo "protected-reached";',
            'false',
        );
        $this->assertSame('protected-reached', $result);
    }

    public function testLogoutRequestClearsLocalAuthenticationWhenDatabaseStartupFails(): void
    {
        $script = 'require ' . var_export(ROOT_DIR . '/tests/bootstrap.php', true) . ';'
            . '\Mk\Framework\Container::set("logger", new \Psr\Log\NullLogger());'
            . '$path = sys_get_temp_dir() . "/jellydash-unavailable-auth-" . bin2hex(random_bytes(8)) . "/auth.sqlite";'
            . '$db = new \Mk\Framework\Database(new \Dibi\Connection(["driver" => "sqlite3", "database" => $path, "lazy" => true]));'
            . '\Mk\Framework\Container::set("db", $db);'
            . '$_SESSION = ["auth_user" => ["id" => 1, "auth_generation" => 0], "auth_login_time" => time(), "auth_last_activity" => time()];'
            . '$_COOKIE[\Mk\Framework\Authorization::REMEMBER_COOKIE] = "synthetic-unavailable-token";'
            . '$_GET = $_REQUEST = ["auth" => "logout"]; $_SERVER["REQUEST_METHOD"] = "POST";'
            . '$_POST = ["csrf_token" => \Mk\Framework\Csrf::token()];'
            . 'register_shutdown_function(static function (): void { echo "|authenticated=" . (int) isset($_SESSION["auth_user"]) . "|remember=" . (int) isset($_COOKIE[\Mk\Framework\Authorization::REMEMBER_COOKIE]) . "|status=" . http_response_code(); });'
            . 'require ' . var_export(ROOT_DIR . '/operations/@request.php', true) . ';';
        $result = $this->runIsolated($script, 'true');
        $this->assertSame('|authenticated=0|remember=0|status=302', $result);
    }

    private function runIsolated(string $script, string $authFlag): string
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment['AUTH_ENABLED'] = $authFlag;
        $environment['APP_DEBUG'] = 'false';
        // The harness constructs its own in-memory DB; never inherit a DB profile.
        unset($environment['DB_DRIVER'], $environment['DB_NAME']);
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR, $environment);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $this->assertSame('', $error);
        return $output;
    }
}
