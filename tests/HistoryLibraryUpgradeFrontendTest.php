<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HistoryLibraryUpgradeFrontendTest extends TestCase
{
    public function testUpgradeDialogIsGlobalAndCanBeHiddenAndReopenedWhileRunning(): void
    {
        $shell = (string) file_get_contents(TEMPLATES_DIR . '/_shell.twig');
        $dialog = (string) file_get_contents(TEMPLATES_DIR . '/_history_library_upgrade_dialog.twig');
        $script = (string) file_get_contents(ROOT_DIR . '/public/assets/js/history-library-upgrade.js');

        $this->assertStringContainsString('_history_library_upgrade_dialog.twig', $shell);
        $this->assertStringContainsString(
            '/assets/js/history-library-upgrade.js?v={{ asset_revision }}',
            $shell,
        );
        $this->assertStringContainsString('data-history-library-upgrade', $dialog);
        $this->assertStringContainsString('role="progressbar"', $dialog);
        $this->assertStringContainsString('data-history-library-upgrade-close', $dialog);
        $this->assertStringContainsString('data-history-library-upgrade-complete', $dialog);
        $this->assertStringContainsString("dialog.addEventListener('cancel'", $script);
        $this->assertStringContainsString('event.preventDefault()', $script);
        $this->assertStringContainsString('data-history-library-upgrade-retry', $dialog);
        $this->assertStringContainsString('data-history-library-upgrade-continue', $dialog);
        $this->assertStringContainsString('data-history-library-upgrade-reopen', $dialog);
        $this->assertStringContainsString('hideForNow', $script);
        $this->assertStringContainsString("reopenButton.addEventListener('click'", $script);
        $this->assertStringContainsString('completeActions.hidden = false', $script);
        $this->assertStringContainsString('closeButton.focus()', $script);
    }

    public function testUpgradeUsesResumableBatchesAndDelaysReleaseHighlights(): void
    {
        $upgrade = (string) file_get_contents(ROOT_DIR . '/public/assets/js/history-library-upgrade.js');
        $release = (string) file_get_contents(ROOT_DIR . '/public/assets/js/release-highlights.js');
        $api = (string) file_get_contents(ROOT_DIR . '/public/api/history-library-upgrade.php');

        $this->assertStringContainsString('/api/history-library-upgrade.php', $upgrade);
        $this->assertStringContainsString("request('POST')", $upgrade);
        $this->assertStringContainsString("'X-CSRF-Token'", $upgrade);
        $this->assertStringContainsString('window.jellydashUpgradeReady', $upgrade);
        $this->assertStringContainsString('window.jellydashUpgradeReady', $release);
        $this->assertStringContainsString('upgradeReady.then', $release);
        $this->assertStringContainsString('Csrf::validateHeader()', $api);
        $this->assertStringContainsString('HistoryLibraryBackfillService', $api);
    }
}
