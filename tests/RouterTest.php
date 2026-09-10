<?php

use Mk\Framework\Router;
use Mk\Framework\View;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the router (Phase 6 / #12). The 404 path needs no database.
 */
final class RouterTest extends TestCase
{
    private string|false $previousAppDebug;

    private bool $hadAppDebugEnv;

    private ?string $previousAppDebugEnv;

    protected function setUp(): void
    {
        $this->previousAppDebug = getenv('APP_DEBUG');
        $this->hadAppDebugEnv = array_key_exists('APP_DEBUG', $_ENV);
        $this->previousAppDebugEnv = $this->hadAppDebugEnv ? (string) $_ENV['APP_DEBUG'] : null;
        putenv('APP_DEBUG=true');
        $_ENV['APP_DEBUG'] = 'true';
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        if ($this->previousAppDebug === false) {
            putenv('APP_DEBUG');
        } else {
            putenv('APP_DEBUG=' . $this->previousAppDebug);
        }

        if ($this->hadAppDebugEnv) {
            $_ENV['APP_DEBUG'] = $this->previousAppDebugEnv;
        } else {
            unset($_ENV['APP_DEBUG']);
        }
    }

    public function testNowPlayingRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('now-playing', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Now Playing', $output);
        $this->assertStringContainsString('app-shell', $output);
        $this->assertStringContainsString('/assets/js/dashboard-scroll.js?v=20260910-scroll-restore', $output);
        $this->assertStringNotContainsString('history-import.js', $output);
        $this->assertStringNotContainsString('data-import-history-dialog', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testHistoryRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('history', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('History', $output);
        $this->assertStringContainsString('filter-bar', $output);
        $this->assertStringContainsString('href="/settings#import-history"', $output);
        $this->assertStringNotContainsString('data-import-history-dialog', $output);
        $this->assertStringNotContainsString('/assets/js/history-import.js', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testStatisticsRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('statistics', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Statistics', $output);
        $this->assertStringContainsString('stats-kpi-grid', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testLibrariesRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('libraries', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Libraries', $output);
        $this->assertStringContainsString('library-grid', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testSettingsRouteMakesExclusionsExplicit(): void
    {
        $previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';

        try {
            ob_start();
            (new Router(new View()))->dispatch('settings', null);
            $output = (string) ob_get_clean();
        } finally {
            if ($previousDebug === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $previousDebug;
            }
        }

        $this->assertStringContainsString('Show server statistics', $output);
        $this->assertStringContainsString('<strong>Exclusions</strong>', $output);
        $this->assertStringContainsString('<legend>Statistics</legend>', $output);
        $this->assertStringContainsString('Selected libraries are hidden from Trending and Most Watched.', $output);
        $this->assertStringContainsString('<legend>Notifications</legend>', $output);
        $this->assertStringContainsString('Selected users never trigger playback alerts.', $output);
        $this->assertStringContainsString('<legend>Monitoring</legend>', $output);
        $this->assertStringContainsString('name="monitoring_ignore_extra"', $output);
        $this->assertStringContainsString('/assets/js/server-stats.js?v=20260809-settings', $output);
        $this->assertStringContainsString('/assets/js/nav-count.js?v=20260908-stale-state', $output);
        $this->assertStringContainsString('data-update-status', $output);
        $this->assertStringContainsString('/assets/js/update-status.js?v=20260810-update', $output);
        $this->assertStringContainsString('data-release-changes', $output);
        $this->assertStringContainsString('data-release-dialog', $output);
        $this->assertStringContainsString('data-import-history-dialog', $output);
        $this->assertStringContainsString('id="import-history"', $output);
        $this->assertStringContainsString('/assets/js/history-library-upgrade.js?v=20260822-history-upgrade-finish', $output);
        $this->assertStringContainsString('/assets/js/release-highlights.js?v=20260822-history-upgrade', $output);
        $this->assertStringContainsString('/assets/js/history-import.js?v=20260908-stream-completion', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testUnknownRouteRendersNotFound(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('definitely-not-a-route', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('PAGE NOT FOUND', $output);
        $this->assertSame(404, http_response_code());
    }
}
