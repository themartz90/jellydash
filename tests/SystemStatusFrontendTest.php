<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SystemStatusFrontendTest extends TestCase
{
    public function testSystemStatusIsIndependentAndOutsideSettingsForm(): void
    {
        $settings = (string) file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $sidebar = (string) file_get_contents(TEMPLATES_DIR . '/_sidebar.twig');

        $this->assertStringContainsString('href="/settings#system-status"', $sidebar);
        $this->assertStringContainsString('id="system-status" data-system-status', $settings);
        $this->assertLessThan(strpos($settings, 'id="system-status"'), strpos($settings, '</form>'));
        $this->assertStringContainsString('type="button" data-system-status-copy disabled', $settings);
        $this->assertStringContainsString('aria-label="System status, checking"', $sidebar);
        $shell = (string) file_get_contents(TEMPLATES_DIR . '/_shell.twig');
        $this->assertStringContainsString('system-status.js?v=20260908-health-2', $shell);
        $this->assertStringNotContainsString('system-status.js', $settings);
    }

    public function testSystemStatusBehaviorExecutesInNode(): void
    {
        $script = ROOT_DIR . '/tests/frontend/system-status.test.js';
        exec(sprintf('node %s 2>&1', escapeshellarg($script)), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('System status frontend tests passed.', $output);
    }
}
