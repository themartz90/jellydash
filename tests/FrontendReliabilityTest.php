<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendReliabilityTest extends TestCase
{
    public function testSharedAssetRevisionIsUsedByLoginAndDashboardShells(): void
    {
        $view = (string) file_get_contents(ROOT_DIR . '/src/View.php');
        $shell = (string) file_get_contents(TEMPLATES_DIR . '/_shell.twig');
        $login = (string) file_get_contents(TEMPLATES_DIR . '/login.twig');

        $this->assertStringContainsString("addGlobal('asset_revision'", $view);
        $this->assertStringContainsString('dashboard.css?v={{ asset_revision }}', $shell);
        $this->assertStringContainsString('dashboard.css?v={{ asset_revision }}', $login);
    }

    public function testPollersBoundRequestsAndRespectPageLifecycle(): void
    {
        foreach (['now-playing.js', 'nav-count.js', 'server-stats.js'] as $file) {
            $script = (string) file_get_contents(ROOT_DIR . '/public/assets/js/' . $file);
            $this->assertStringContainsString('AbortController', $script, $file);
            $this->assertStringContainsString('document.hidden', $script, $file);
            $this->assertStringContainsString("addEventListener('visibilitychange'", $script, $file);
            $this->assertStringContainsString("addEventListener('pagehide'", $script, $file);
            $this->assertStringContainsString("addEventListener('pageshow'", $script, $file);
        }
    }

    public function testNowPlayingUsesStableCardsAndRendersCollectionAvailability(): void
    {
        $script = (string) file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $template = (string) file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');

        $this->assertStringContainsString('function reconcileStreams(', $script);
        $this->assertStringContainsString('existing.innerHTML = replacement.innerHTML', $script);
        $this->assertStringContainsString('activeElement', $script);
        $this->assertStringContainsString('watch_today_available', $script);
        $this->assertStringContainsString('collection_status', $script);
        $this->assertStringContainsString('data-collection-status', $template);
    }

    public function testAccessibilityAndOptionalControlsHaveExplicitSemantics(): void
    {
        $history = (string) file_get_contents(TEMPLATES_DIR . '/history/index.twig');
        $statistics = (string) file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $devices = (string) file_get_contents(ROOT_DIR . '/public/assets/js/statistics-devices.js');
        $css = (string) file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertStringContainsString('for="history-search"', $history);
        $this->assertStringContainsString('id="history-search"', $history);
        $this->assertStringContainsString('<h2>General</h2>', $statistics);
        $this->assertStringContainsString('class="visually-hidden"', $statistics);
        $this->assertStringContainsString('manageLink.hidden = true', $devices);
        $this->assertStringContainsString('.stats-devices-manage[hidden]', $css);
    }

    public function testScopedAvatarBindingAndBoundedPushSetupArePresent(): void
    {
        $avatars = (string) file_get_contents(ROOT_DIR . '/public/assets/js/avatars.js');
        $push = (string) file_get_contents(ROOT_DIR . '/public/assets/js/push.js');

        $this->assertStringContainsString('records.forEach', $avatars);
        $this->assertStringContainsString('record.addedNodes', $avatars);
        $this->assertStringContainsString('waitForServiceWorker', $push);
        $this->assertStringContainsString('Setup timed out. Try again.', $push);
        $this->assertStringContainsString('Notifications need browser support.', $push);
    }

    public function testEmptyLegacyMainScriptIsRemoved(): void
    {
        $this->assertFileDoesNotExist(ROOT_DIR . '/public/assets/js/main.js');
    }
}
