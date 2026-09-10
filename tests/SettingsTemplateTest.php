<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsTemplateTest extends TestCase
{
    public function testImportSectionKeepsNativeAndPlaybackReportingSourcesSeparate(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('action="/api/history-csv.php"', $template);
        $this->assertStringContainsString('action="/api/playback-reporting.php"', $template);
        $this->assertStringContainsString('name="commit"', $template);
        $this->assertStringContainsString('data-import-dropzone', $template);
        $this->assertStringContainsString('id="import-history"', $template);
        $this->assertStringContainsString('name="jellydash_history"', $template);
        $this->assertStringContainsString('name="playback_reporting"', $template);
        $this->assertStringContainsString('data-import-source="jellydash"', $template);
        $this->assertStringContainsString('data-import-source="playback-reporting"', $template);
        $this->assertStringContainsString('Jellydash CSV', $template);
        $this->assertStringContainsString('Bring older Jellyfin or Emby history', $template);
        $this->assertStringContainsString('data-import-plugin', $template);
        $this->assertStringContainsString('Import from server plugin', $template);
        $this->assertStringContainsString('data-import-alt', $template);
        $this->assertStringContainsString('checks the file before anything is written', $template);
        $this->assertStringContainsString('data-import-plugin-broken-note', $template);
        $this->assertStringContainsString('history-import.js?v={{ asset_revision }}', $template);
        $this->assertStringContainsString('https://github.com/jellyfin/jellyfin-plugin-playbackreporting/pull/131', $template);
        $this->assertStringContainsString('value="plugin"', $template);
        $this->assertStringNotContainsString('Import file', $template);
        $this->assertStringNotContainsString('value="tsv"', $template);
        $this->assertStringNotContainsString('value="sqlite"', $template);

        $dialog = (string) file_get_contents(TEMPLATES_DIR . '/_import_history_dialog.twig');
        $this->assertStringContainsString('data-import-history-kicker', $dialog);
    }

    public function testChangedSettingsExposeAnImmediateSaveAction(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/settings-dirty.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('id="settings-form"', $template);
        $this->assertStringContainsString('data-settings-dirty-bar hidden', $template);
        $this->assertStringContainsString('form="settings-form"', $template);
        $this->assertStringContainsString('settings-dirty.js?v=20260818', $template);
        $this->assertStringContainsString("form.addEventListener('change', sync)", $script);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $script);
        $this->assertStringContainsString('.settings-dirty-bar[hidden]', $stylesheet);
    }

    public function testMonitoringAndNotificationExclusionsStaySeparate(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('<strong>Exclusions</strong>', $template);
        $this->assertStringContainsString('<legend>Monitoring</legend>', $template);
        $this->assertStringContainsString('name="monitoring_ignore[]"', $template);
        $this->assertStringContainsString('name="monitoring_ignore_extra"', $template);
        $this->assertStringContainsString('Existing rows stay in the database.', $template);
        $this->assertStringContainsString('activity skipped while excluded cannot be reconstructed.', $template);
        $this->assertStringContainsString('Names match exactly and are case-insensitive.', $template);
        $this->assertStringContainsString('<legend>Notifications</legend>', $template);
        $this->assertStringContainsString('name="push_ignore[]"', $template);
        $this->assertStringContainsString('name="push_ignore_extra"', $template);
    }

    public function testMonitoringExclusionSettingUsesEnvironmentFallbackAndSavesEmptySelections(): void
    {
        $controller = file_get_contents(ROOT_DIR . '/src/Pages/SettingsController.php');
        $request = file_get_contents(ROOT_DIR . '/operations/@request.php');
        $environment = file_get_contents(ROOT_DIR . '/.env.example');

        $this->assertIsString($controller);
        $this->assertIsString($request);
        $this->assertIsString($environment);
        $this->assertStringContainsString("AppSettings::get('ignore_users')", $controller);
        $this->assertStringContainsString("Config::get('IGNORE_USERS', '')", $controller);
        $this->assertStringContainsString('users(true)', $controller);
        $this->assertStringContainsString("AppSettings::set('ignore_users', \$csv(\$monitoringIgnore, 'monitoring_ignore_extra'))", $request);
        $this->assertStringContainsString('IGNORE_USERS=', $environment);
    }

    public function testSettingsUseOneResponsiveWorkspace(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="settings-page"', $template);
        $this->assertStringContainsString('class="settings-layout"', $template);
        $this->assertStringContainsString('class="settings-card-head"', $template);
        $this->assertStringContainsString('class="settings-card-mark"', $template);
        $this->assertStringContainsString('class="settings-save-btn settings-header-save" form="settings-form"', $template);
        $this->assertStringNotContainsString('class="settings-actions"', $template);
        $this->assertStringContainsString('.settings-layout {', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 760px) minmax(400px, 1fr);', $stylesheet);
        $this->assertStringContainsString('.settings-layout > .settings-import .settings-import-sources', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: 1fr;', $stylesheet);
    }
}
