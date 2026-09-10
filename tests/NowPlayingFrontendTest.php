<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NowPlayingFrontendTest extends TestCase
{
    public function testInitialRequestFailureReplacesLoadingStates(): void
    {
        $nowPlaying = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $navCount = file_get_contents(ROOT_DIR . '/public/assets/js/nav-count.js');

        $this->assertIsString($nowPlaying);
        $this->assertIsString($navCount);
        $this->assertStringContainsString('Could not load sessions', $nowPlaying);
        $this->assertStringContainsString('renderError', $nowPlaying);
        $this->assertStringContainsString("navCount.classList.remove('is-loading')", $navCount);
        $this->assertStringContainsString("navCount.textContent = '-'", $navCount);
    }

    public function testNowPlayingSkipsOverlappingRefreshesAndReleasesTheGuardAfterFailure(): void
    {
        $nowPlaying = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');

        $this->assertIsString($nowPlaying);
        $this->assertStringContainsString('if (refreshInFlight || document.hidden)', $nowPlaying);
        $this->assertStringContainsString('refreshInFlight = true', $nowPlaying);
        $this->assertStringContainsString('refreshInFlight = false', $nowPlaying);
        $this->assertStringContainsString("new CustomEvent('jellydash:now-playing'", $nowPlaying);
    }

    public function testNowPlayingOwnsTheNavigationCountPollingLoop(): void
    {
        $nowPlaying = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $navCount = file_get_contents(ROOT_DIR . '/public/assets/js/nav-count.js');
        $shell = file_get_contents(TEMPLATES_DIR . '/_shell.twig');

        $this->assertIsString($nowPlaying);
        $this->assertIsString($navCount);
        $this->assertIsString($shell);
        $this->assertStringContainsString("setText('[data-nav-count]', activeStreams)", $nowPlaying);
        $this->assertStringContainsString("document.querySelector('[data-now-playing-root]')", $navCount);
        $this->assertStringContainsString('Do not start a second', $navCount);
        $this->assertStringContainsString('nav-count.js?v={{ asset_revision }}', $shell);
    }

    public function testActiveStreamsAreLabelledAsStreams(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertStringContainsString("streams|length == 1 ? 'stream' : 'streams'", $template);
        $this->assertStringContainsString("activeStreams === 1 ? 'stream' : 'streams'", $script);
    }

    public function testPlaybackTelemetryLivesInTheHeaderInsteadOfTheFooter(): void
    {
        $controller = file_get_contents(ROOT_DIR . '/src/Pages/NowPlayingController.php');
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($controller);
        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("'header_class' => 'dashboard-header-now-playing'", $controller);
        $this->assertStringContainsString("'hide_footer' => true", $controller);
        $this->assertStringContainsString('data-now-playing-telemetry', $template);
        $this->assertStringContainsString('data-stat-block="bandwidth"', $template);
        $this->assertStringContainsString('data-stat-block="transcoding"', $template);
        $this->assertStringNotContainsString('data-stat-block="active_streams"', $template);
        $this->assertStringNotContainsString('{% block dashboard_footer %}', $template);
        $this->assertStringNotContainsString('data-stat-block="active_streams"', $script);
        $this->assertStringContainsString('.now-playing-telemetry', $stylesheet);
    }

    public function testMobileIdleLayoutUsesTheAvailableFlexSpace(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="now-playing-page"', $template);
        $this->assertStringContainsString('.dashboard-content-now-playing', $stylesheet);
        $this->assertStringNotContainsString('calc(100dvh - 350px)', $stylesheet);
    }

    public function testMobileRecentlyAddedUsesTheSameMinimumForLoadingAndIdleStates(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString(
            ".now-playing-page.has-recently-added .now-playing-live > .empty-state,\n"
            . "    .now-playing-page.has-recently-added .now-playing-live > .loading-state {\n"
            . "        min-height: 175px;\n"
            . '    }',
            $stylesheet,
        );
    }

    public function testStandaloneMobileShellUsesTheStableViewportHeight(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $dynamicRule = "    .app-shell {\n"
            . "        display: flex;\n"
            . "        flex-direction: column;\n"
            . "        width: 100%;\n"
            . "        min-height: 100vh;\n"
            . "        min-height: 100dvh;\n";
        $stableRule = "@media (max-width: 900px) and (display-mode: standalone) {\n"
            . "    .app-shell {\n"
            . "        min-height: 100svh;\n"
            . '    }';
        $dynamicRulePosition = strpos($stylesheet, $dynamicRule);
        $stableRulePosition = strpos($stylesheet, $stableRule);

        $this->assertNotFalse($dynamicRulePosition);
        $this->assertNotFalse($stableRulePosition);
        $this->assertGreaterThan($dynamicRulePosition, $stableRulePosition);
    }

    public function testRecentlyAddedStaysHiddenForEmptyOrFailedRequests(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/recently-added.js');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertStringContainsString('data-recently-added aria-labelledby=', $template);
        $this->assertStringContainsString('if (!response.ok)', $script);
        $this->assertStringContainsString('if (items.length === 0)', $script);
        $this->assertStringContainsString('panel.hidden = false', $script);
    }

    public function testRecentlyAddedCanBeDisabledWithoutLoadingItsClient(): void
    {
        $nowPlaying = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $settings = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $view = file_get_contents(ROOT_DIR . '/src/View.php');
        $request = file_get_contents(ROOT_DIR . '/operations/@request.php');

        $this->assertIsString($nowPlaying);
        $this->assertIsString($settings);
        $this->assertIsString($view);
        $this->assertIsString($request);
        $this->assertStringContainsString('{% if show_recently_added %}', $nowPlaying);
        $this->assertStringContainsString('name="show_recently_added"', $settings);
        $this->assertStringContainsString("AppSettings::bool('show_recently_added', true)", $view);
        $this->assertStringContainsString("AppSettings::set('show_recently_added'", $request);
    }

    public function testRecentlyAddedCollapsesDuringPlaybackAndCanBeExpanded(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/recently-added.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('data-recently-added-toggle', $template);
        $this->assertStringContainsString('aria-controls="recently-added-row"', $template);
        $this->assertStringContainsString('const canCollapse = playbackActive;', $script);
        $this->assertStringContainsString("window.addEventListener('jellydash:now-playing'", $script);
        $this->assertStringContainsString("panel.classList.toggle('is-collapsible', canCollapse)", $script);
        $this->assertStringContainsString("panel.classList.toggle('is-collapsed', collapsed)", $script);
        $this->assertStringContainsString('row.tabIndex = collapsed ? -1 : 0', $script);
        $this->assertStringContainsString('.recently-added-toggle[hidden]', $stylesheet);
        $this->assertStringContainsString('.recently-added-panel.is-collapsible.is-collapsed', $stylesheet);
    }

    public function testRecentlyAddedCardsOpenMatchingJellyfinDetailsSafely(): void
    {
        $service = file_get_contents(ROOT_DIR . '/src/Jellyfin/RecentlyAddedService.php');
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/recently-added.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($service);
        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("'/System/Info/Public'", $service);
        $this->assertStringContainsString("'/web/#/details?id='", $service);
        $this->assertStringContainsString('jellyfinUrl', $service);
        $this->assertStringContainsString('safeJellyfinUrl', $script);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $script);
        $this->assertStringContainsString('recent-media-open', $script);
        $this->assertStringContainsString('if (event.target !== row)', $script);
        $this->assertStringContainsString('recently-added.js?v={{ asset_revision }}', $template);
        $this->assertStringContainsString('.recent-media-card[href]:focus-visible', $stylesheet);
    }

    public function testActiveStreamGridCannotShrinkUnderRecentlyAddedShelf(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("streams is not empty ? ' has-streams' : ''", $template);
        $this->assertStringContainsString("root.classList.toggle('has-streams', streams.length > 0)", $script);
        $this->assertStringContainsString('.now-playing-live.has-streams', $stylesheet);
        $this->assertStringContainsString('min-height: min-content;', $stylesheet);
        $this->assertStringContainsString(".now-playing-page {\n    min-height: min-content;", $stylesheet);
    }

    public function testMobilePlaybackDetailsCannotPushTheMethodBadgeToAnotherRow(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.stream-card-top .playback-stack', $stylesheet);
        $this->assertStringContainsString('display: contents;', $stylesheet);
        $this->assertStringContainsString('.stream-card-top .quality-chip', $stylesheet);
        $this->assertStringContainsString('grid-column: 1 / -1;', $stylesheet);
        $this->assertStringContainsString('width: fit-content;', $stylesheet);
        $this->assertStringContainsString('justify-self: end;', $stylesheet);
        $this->assertStringContainsString('text-overflow: ellipsis;', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: 54px minmax(0, 1fr);', $stylesheet);
        $this->assertStringContainsString('-webkit-line-clamp: 3;', $stylesheet);
    }

    public function testRicherPlaybackDetailsRenderInitiallyAndAfterPolling(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/_stream_card.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="stream-details"', $template);
        $this->assertStringContainsString('function streamDetails(stream)', $script);
        $this->assertStringContainsString('${streamDetails(stream)}', $script);
        $this->assertStringContainsString('.stream-overlay-summary', $stylesheet);
    }

    public function testTranscodesKeepTheCardCompactAndOpenFullDiagnostics(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/_stream_card.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('stream.isTranscode', $template);
        $this->assertStringContainsString('data-diagnostics-open', $template);
        $this->assertStringContainsString('data-diagnostics-overlay', $template);
        $this->assertStringContainsString('stream.sourceMediaLabel', $template);
        $this->assertStringContainsString('stream.outputMediaLabel', $template);
        $this->assertStringContainsString('function transcodeDetails(stream)', $script);
        $this->assertStringContainsString('function transcodeDiagnostics(stream)', $script);
        $this->assertStringContainsString('.stream-diagnostics-overlay', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);', $stylesheet);
    }

    public function testDiagnosticsAreAccessibleAndSurvivePollingRefreshes(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/_stream_card.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('aria-expanded="false"', $template);
        $this->assertStringContainsString('aria-hidden="true"', $template);
        $this->assertStringContainsString('data-diagnostics-overlay aria-hidden="true"', $template);
        $this->assertStringContainsString('inert>', $template);
        $this->assertStringContainsString('function setDiagnosticsOpen(cardElement, open, moveFocus = true)', $script);
        $this->assertStringContainsString('overlay.inert = !open', $script);
        $this->assertStringContainsString("event.key !== 'Escape'", $script);
        $this->assertStringContainsString('candidate.dataset.streamId === openDiagnosticsId', $script);
        $this->assertStringContainsString('setDiagnosticsOpen(openCard, true, false)', $script);
        $this->assertStringContainsString('opacity: 0;', $stylesheet);
        $this->assertStringContainsString('pointer-events: none;', $stylesheet);
    }

    public function testTranscodePathUsesTheLocalFilledTablerIcon(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/_stream_card.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertStringContainsString('stream-transcode-icon icon-filled', $template);
        $this->assertStringContainsString('stream-transcode-icon icon-filled', $script);
        $this->assertStringContainsString('M7 6l-.112 .006a1 1 0 0 0-.669 1.619', $template);
    }

    public function testDiagnosticsUseTheCardAsTheirOnlyRoundedClipOwner(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.stream-diagnostics-overlay\s*\{(?:(?!border-radius|backdrop-filter|transform:).)*position:\s*absolute;(?:(?!border-radius|backdrop-filter|transform:).)*inset:\s*0;(?:(?!border-radius|backdrop-filter|transform:).)*\}/s',
            $stylesheet,
        );
    }
}
