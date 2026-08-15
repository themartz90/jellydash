<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsTemplateTest extends TestCase
{
    public function testImportFormPostsPlaybackReportingBackup(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('action="/api/playback-reporting.php"', $template);
        $this->assertStringContainsString('name="commit"', $template);
        $this->assertStringContainsString('data-import-dropzone', $template);
        $this->assertStringContainsString('name="playback_reporting"', $template);
        $this->assertStringContainsString('data-import-plugin', $template);
        $this->assertStringContainsString('data-import-alt', $template);
        $this->assertStringContainsString('asks you to confirm', $template);
        $this->assertStringContainsString('data-import-plugin-broken-note', $template);
        $this->assertStringContainsString('https://github.com/jellyfin/jellyfin-plugin-playbackreporting/pull/131', $template);
        $this->assertStringContainsString('value="plugin"', $template);
        $this->assertStringNotContainsString('Import file', $template);
        $this->assertStringNotContainsString('value="tsv"', $template);
        $this->assertStringNotContainsString('value="sqlite"', $template);
    }
}
