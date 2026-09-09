<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\HistoryFilters;
use PHPUnit\Framework\TestCase;

final class HistoryTemplateTest extends TestCase
{
    public function testFooterUsesFilteredResultTotal(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/history/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            'Showing {{ summary.from }}-{{ summary.to }} of {{ summary.filtered_total }} plays',
            $template
        );
        $this->assertStringContainsString(
            '{{ summary.from }}-{{ summary.to }} <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString(
            '0 <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString('history/_pager.twig', $template);
        $this->assertStringContainsString('href="/settings#import-history"', $template);
        $this->assertStringContainsString('Import history', $template);
        $this->assertStringContainsString('Export CSV', $template);
        $this->assertStringContainsString('data-history-export-open', $template);
        $this->assertStringContainsString('history/_export_dialog.twig', $template);
        $this->assertStringContainsString('summary.total > 0', $template);
        $this->assertStringContainsString('history-export.js?v=20260910-statistics-drilldowns', $template);
        $this->assertStringContainsString('history-filters.js?v=20260910-statistics-drilldowns', $template);
        $this->assertStringNotContainsString('data-import-history-banner', $template);
        $this->assertStringNotContainsString('history-import.js', $template);
        $this->assertStringContainsString('{% for library in libraries %}', $template);
        $this->assertStringContainsString('{% for client in clients %}', $template);
        $this->assertStringContainsString('name="method" aria-label="Playback method"', $template);
        $this->assertStringNotContainsString('option value="Movies"', $template);
        $this->assertStringContainsString('Watch time', $template);
        $this->assertStringNotContainsString('Watch time shown', $template);

        $empty = file_get_contents(TEMPLATES_DIR . '/history/_empty.twig');
        $this->assertIsString($empty);
        $this->assertStringContainsString('{% if summary.total == 0 %}', $empty);
        $this->assertStringContainsString('Import existing history', $empty);
        $this->assertStringContainsString('href="/settings#import-history"', $empty);
    }

    public function testHistoryExportDialogOffersClearCompleteHistoryFilters(): void
    {
        $dialog = (string) file_get_contents(TEMPLATES_DIR . '/history/_export_dialog.twig');
        $script = (string) file_get_contents(ROOT_DIR . '/public/assets/js/history-export.js');
        $filterScript = (string) file_get_contents(ROOT_DIR . '/public/assets/js/history-filters.js');
        $stylesheet = (string) file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertStringContainsString('action="/api/history-export.php"', $dialog);
        $this->assertStringContainsString('every match, not only the page currently on screen', $dialog);
        $this->assertStringContainsString('name="search" value="{{ filters.search }}"', $dialog);
        $this->assertStringContainsString('name="user"', $dialog);
        $this->assertStringContainsString('name="library"', $dialog);
        $this->assertStringContainsString('name="client"', $dialog);
        $this->assertStringContainsString('name="method"', $dialog);
        foreach (['direct', 'direct-play', 'direct-stream', 'transcode'] as $method) {
            $this->assertStringContainsString('value="' . $method . '"', $dialog);
        }
        $this->assertStringContainsString('name="range" value="7"', $dialog);
        $this->assertStringContainsString('name="range" value="30"', $dialog);
        $this->assertStringContainsString('name="range" value="all"', $dialog);
        $this->assertStringContainsString('data-history-export-count', $dialog);
        $this->assertStringContainsString('data-history-export-all', $dialog);
        $this->assertStringContainsString('data-history-export-download disabled', $dialog);
        $this->assertStringContainsString('CSV format v2', $dialog);

        $this->assertStringContainsString("values.set('preview', '1')", $script);
        $this->assertStringContainsString("fetch('/api/history-export.php?'", $script);
        $this->assertStringContainsString('new URLSearchParams(new FormData(form))', $script);
        $this->assertStringContainsString('AbortController', $script);
        $this->assertStringContainsString('plays are ready to export', $script);
        $this->assertStringContainsString("form.elements.range.value = 'all'", $script);
        $this->assertStringContainsString("form.elements.client.value = ''", $script);
        $this->assertStringContainsString("form.elements.method.value = ''", $script);
        $this->assertStringContainsString("values.get('range') !== 'custom'", $script);
        $this->assertStringContainsString("event.formData.get('range') !== 'custom'", $filterScript);
        $this->assertStringContainsString('window.setTimeout(closeDialog, 0)', $script);

        $this->assertStringContainsString('.history-export-dialog', $stylesheet);
        $this->assertStringContainsString('.history-export-period-options', $stylesheet);
        $this->assertStringContainsString('.history-export-count.is-ready', $stylesheet);
    }

