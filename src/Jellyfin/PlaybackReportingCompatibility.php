<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Playback Reporting v17 still calls IUserManager.Users. That property is gone
 * on Jellyfin 10.11.9+, and catalog v18 was never published (PR #131).
 */
final class PlaybackReportingCompatibility
{
    public const HELP_URL = 'https://github.com/jellyfin/jellyfin-plugin-playbackreporting/pull/131';
    public const PLUGIN_GUID = '5c534381-91a3-43cb-907a-35aa02eb9d2c';
    public const MIN_JELLYFIN = '10.11.9';
    public const MIN_PLUGIN = '18.0.0.0';

    public static function isBrokenCombo(?string $jellyfinVersion, ?string $pluginVersion): bool
    {
        $jellyfin = self::normalizeVersion($jellyfinVersion);
        $plugin = self::normalizeVersion($pluginVersion);
        if ($jellyfin === '' || $plugin === '') {
            return false;
        }

        return version_compare($jellyfin, self::MIN_JELLYFIN, '>=')
            && version_compare($plugin, self::MIN_PLUGIN, '<');
    }

    /**
     * @return array{broken: bool, importable: bool}
     */
    public static function importState(
        bool $pluginAvailable,
        ?string $jellyfinVersion,
        ?string $pluginVersion,
        ?bool $customQueryOk,
    ): array {
        if (!$pluginAvailable) {
            return ['broken' => false, 'importable' => false];
        }

        if ($customQueryOk === true) {
            return ['broken' => false, 'importable' => true];
        }

        if ($customQueryOk === false || self::isBrokenCombo($jellyfinVersion, $pluginVersion)) {
            return ['broken' => true, 'importable' => false];
        }

        return ['broken' => false, 'importable' => true];
    }

    public static function isCustomQueryBrokenMessage(string $message): bool
    {
        return str_contains($message, 'plugin v18')
            || (str_contains($message, 'HTTP 500') && str_contains($message, 'submit_custom_query'));
    }

    public static function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);
        if ($version === '') {
            return '';
        }

        if (preg_match('/^(\d+(?:\.\d+)*)/', $version, $match) !== 1) {
            return '';
        }

        return $match[1];
    }
}
