<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Sanitizes and persists the small artwork identity snapshot captured by History. */
final class ArtworkObservation
{
    /** @return array{source: string, server: string, series: ?string, tag: ?string, observedAt: int}|null */
    public static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($value)) {
            return null;
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['observedAt', 'series', 'server', 'source', 'tag']) {
            return null;
        }

        $source = $value['source'];
        $server = $value['server'];
        $series = $value['series'];
        $tag = $value['tag'];
        $observedAt = $value['observedAt'];
        if (!is_string($source) || !self::validSource($source)
            || !is_string($server) || ($server !== '' && !self::validId($server))
            || ($series !== null && (!is_string($series) || !self::validId($series)))
            || ($tag !== null && (!is_string($tag) || !self::validTag($tag)))
            || !is_int($observedAt) || $observedAt <= 0) {
            return null;
        }

        return ['source' => $source, 'server' => $server, 'series' => $series, 'tag' => $tag, 'observedAt' => $observedAt];
    }

    /** @param array<string, mixed> $stream */
    public static function encode(array $stream, \DateTimeImmutable $observedAt, ?string $previous = null): ?string
    {
        if (!in_array($stream['itemType'] ?? null, ['Movie', 'Episode'], true)) {
            return null;
        }
        $source = $stream['artworkSource'] ?? null;
        if (!is_string($source) || !self::validSource($source)) {
            return null;
        }

        $server = self::optionalId($stream['artworkServerId'] ?? null) ?? '';
        $previousObservation = self::decode($previous);
        if ($previousObservation !== null && ($previousObservation['source'] !== $source
            || ($server !== '' && $previousObservation['server'] !== '' && $server !== $previousObservation['server']))) {
            $previousObservation = null;
        }

        $series = self::optionalId($stream['seriesId'] ?? null);
        $tag = self::optionalTag($stream['posterTag'] ?? null);
        if ($previousObservation !== null) {
            if ($server === '') {
                $server = $previousObservation['server'];
            }
            $series ??= $previousObservation['series'];
            $tag ??= $previousObservation['tag'];
        }

        return json_encode([
            'source' => $source,
            'server' => $server,
            'series' => $series,
            'tag' => $tag,
            'observedAt' => (int) $observedAt->format('Uu'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $stream @return array<string, string> */
    public static function streamFields(array $stream): array
    {
        $fields = [];
        $source = $stream['artworkSource'] ?? null;
        if (is_string($source) && self::validSource($source)) {
            $fields['artworkSource'] = $source;
        }
        if (array_key_exists('artworkServerId', $stream)) {
            $fields['artworkServerId'] = self::optionalId($stream['artworkServerId']) ?? '';
        }
        foreach (['seriesId' => 'optionalId', 'posterTag' => 'optionalTag'] as $field => $validator) {
            $value = self::$validator($stream[$field] ?? null);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    private static function validSource(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function validId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) === 1;
    }

    private static function validTag(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $value) === 1;
    }

    private static function optionalId(mixed $value): ?string
    {
        return is_string($value) && self::validId($value) ? $value : null;
    }

    private static function optionalTag(mixed $value): ?string
    {
        return is_string($value) && self::validTag($value) ? $value : null;
    }
}
