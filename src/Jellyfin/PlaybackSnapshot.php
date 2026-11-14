<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Describes only positively observed playback connections after privacy filtering. */
final class PlaybackSnapshot
{
    /** @param list<array<string, mixed>> $raw @param list<array<string, mixed>> $streams
     * @return array{streams: list<array<string, mixed>>, connections: list<array<string, mixed>>}
     */
    public static function create(array $raw, array $streams, array $ordinaryIds = [], array $ambiguousIds = []): array
    {
        $counts = [];
        foreach ($raw as $session) {
            $id = trim((string) ($session['Id'] ?? ''));
            if ($id !== '') {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        $streams = array_values(array_filter($streams, static fn (array $stream): bool => ($counts[(string) $stream['id']] ?? 1) === 1 && !in_array($stream['id'], $ambiguousIds, true)));
        $active = [];
        foreach ($streams as $stream) {
            $active[(string) $stream['id']] = $stream;
        }
        $connections = [];
        foreach ($raw as $session) {
            $id = (string) ($session['Id'] ?? '');
            if (!self::identifier($id, 128) || ($counts[$id] ?? 0) !== 1 || in_array($id, $ambiguousIds, true)) {
                continue;
            }
            $item = $session['NowPlayingItem'] ?? null;
            $stream = $active[$id] ?? null;
            $idle = $item === null && is_array($session['PlayState'] ?? null)
                && self::identifier($session['UserId'] ?? null, 64) && self::identifier($session['DeviceId'] ?? null, 256)
                && is_string($session['Client'] ?? null) && trim($session['Client']) !== '';
            if (!$idle && ($stream === null || !self::identifier($stream['itemId'] ?? null, 64))) {
                continue;
            }
            if (!$idle && (!self::identifier($session['UserId'] ?? null, 64) || !self::identifier($session['DeviceId'] ?? null, 256)
                || !is_string($session['Client'] ?? null) || trim($session['Client']) === '')) {
                continue;
            }
            $eligible = $idle || !(in_array($stream['itemType'], ['Audio', 'Video'], true)
                && ($stream['themeClassification'] ?? null) !== ThemePlaybackClassifier::ORDINARY
                && (new ThemePlaybackClassifier())->classify($item) !== ThemePlaybackClassifier::ORDINARY
                && !in_array($stream['itemId'], $ordinaryIds, true));
            $server = is_array($item) ? ($item['ServerId'] ?? $session['ServerId'] ?? '') : ($session['ServerId'] ?? '');
            $connections[] = ['id' => $id, 'owner' => hash('sha256', json_encode([
                (string) ($session['UserId'] ?? ''), (string) ($session['DeviceId'] ?? ''), (string) ($session['Client'] ?? ''),
            ], JSON_THROW_ON_ERROR)), 'server' => is_string($server) ? hash('sha256', $server) : hash('sha256', ''),
                'idle' => $idle, 'eligible' => $eligible, 'item' => $idle ? null : (string) $stream['itemId']];
        }
        return ['streams' => $streams, 'connections' => $connections];
    }

    private static function identifier(mixed $value, int $limit): bool
    {
        return is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $limit && !preg_match('/[\x00-\x20\x7f]/u', $value);
    }
}