    public function testExactPeriodIsVisibleAndPreservedByHistoryAndExportForms(): void
    {
        $template = (string) file_get_contents(TEMPLATES_DIR . '/history/index.twig');
        $dialog = (string) file_get_contents(TEMPLATES_DIR . '/history/_export_dialog.twig');

        foreach ([$template, $dialog] as $source) {
            $this->assertStringContainsString('name="start" value="{{ filters.start }}"', $source);
            $this->assertStringContainsString('name="end" value="{{ filters.end }}"', $source);
            $this->assertStringContainsString('filters.period_label', $source);
            $this->assertStringContainsString('value="custom"', $source);
        }
    }

    public function testHistoryPagerPreservesTheExactPeriod(): void
    {
        $controller = new \Mk\Framework\Pages\HistoryController(new \Mk\Framework\View());
        $url = (new ReflectionMethod($controller, 'historyUrl'))->invoke(
            $controller,
            new HistoryFilters(
                user: 'Martin',
                client: 'Jellyfin Web',
                method: 'direct-stream',
                range: 'custom',
                start: new DateTimeImmutable('2026-03-02'),
                end: new DateTimeImmutable('2026-04-01'),
            ),
            2,
        );

        $this->assertSame(
            '/history?user=Martin&client=Jellyfin+Web&method=direct-stream&range=custom&start=2026-03-02&end=2026-04-01&p=2',
            $url,
        );
    }

    public function testHistoryRowsUseSharedAvatarPartial(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/history/_history_row.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('_avatar.twig', $template);
        $this->assertStringContainsString('play.avatarUrl', $template);
    }

    public function testHistoryRowsKeepItemDetailsAndLibrarySeparate(): void
    {
        $controller = new \Mk\Framework\Pages\HistoryController(new \Mk\Framework\View());
        $method = new ReflectionMethod($controller, 'rowView');
        $avatars = new \Mk\Framework\Jellyfin\JellyfinUserAvatars();

        $episode = $method->invoke(
            $controller,
            $this->historyRow([
                'item_type' => 'Episode',
                'series_name' => 'Impractical Jokers',
                'item_name' => 'Celebrity Crushed',
                'season_ep' => 'S13 E3',
                'library' => 'TV Shows',
            ]),
            new DateTimeImmutable('2026-08-22 12:00:00'),
            $avatars,
        );
        $movie = $method->invoke(
            $controller,
            $this->historyRow([
                'item_type' => 'Movie',
                'series_name' => null,
                'item_name' => 'Elvira: Mistress of the Dark',
                'season_ep' => null,
                'library' => 'Movies',
            ]),
            new DateTimeImmutable('2026-08-22 12:00:00'),
            $avatars,
        );

        $this->assertSame('S13 E3 - Celebrity Crushed', $episode['sub']);
        $this->assertSame('TV Shows', $episode['library']);
        $this->assertSame('Movie', $movie['sub']);
        $this->assertSame('Movies', $movie['library']);

        $template = (string) file_get_contents(TEMPLATES_DIR . '/history/_history_row.twig');
        $stylesheet = (string) file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        $this->assertStringContainsString('history-item-meta', $template);
        $this->assertStringContainsString('history-library-pill', $template);
        $this->assertStringContainsString('play.library', $template);
        $this->assertMatchesRegularExpression(
            '/\.history-item-meta small\s*\{[^}]*flex:\s*0 1 auto;/s',
            $stylesheet,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function historyRow(array $overrides): \Dibi\Row
    {
        return new \Dibi\Row(array_merge([
            'item_type' => 'Video',
            'play_method' => 'DirectPlay',
            'watched_sec' => 120,
            'runtime_sec' => 600,
            'series_name' => null,
            'item_name' => 'Fixture',
            'user_name' => 'Martin',
            'user_id' => '',
            'season_ep' => null,
            'library' => 'Videos',
            'client' => 'Jellyfin Web',
            'device' => 'Chrome',
            'is_finished' => 0,
            'item_id' => 'fixture-item',
        ], $overrides));
    }
}
