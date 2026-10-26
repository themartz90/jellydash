<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DockerSqliteSetupTest extends TestCase
{
    public function testImageAndEntrypointPrepareSQLiteSupport(): void
    {
        $dockerfile = file_get_contents(ROOT_DIR . '/Dockerfile');
        $entrypoint = file_get_contents(ROOT_DIR . '/docker/entrypoint.sh');

        $this->assertIsString($dockerfile);
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString("extension_loaded('sqlite3')", $dockerfile);
        $this->assertStringNotContainsString('libsqlite3-dev', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^\s+sqlite3\s+\\\\$/m', $dockerfile);
        $this->assertStringContainsString('/var/www/html/var/data', $entrypoint);
        $this->assertStringContainsString('database:migrate-to-sqlite', $entrypoint);
        $this->assertStringContainsString('gosu www-data php', $entrypoint);

        $workflow = file_get_contents(ROOT_DIR . '/.github/workflows/ci.yml');
        $this->assertIsString($workflow);
        $this->assertStringNotContainsString('--env POLLER_ENABLED=false', $workflow);
        $this->assertStringContainsString('--env TZ=America/New_York', $workflow);
        $this->assertStringContainsString('Config::timezone()', $workflow);
        $this->assertStringContainsString('login_attempts', $workflow);
        $this->assertStringContainsString('remember_me=1', $workflow);
        $this->assertStringContainsString('jellydash_remember', $workflow);
        $this->assertStringContainsString('auth_remember_tokens', $workflow);
    }

    public function testSQLiteComposeSetupIsSeparateAndPersistent(): void
    {
        $compose = file_get_contents(ROOT_DIR . '/docker-compose.sqlite.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('DB_DRIVER: sqlite3', $compose);
        $this->assertStringContainsString('DB_NAME: /var/www/html/var/data/jellydash.sqlite', $compose);
        $this->assertStringContainsString('./sqlite-data:/var/www/html/var/data', $compose);
        $this->assertStringNotContainsString("\n  db:", $compose);
        $this->assertStringNotContainsString('depends_on:', $compose);
    }

    public function testBothComposeSetupsPersistOrdinarySessions(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.sqlite.yml'] as $path) {
            $compose = (string) file_get_contents(ROOT_DIR . '/' . $path);
            $this->assertStringContainsString('app_sessions:/var/www/html/var/sessions', $compose, $path);
            $this->assertMatchesRegularExpression('/^  app_sessions:\s*$/m', $compose, $path);
        }
        $settings = (string) file_get_contents(ROOT_DIR . '/utils/@settings.php');
        $this->assertStringContainsString("ROOT_DIR . '/var/sessions'", $settings);
    }

    public function testPendingHistorySamplesUseTheExistingPersistentRuntimeVolume(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.sqlite.yml'] as $path) {
            $compose = (string) file_get_contents(ROOT_DIR . '/' . $path);
            $this->assertStringContainsString('app_runtime_cache:/var/www/html/var/cache', $compose);
        }
        $this->assertStringContainsString('HISTORY_SAMPLE_QUEUE_DIR: "${HISTORY_SAMPLE_QUEUE_DIR:-/var/www/html/var/cache/history-samples}"', (string) file_get_contents(ROOT_DIR . '/docker-compose.yml'));
    }

    public function testMainComposeForwardsBothSupportedTimezoneVariables(): void
    {
        $compose = file_get_contents(ROOT_DIR . '/docker-compose.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('APP_TIMEZONE: "${APP_TIMEZONE:-}"', $compose);
        $this->assertStringContainsString('TZ: "${TZ:-}"', $compose);
    }

    public function testSelfHostedNotificationSettingsReachBothComposeSetups(): void
    {
        $compose = file_get_contents(ROOT_DIR . '/docker-compose.yml');
        $sqlite = file_get_contents(ROOT_DIR . '/docker-compose.sqlite.yml');
        $example = file_get_contents(ROOT_DIR . '/.env.example');
        self::assertMatchesRegularExpression('/env_file:\s*\n\s*- \.env/', $sqlite);
        foreach (['NTFY_URL', 'NTFY_TOPIC', 'NTFY_TOKEN', 'GOTIFY_URL', 'GOTIFY_APP_TOKEN'] as $key) {
            self::assertStringContainsString($key . ': "${' . $key . ':-}"', $compose);
            self::assertStringContainsString($key . '=', $example);
            self::assertStringNotContainsString($key . ':', $sqlite, 'SQLite must not shadow its env_file settings.');
        }
    }

    public function testReadmeMakesTheSelectedComposeSetupTheDefault(): void
    {
        $readme = file_get_contents(ROOT_DIR . '/README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString(
            'curl -L -o docker-compose.yml https://raw.githubusercontent.com/themartz90/jellydash/main/docker-compose.sqlite.yml',
            $readme,
        );
        $this->assertStringContainsString('For both MariaDB and SQLite:', $readme);
        $this->assertStringContainsString('docker compose pull && docker compose up -d', $readme);
        $this->assertStringContainsString('cp -n docker-compose.yml docker-compose.mariadb.yml', $readme);
        $this->assertStringContainsString('MariaDB Compose backup verified', $readme);
        $this->assertStringContainsString('cp docker-compose.sqlite.yml docker-compose.yml', $readme);
        $this->assertStringContainsString('cp docker-compose.mariadb.yml docker-compose.yml', $readme);
        $this->assertStringNotContainsString('docker compose -f docker-compose.sqlite.yml pull', $readme);
        $this->assertStringNotContainsString('docker compose -f docker-compose.sqlite.yml up -d', $readme);
    }

    public function testWebAndConsoleUseTheSharedTimezoneResolver(): void
    {
        $webSettings = file_get_contents(ROOT_DIR . '/utils/@settings.php');
        $console = file_get_contents(ROOT_DIR . '/bin/console.php');

        $this->assertIsString($webSettings);
        $this->assertIsString($console);
        $this->assertStringContainsString('Config::timezone()', $webSettings);
        $this->assertStringContainsString('Config::timezone()', $console);
        $this->assertStringNotContainsString("Config::get('APP_TIMEZONE'", $webSettings);
        $this->assertStringNotContainsString("Config::get('APP_TIMEZONE'", $console);
    }
}
