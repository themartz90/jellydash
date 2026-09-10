<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsRangeFrontendTest extends TestCase
{
    public function testRangePickerExposesTheDefaultViewAction(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        $controller = file_get_contents(ROOT_DIR . '/src/Pages/StatisticsController.php');
        $request = file_get_contents(ROOT_DIR . '/operations/@request.php');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertIsString($controller);
        $this->assertIsString($request);
        $this->assertStringContainsString('class="statistics-range-picker"', $template);
        $this->assertStringContainsString('Viewing period', $template);
        $this->assertStringContainsString('Default view', $template);
        $this->assertStringContainsString('Make default', $template);
        $this->assertStringContainsString('name="csrf_token"', $template);
        $this->assertStringContainsString('aria-current="page"', $template);
        $this->assertStringContainsString('.statistics-range-control a.is-active', $stylesheet);
        $this->assertStringContainsString("AppSettings::get('statistics_default_range')", $controller);
        $this->assertStringContainsString('->cachedData($range)', $controller);
        $this->assertStringContainsString("AppSettings::set('statistics_default_range'", $request);
        $this->assertStringContainsString("requestIs('statistics-default')", $request);
    }

    public function testTrendAndCoverageValuesAreExposedInTheStatisticsMarkup(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('aria-label="{{ bar.label }}: {{ bar.value }}"', $template);
        $this->assertStringContainsString('{{ stats.codecCoverage }}', $template);
        $this->assertStringContainsString('{{ stats.reasonCoverage }}', $template);
        $this->assertStringContainsString('width: {{ user.share }}', $template);
        $this->assertStringContainsString(".stats-trend-bars i {\n    width: min(30px, 100%);\n    min-height: 0;", $stylesheet);
    }

    public function testPlaybackDrilldownsAreLinksWithMobileFriendlyTargets(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="stats-kpi-link" href="{{ kpi.href }}"', $template);
        $this->assertStringContainsString('class="stats-user-row stats-user-link" href="{{ user.href }}"', $template);
        $this->assertStringContainsString('class="stats-user-table-row stats-user-table-link" href="{{ user.href }}"', $template);
        $this->assertStringContainsString('{% if user.href is defined %}', $template);
        $this->assertStringContainsString('.stats-kpi-link:focus-visible,', $stylesheet);
        $this->assertStringContainsString(".stats-user-link {\n    min-height: 44px;", $stylesheet);
        $this->assertStringContainsString('class="stats-legend-row stats-drilldown-link" href="{{ item.href }}"', $template);
        $this->assertStringContainsString('class="stats-ranked-row stats-drilldown-link" href="{{ item.href }}"', $template);
        $this->assertStringContainsString('class="stats-bar-row stats-drilldown-link" href="{{ item.href }}"', $template);
        $this->assertStringContainsString('class="stats-client-name-link" href="{{ item.href }}"', $template);
        $this->assertStringContainsString('class="stats-stack-filter-links"', $template);
        $this->assertStringContainsString('href="{{ item.directHref }}"', $template);
        $this->assertStringContainsString('href="{{ item.transcodeHref }}"', $template);
        $this->assertStringContainsString('.stats-drilldown-link:focus-visible,', $stylesheet);
        $this->assertStringContainsString('.stats-stack-filter-links a {', $stylesheet);
        $this->assertStringContainsString('min-height: 44px;', $stylesheet);
    }

    public function testStatisticsExplainsThatBreakdownsOpenHistory(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="stats-history-hint"', $template);
        $this->assertStringContainsString('Select a title, user, client or playback type to view matching plays in History.', $template);
        $this->assertStringContainsString('.stats-history-hint {', $stylesheet);
        $this->assertStringContainsString(".stats-history-hint svg {\n    width: 16px;", $stylesheet);
        $this->assertStringContainsString('stroke: currentColor;', $stylesheet);
        $this->assertStringContainsString('.stats-user-table-link:active,', $stylesheet);
    }
}
