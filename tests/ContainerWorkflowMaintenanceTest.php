<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerWorkflowMaintenanceTest extends TestCase
{
    public function testRuntimeAndComposerImagesUseVerifiedImmutablePins(): void
    {
        $dockerfile = (string) file_get_contents(ROOT_DIR . '/Dockerfile');

        self::assertStringContainsString(
            'FROM php:8.3.32-apache-bookworm@sha256:ff23b916a51fb99b2a2afddb8649d1b96e15337f6b15fb0ce5179a950c00aae2',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY --from=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332',
            $dockerfile,
        );
        foreach (['curl', 'mbstring', 'mysqli', 'gmp', 'sqlite3', 'openssl'] as $extension) {
            self::assertStringContainsString("extension_loaded('{$extension}')", $dockerfile);
        }
    }

    public function testHealthcheckOnlyProbesLocalApacheAndPhpReadiness(): void
    {
        $dockerfile = (string) file_get_contents(ROOT_DIR . '/Dockerfile');
        $health = (string) file_get_contents(ROOT_DIR . '/public/healthz.php');

        self::assertStringContainsString('HEALTHCHECK', $dockerfile);
        self::assertStringContainsString('http://127.0.0.1/healthz.php', $dockerfile);
        self::assertStringContainsString('http_response_code(204)', $health);
        self::assertStringNotContainsString('vendor/autoload.php', $health);
        self::assertStringNotContainsString('Database', $health);
        self::assertStringNotContainsString('Jellyfin', $health);
    }

    public function testUploadsDenyScriptsFromValidApacheConfiguration(): void
    {
        $apache = (string) file_get_contents(ROOT_DIR . '/docker/apache/000-default.conf');
        $uploads = (string) file_get_contents(ROOT_DIR . '/public/uploads/.htaccess');

        self::assertStringContainsString('<Directory /var/www/html/public/uploads>', $apache);
        self::assertStringContainsString('php_admin_flag engine off', $apache);
        self::assertStringContainsString('<FilesMatch', $apache);
        self::assertStringContainsString('Require all denied', $apache);
        self::assertStringNotContainsString('php_admin_flag', $uploads);
        self::assertStringContainsString('Require all denied', $uploads);
    }

    public function testActionsAreImmutableAndHaveDependabotUpdates(): void
    {
        $workflows = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            glob(ROOT_DIR . '/.github/workflows/*.yml') ?: [],
        ));
        preg_match_all('/^\s*uses:\s*[^\s]+@([^\s#]+)(?:\s+#\s+v?[^\s]+)?\s*$/m', $workflows, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $reference) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $reference);
        }
        foreach ([
            'actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0',
            'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2',
            'docker/setup-qemu-action@c7c53464625b32c7a7e944ae62b3e17d2b600130 # v3.7.0',
            'docker/setup-buildx-action@8d2750c68a42422c14e847fe6c8ac0403b4cbd6f # v3.12.0',
            'docker/login-action@c94ce9fb468520275223c153574b00df6fe4bcc9 # v3.7.0',
            'docker/metadata-action@c299e40c65443455700f0fdfc63efafe5b349051 # v5.10.0',
            'docker/build-push-action@10e90e3645eae34f1e60eeb005ba3a3d33f178e8 # v6.19.2',
        ] as $pin) {
            self::assertStringContainsString($pin, $workflows);
        }
        self::assertStringContainsString('packages: write', $workflows);

        $dependabot = (string) file_get_contents(ROOT_DIR . '/.github/dependabot.yml');
        self::assertStringContainsString('package-ecosystem: "github-actions"', $dependabot);
        self::assertStringContainsString('package-ecosystem: "docker"', $dependabot);
    }

    public function testDockerIntegrationChecksHealthAndUploadServingBoundary(): void
    {
        $workflow = (string) file_get_contents(ROOT_DIR . '/.github/workflows/ci.yml');

        self::assertStringContainsString('test -f /var/www/html/public/healthz.php', $workflow);
        self::assertStringContainsString('test -f /var/www/html/modules/.gitkeep', $workflow);
        self::assertStringContainsString('test ! -e /var/www/html/internal', $workflow);
        self::assertStringContainsString('test ! -e /var/www/html/tests', $workflow);
        self::assertStringContainsString('--entrypoint apache2ctl jellydash:ci -t', $workflow);
        self::assertStringContainsString('.State.Health.Status', $workflow);
        self::assertStringContainsString('/uploads/images/health-check.png', $workflow);
        self::assertStringContainsString('/uploads/blocked.php', $workflow);
        self::assertStringContainsString('= "403"', $workflow);
    }

    public function testComposePreservesEveryDocumentedCoreVariableAndModuleOverrides(): void
    {
        $environment = (string) file_get_contents(ROOT_DIR . '/.env.example');
        $mariaDb = (string) file_get_contents(ROOT_DIR . '/docker-compose.yml');
        $sqlite = (string) file_get_contents(ROOT_DIR . '/docker-compose.sqlite.yml');
        $moduleDocs = (string) file_get_contents(ROOT_DIR . '/docs/MODULES.md');
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $environment, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $variable) {
            self::assertStringContainsString($variable, $mariaDb, $variable . ' must reach the app container.');
        }
        self::assertStringContainsString("env_file:\n      - .env", $sqlite);
        self::assertStringContainsString('DB_DRIVER: sqlite3', $sqlite);
        self::assertStringContainsString('pass them through in your compose override', $moduleDocs);
    }
}
