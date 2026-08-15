<?php

use Mk\Framework\Router;
use Mk\Framework\View;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the router (Phase 6 / #12). The 404 path needs no database.
 */
final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        http_response_code(200);
    }

    public function testNowPlayingRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('now-playing', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Now Playing', $output);
        $this->assertStringContainsString('app-shell', $output);
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
        $this->assertStringContainsString('data-import-history-dialog', $output);
        $this->assertStringContainsString('/assets/js/history-import.js?v=20260815-review', $output);
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
        $this->assertStringContainsString('Statistics exclusions', $output);
        $this->assertStringContainsString('Selected libraries are hidden from Trending and Most Watched.', $output);
        $this->assertStringContainsString('Notification exclusions', $output);
        $this->assertStringContainsString('Selected users never trigger playback alerts.', $output);
        $this->assertStringContainsString('/assets/js/server-stats.js?v=20260809-settings', $output);
        $this->assertStringContainsString('/assets/js/nav-count.js?v=20260809-settings', $output);
        $this->assertStringContainsString('data-update-status', $output);
        $this->assertStringContainsString('/assets/js/update-status.js?v=20260810-update', $output);
        $this->assertStringContainsString('data-release-changes', $output);
        $this->assertStringContainsString('data-release-dialog', $output);
        $this->assertStringContainsString('data-import-history-dialog', $output);
        $this->assertStringContainsString('/assets/js/release-highlights.js?v=20260813-import', $output);
        $this->assertStringContainsString('/assets/js/history-import.js?v=20260815-review', $output);
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
