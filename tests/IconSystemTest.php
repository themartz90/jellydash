<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IconSystemTest extends TestCase
{
    public function testCoreInterfaceUsesLocalTablerFilledIcons(): void
    {
        $sidebar = file_get_contents(TEMPLATES_DIR . '/_sidebar.twig');
        $shell = file_get_contents(TEMPLATES_DIR . '/_shell.twig');
        $nowPlaying = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $historyEmpty = file_get_contents(TEMPLATES_DIR . '/history/_empty.twig');
        $settings = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $libraries = file_get_contents(ROOT_DIR . '/public/assets/js/libraries.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($sidebar);
        $this->assertIsString($shell);
        $this->assertIsString($nowPlaying);
        $this->assertIsString($historyEmpty);
        $this->assertIsString($settings);
        $this->assertIsString($libraries);
        $this->assertIsString($stylesheet);

        $sources = $sidebar . $shell . $nowPlaying . $historyEmpty . $settings . $libraries;
        $this->assertGreaterThanOrEqual(17, substr_count($sources, 'icon-filled'));
        $this->assertStringContainsString('svg.icon-filled', $stylesheet);
        $this->assertStringContainsString('fill: currentColor;', $stylesheet);
        $this->assertStringContainsString('stroke: none;', $stylesheet);
        $this->assertStringContainsString('dashboard.css?v={{ asset_revision }}', $shell);
        $this->assertStringNotContainsString('api.iconify.design', $sources);
        $this->assertStringNotContainsString('code.iconify.design', $sources);
    }

    public function testTablerLicenseNoticeShipsWithTheIcons(): void
    {
        $readme = file_get_contents(ROOT_DIR . '/README.md');
        $notice = file_get_contents(ROOT_DIR . '/THIRD_PARTY_NOTICES.md');

        $this->assertIsString($readme);
        $this->assertIsString($notice);
        $this->assertStringContainsString('Tabler Icons', $readme);
        $this->assertStringContainsString('Tabler Icons v3.46.0', $notice);
        $this->assertStringContainsString('Copyright (c) 2020-2026 Paweł Kuna', $notice);
        $this->assertStringContainsString('MIT License', $notice);
    }

    public function testPushNotificationsUseDedicatedStatusBarBadge(): void
    {
        $serviceWorker = file_get_contents(ROOT_DIR . '/public/sw.js');
        $badgePath = ROOT_DIR . '/public/assets/img/notification-badge.png';

        $this->assertIsString($serviceWorker);
        $this->assertStringContainsString("badge: '/assets/img/notification-badge.png?v=1'", $serviceWorker);
        $this->assertStringNotContainsString("badge: '/assets/img/icon-192.png'", $serviceWorker);
        $this->assertFileExists($badgePath);

        $size = getimagesize($badgePath);
        $this->assertIsArray($size);
        $this->assertSame(96, $size[0]);
        $this->assertSame(96, $size[1]);
        $this->assertSame(IMAGETYPE_PNG, $size[2]);
    }
}
