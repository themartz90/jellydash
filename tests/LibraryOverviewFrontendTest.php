<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LibraryOverviewFrontendTest extends TestCase
{
    public function testLibraryCardsUseBoundedMetricsAndExposeUnavailableCounts(): void
    {
        $script = (string) file_get_contents(ROOT_DIR . '/public/assets/js/libraries.js');

        $this->assertStringContainsString("stat('Total Items', library.totalFiles)", $script);
        $this->assertStringContainsString('library.playbackAvailable === false', $script);
        $this->assertStringContainsString("playbackUnavailable ? 'Playback Unavailable'", $script);
        $this->assertStringContainsString('Live item counts are unavailable.', $script);
        $this->assertStringContainsString("payload.partial || payload.stale ? 'warning' : 'ok'", $script);
        $this->assertStringNotContainsString("stat('Total Time', library.totalTime)", $script);
        $this->assertStringNotContainsString("stat('Library Size', library.size)", $script);
    }

    public function testLibraryLoadingSummaryMatchesTheLiveMetrics(): void
    {
        $template = (string) file_get_contents(ROOT_DIR . '/templates/libraries/index.twig');

        $this->assertStringContainsString("['Libraries', 'Total Items', 'Total Playback', 'Total Plays']", $template);
        $this->assertStringNotContainsString('Storage Used', $template);
    }
}
