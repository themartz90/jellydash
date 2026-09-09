<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsMostWatchedFrontendTest extends TestCase
{
    public function testMostWatchedIsAnAccessibleCollapsedDisclosure(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('<details class="most-watched-disclosure" data-most-watched>', $template);
        $this->assertStringContainsString('<summary class="most-watched-summary">', $template);
        $this->assertStringNotContainsString('data-most-watched open', $template);
        $this->assertStringContainsString('<strong>Most Watched</strong>', $template);
        $this->assertStringContainsString('<small>All-time favourites</small>', $template);
        $this->assertStringContainsString('<span>TV Shows</span>', $template);
        $this->assertStringContainsString('<span>Movies</span>', $template);
    }

    public function testMostWatchedPostersAreDeferredUntilTheDisclosureOpens(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $card = file_get_contents(TEMPLATES_DIR . '/statistics/_title_card.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/statistics-most-watched.js');

        $this->assertIsString($template);
        $this->assertIsString($card);
        $this->assertIsString($script);
        $this->assertSame(2, substr_count($template, 'defer_poster: true'));
        $this->assertStringContainsString('data-deferred-poster="{{ item.poster }}"', $card);
        $this->assertStringContainsString('style="background-image: {{ item.poster }}"', $card);
        $this->assertStringContainsString("disclosure.addEventListener('toggle', loadPosters)", $script);
        $this->assertStringContainsString('if (postersLoaded || !disclosure.open)', $script);
        $this->assertStringContainsString('poster.style.backgroundImage = background', $script);
        $this->assertStringContainsString("poster.removeAttribute('data-deferred-poster')", $script);
    }

    public function testDisclosureAssetsAndRestrainedMotionAreWired(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $shell = file_get_contents(TEMPLATES_DIR . '/_shell.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($shell);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('/assets/js/statistics-most-watched.js?v=20260901-disclosure', $template);
        $this->assertStringContainsString('/assets/css/dashboard.css?v=20260909-exclusions', $shell);
        $this->assertStringContainsString('@media (hover: hover) and (pointer: fine)', $stylesheet);
        $this->assertStringContainsString('transition: transform .16s ease;', $stylesheet);
        $this->assertStringContainsString('.most-watched-disclosure[open] .most-watched-chevron svg', $stylesheet);
        $this->assertStringContainsString(".most-watched-chevron svg {\n        transition: none;", $stylesheet);

        $componentStart = strpos($stylesheet, '.most-watched-disclosure {');
        $componentEnd = strpos($stylesheet, '.trending-row {', $componentStart);
        $this->assertIsInt($componentStart);
        $this->assertIsInt($componentEnd);
        $componentStyles = substr($stylesheet, $componentStart, $componentEnd - $componentStart);
        $this->assertStringNotContainsString('transition: all', $componentStyles);
    }
}
