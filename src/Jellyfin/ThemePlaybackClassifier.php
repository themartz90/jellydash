<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class ThemePlaybackClassifier
{
    public const THEME = 'theme';
    public const ORDINARY = 'ordinary';
    public const PENDING = 'pending';

    /** @var list<string> */
    private const AUDIO_EXTENSIONS = [
        'aac', 'alac', 'flac', 'm4a', 'mp3', 'oga', 'ogg', 'opus', 'wav', 'wma',
    ];

    /** @var list<string> */
    private const VIDEO_EXTENSIONS = [
        'avi', 'm2ts', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'ts', 'webm', 'wmv',
    ];

    /**
     * Returns null when the available metadata cannot safely distinguish a
     * theme item from ordinary playback.
     *
     * @param array<string, mixed> $item
     */
    public function classify(array $item): ?string
    {
        $extraType = strtolower(trim((string) ($item['ExtraType'] ?? $item['extra_type'] ?? '')));
        if (in_array($extraType, ['themesong', 'themevideo'], true)) {
            return self::THEME;
        }

        $type = strtolower(trim((string) ($item['Type'] ?? $item['item_type'] ?? '')));
        if ($type === '') {
            return null;
        }
        if (!in_array($type, ['audio', 'video'], true)) {
            return self::ORDINARY;
        }

        $path = $this->path($item);
        if ($path === '') {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
            return null;
        }

        $normalized = strtolower(str_replace('\\', '/', $path));
        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => $part !== ''));
        $filename = $segments === [] ? '' : (string) $segments[array_key_last($segments)];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $stem = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        $parent = count($segments) > 1 ? $segments[count($segments) - 2] : '';

        if ($type === 'audio' && in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            if ($stem === 'theme' || $parent === 'theme-music') {
                return self::THEME;
            }
        }

        if ($type === 'video'
            && in_array($extension, self::VIDEO_EXTENSIONS, true)
            && $parent === 'backdrops') {
            return self::THEME;
        }

        return self::ORDINARY;
    }

    /** @param array<string, mixed> $item */
    private function path(array $item): string
    {
        $path = trim((string) ($item['Path'] ?? $item['path'] ?? ''));
        if ($path !== '') {
            return $path;
        }

        $sources = $item['MediaSources'] ?? $item['media_sources'] ?? [];
        if (!is_array($sources)) {
            return '';
        }
        $paths = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $path = trim((string) ($source['Path'] ?? $source['path'] ?? ''));
            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return count($paths) === 1 ? (string) reset($paths) : '';
    }
}
