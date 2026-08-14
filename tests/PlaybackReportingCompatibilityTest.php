<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackReportingCompatibility;
use PHPUnit\Framework\TestCase;

final class PlaybackReportingCompatibilityTest extends TestCase
{
    public function testBrokenOnJellyfin10119WithPluginV17(): void
    {
        $this->assertTrue(PlaybackReportingCompatibility::isBrokenCombo('10.11.9', '17.0.0.0'));
        $this->assertTrue(PlaybackReportingCompatibility::isBrokenCombo('10.11.11', '17.0.0.0'));
        $this->assertTrue(PlaybackReportingCompatibility::isBrokenCombo('10.11.11', '17'));
    }

    public function testNotBrokenWhenPluginIsV18OrJellyfinIsOlder(): void
    {
        $this->assertFalse(PlaybackReportingCompatibility::isBrokenCombo('10.11.8', '17.0.0.0'));
        $this->assertFalse(PlaybackReportingCompatibility::isBrokenCombo('10.11.11', '18.0.0.0'));
        $this->assertFalse(PlaybackReportingCompatibility::isBrokenCombo('10.10.7', '16.0.0.0'));
        $this->assertFalse(PlaybackReportingCompatibility::isBrokenCombo(null, '17.0.0.0'));
        $this->assertFalse(PlaybackReportingCompatibility::isBrokenCombo('10.11.11', null));
    }

    public function testImportStatePrefersAWorkingCustomQuery(): void
    {
        $state = PlaybackReportingCompatibility::importState(true, '10.11.11', '17.0.0.0', true);

        $this->assertFalse($state['broken']);
        $this->assertTrue($state['importable']);
    }

    public function testImportStateMarksBrokenOnVersionComboOrHttp500(): void
    {
        $fromVersion = PlaybackReportingCompatibility::importState(true, '10.11.11', '17.0.0.0', null);
        $this->assertTrue($fromVersion['broken']);
        $this->assertFalse($fromVersion['importable']);

        $fromQuery = PlaybackReportingCompatibility::importState(true, null, null, false);
        $this->assertTrue($fromQuery['broken']);
        $this->assertFalse($fromQuery['importable']);
    }

    public function testImportStateHidesTheButtonWhenThePluginIsMissing(): void
    {
        $state = PlaybackReportingCompatibility::importState(false, '10.11.11', '17.0.0.0', false);

        $this->assertFalse($state['broken']);
        $this->assertFalse($state['importable']);
    }

    public function testCustomQueryBrokenMessageDetection(): void
    {
        $this->assertTrue(PlaybackReportingCompatibility::isCustomQueryBrokenMessage(
            'Playback Reporting API is incompatible with this Jellyfin (needs plugin v18+ on 10.11.9+). Drop a TSV backup instead.'
        ));
        $this->assertTrue(PlaybackReportingCompatibility::isCustomQueryBrokenMessage(
            'Jellyfin request failed with HTTP 500. path=/user_usage_stats/submit_custom_query'
        ));
        $this->assertFalse(PlaybackReportingCompatibility::isCustomQueryBrokenMessage(
            'Jellyfin request failed with HTTP 404. Is the Playback Reporting plugin installed?'
        ));
    }
}
