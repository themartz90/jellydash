<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerWorkflowMaintenanceTest extends TestCase
{
    public function testRuntimeAndComposerImagesUseVerifiedImmutablePins(): void
    {
        $dockerfile = (string) file_get_contents(ROOT_DIR . '/Dockerfile');

        self::assertStringContainsString(
            'FROM php:8.3.33-apache-bookworm@sha256:fa8852a2e01747ffe8c8768bfd6bbc2f296f974aa0de90ee157a66664996d263',
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

    public function testActionsUseImmutablePins(): void
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
            'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1',
            'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2',
            'docker/setup-qemu-action@1f40c72289eff860ee54a304f1438e3cff362e0a # v4.3.0',
            'docker/setup-buildx-action@37fe631027851001ddb9b187196cc803df7f5f0e # v4.3.0',
            'docker/login-action@dbcb813823bdd20940b903addbd779551569679f # v4.6.0',
            'docker/metadata-action@dc802804100637a589fabce1cb79ff13a1411302 # v6.2.0',
            'docker/build-push-action@53b7df96c91f9c12dcc8a07bcb9ccacbed38856a # v7.3.0',
        ] as $pin) {
            self::assertStringContainsString($pin, $workflows);
        }
        self::assertStringContainsString('packages: write', $workflows);
    }

    public function testManualReleaseChecksCannotPublishAnImage(): void
    {
        $release = (string) file_get_contents(ROOT_DIR . '/.github/workflows/release.yml');
        $build = (string) file_get_contents(ROOT_DIR . '/.github/workflows/build-image.yml');
        $dryRun = substr($release, (int) strpos($release, '  dry-run:'));

        self::assertStringContainsString("if: github.event_name == 'push' && startsWith(github.ref, 'refs/tags/v')", $release);
        self::assertStringContainsString("if: github.event_name == 'workflow_dispatch'", $dryRun);
        self::assertStringContainsString('packages: read', $dryRun);
        self::assertStringNotContainsString('packages: write', $dryRun);
        self::assertStringContainsString('publish: false', $dryRun);
        self::assertStringNotContainsString('publish: true', $dryRun);
        self::assertSame(2, substr_count($release, 'uses: ./.github/workflows/build-image.yml'));
        self::assertStringContainsString('type: boolean', $build);
        self::assertStringContainsString('default: false', $build);
        self::assertStringContainsString('push: ${{ inputs.publish }}', $build);
        self::assertStringNotContainsString('push: true', $build);
        self::assertStringContainsString('type=oci,dest=', $build);
        self::assertStringContainsString('platforms: linux/amd64,linux/arm64', $build);
        self::assertStringContainsString("required = {'linux/amd64', 'linux/arm64'}", $build);
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
