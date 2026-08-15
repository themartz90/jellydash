<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseHighlightsTest extends TestCase
{
    public function testVersionedHighlightsAreStructuredAndSafe(): void
    {
        $path = ROOT_DIR . '/public/assets/release-highlights/1.2.0.json';

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('1.2.0', $payload['version'] ?? null);
        $this->assertTrue($payload['auto_show'] ?? false);
        $this->assertIsString($payload['title'] ?? null);
        $this->assertNotSame('', trim((string) ($payload['title'] ?? '')));
        $this->assertIsString($payload['summary'] ?? null);
        $this->assertNotSame('', trim((string) ($payload['summary'] ?? '')));
        $this->assertIsArray($payload['highlights'] ?? null);
        $this->assertNotEmpty($payload['highlights']);
        $this->assertIsArray($payload['links'] ?? null);
        $this->assertNotEmpty($payload['links']);

        foreach ($payload['highlights'] as $highlight) {
            $this->assertIsString($highlight);
            $this->assertNotSame('', trim($highlight));
        }

        foreach ($payload['links'] as $link) {
            $this->assertIsArray($link);
            $this->assertIsString($link['label'] ?? null);
            $this->assertNotSame('', trim((string) ($link['label'] ?? '')));
            $this->assertIsString($link['url'] ?? null);

            $parts = parse_url((string) ($link['url'] ?? ''));
            $this->assertIsArray($parts);
            $this->assertSame('https', $parts['scheme'] ?? null);
            $this->assertSame('github.com', $parts['host'] ?? null);
            $this->assertStringStartsWith('/themartz90/jellydash/', (string) ($parts['path'] ?? ''));
        }
    }

    public function testReleaseScriptSignalsWhenStartupDialogIsSettled(): void
    {
        $js = (string) file_get_contents(ROOT_DIR . '/public/assets/js/release-highlights.js');
        $importJs = (string) file_get_contents(ROOT_DIR . '/public/assets/js/history-import.js');

        $this->assertStringContainsString('jellydash:release-dialog-settled', $js);
        $this->assertStringContainsString('jellydash:release-dialog-settled', $importJs);
        $this->assertStringContainsString('/api/playback-reporting.php', $importJs);
        $this->assertStringContainsString('data-import-alt', $importJs);
        $this->assertStringContainsString('payload.broken', $importJs);
        $this->assertStringContainsString('X-CSRF-Token', $importJs);
        $this->assertStringContainsString('method: \'POST\'', $importJs);
        $this->assertStringContainsString('commit', $importJs);
        $this->assertStringContainsString('application/x-ndjson', $importJs);
        $this->assertStringContainsString('data-import-history-progress', $importJs);
        $this->assertStringContainsString('data-history-live', $importJs);
        $this->assertStringContainsString('Import ', $importJs);
        $this->assertStringContainsString('Checking the import', $importJs);
        $this->assertStringContainsString('Importing history', $importJs);

        $api = (string) file_get_contents(ROOT_DIR . '/public/api/playback-reporting.php');
        $this->assertStringContainsString('Csrf::validateHeader()', $api);
        $this->assertStringContainsString('previewFile', $api);
        $this->assertStringContainsString('previewPlugin', $api);
        $this->assertStringContainsString('streamImport', $api);
        $this->assertStringContainsString('application/x-ndjson', $api);

        $dialog = (string) file_get_contents(TEMPLATES_DIR . '/_import_history_dialog.twig');
        $this->assertStringContainsString('data-import-history-confirm', $dialog);
        $this->assertStringContainsString('data-import-history-title', $dialog);
        $this->assertStringContainsString('data-import-history-progress', $dialog);
    }
}
