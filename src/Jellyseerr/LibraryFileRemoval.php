<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

/** Resolves the target of the supported Seerr file routes before any write. */
final class LibraryFileRemoval
{
    public function __construct(private JellyseerrClient $client)
    {
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    public function target(array $request): ?array
    {
        $card = RequestMapper::display($request);
        $version = RequestManager::version($request, $card);
        $type = $card['mediaType'];
        $media = $request['media'] ?? null;
        if ($version === null || !is_array($media) || $type === null || ($media['mediaType'] ?? null) !== $type
            || !self::available($card) || $card['is4k'] === null || $card['mediaId'] === null || $card['tmdbId'] === null) {
            return null;
        }
        $is4k = $card['is4k'];
        $suffix = $is4k ? '4k' : '';
        $recorded = $media['serviceId' . $suffix] ?? null;
        $arrId = RequestLink::id($media['externalServiceId' . $suffix] ?? null);
        if (!is_int($recorded) || $recorded < 0 || $arrId === null) {
            return null;
        }
        $upstream = $this->client->libraryRemovalVersion();
        // Legacy compares the query to a string even though its API schema
        // coerces it to a boolean. A 4K request can reach the Standard route.
        if (!in_array($upstream, ['3.5.2', '2.7.3'], true) || ($upstream === '2.7.3' && $is4k)) {
            return null;
        }
        $routeId = $media[$upstream === '3.5.2' ? 'serviceId' . $suffix : 'serviceId'] ?? null;
        if ($routeId !== null && !is_int($routeId)) {
            return null;
        }
        $services = $this->client->libraryServices($type);
        $service = self::resolveService($services, $is4k, $recorded, $routeId);
        if ($service === null) {
            return null;
        }

        $tmdbId = $card['tmdbId'];
        $movie = $type === 'movie';
        $kind = $movie ? 'movie' : 'series';
        $details = $movie ? $this->client->movie($tmdbId) : $this->client->tv($tmdbId);
        $title = $details[$movie ? 'title' : 'name'] ?? null;
        $providerId = $movie ? $tmdbId : RequestLink::id($media['tvdbId'] ?? null);
        if (($details['id'] ?? null) !== $tmdbId || !is_string($title) || trim($title) === '' || $providerId === null) {
            return null;
        }
        if (!$movie && ($details['externalIds']['tvdbId'] ?? null) !== $providerId) {
            return null;
        }
        if ($movie && $upstream === '2.7.3' && RequestLink::id($media['externalServiceSlug' . $suffix] ?? null) !== $tmdbId) {
            return null;
        }
        $matches = $this->client->libraryLookup($service['connection'], $kind, $providerId);
        $match = $matches[0] ?? null;
        $identityField = $movie ? 'tmdbId' : 'tvdbId';
        if (!array_is_list($matches) || count($matches) !== 1 || !is_array($match)
            || ($match['id'] ?? null) !== $arrId || ($match[$identityField] ?? null) !== $providerId) {
            return null;
        }
        $entry = $this->client->libraryEntry($service['connection'], $kind, $arrId);
        $files = $movie ? (($entry['hasFile'] ?? null) === true ? 1 : 0) : ($entry['statistics']['episodeFileCount'] ?? null);
        if ($entry === null || ($entry[$identityField] ?? null) !== $providerId
            || !is_int($files) || $files <= 0 || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
            return null;
        }
        $otherPath = $this->otherEditionPath($media, $services, $is4k, $kind, $identityField, $providerId);
        if ($otherPath === false || ($otherPath !== null && self::overlaps($entry['path'], $otherPath))) {
            return null;
        }
        $binding = self::binding($request);
        $label = mb_strimwidth(trim($title), 0, 240);
        $token = hash('sha256', serialize([
            $version, $binding, $upstream, $recorded, $service['connection']['url'], $service['name'],
            $providerId, $arrId, $entry['path'], $otherPath, $files, $label,
        ]));

        return [
            'token' => $token, 'binding' => $binding, 'upstream' => $upstream,
            'mediaId' => $card['mediaId'], 'requestId' => $card['requestId'], 'is4k' => $is4k,
            'type' => $type, 'kind' => $kind, 'arrId' => $arrId, 'connection' => $service['connection'],
            'service' => $service['name'], 'title' => $label,
        ];
    }

    /** @param array<string, mixed> $media @param array<array-key, mixed> $services */
    private function otherEditionPath(array $media, array $services, bool $is4k, string $kind, string $identityField, int $providerId): string|false|null
    {
        $suffix = $is4k ? '' : '4k';
        $available = in_array($media['status' . $suffix] ?? null, [4, 5], true);
        $id = RequestLink::id($media['externalServiceId' . $suffix] ?? null);
        if ($id === null) {
            return $available ? false : null;
        }
        $serviceId = $media['serviceId' . $suffix] ?? null;
        foreach ($services as $service) {
            if (is_int($serviceId) && $service['id'] === $serviceId && $service['is4k'] !== $is4k) {
                $connection = self::connection($service);
                if ($connection === null) {
                    return false;
                }
                $other = $this->client->libraryEntry($connection, $kind, $id);
                if ($other === null) {
                    return $available ? false : null;
                }
                $path = $other['path'] ?? null;

                return ($other[$identityField] ?? null) === $providerId && is_string($path) && $path !== '' ? $path : false;
            }
        }

        return false;
    }

    private static function overlaps(string $path, string $other): bool
    {
        $left = rtrim(strtolower(str_replace('\\', '/', $path)), '/') . '/';
        $right = rtrim(strtolower(str_replace('\\', '/', $other)), '/') . '/';

        return str_starts_with($left, $right) || str_starts_with($right, $left);
    }

    /** @param array<string, mixed> $card */
    public static function available(array $card): bool
    {
        return in_array($card['mediaStatus'], $card['mediaType'] === 'tv'
            ? [RequestMapper::MEDIA_PARTIALLY_AVAILABLE, RequestMapper::MEDIA_AVAILABLE] : [RequestMapper::MEDIA_AVAILABLE], true);
    }

    /** @param array<string, mixed> $request */
    public static function binding(array $request): string
    {
        $media = is_array($request['media'] ?? null) ? $request['media'] : [];

        return hash('sha256', serialize(array_intersect_key($media, array_flip([
            'id', 'tmdbId', 'tvdbId', 'mediaType', 'status', 'status4k', 'serviceId', 'serviceId4k',
            'externalServiceId', 'externalServiceId4k', 'externalServiceSlug', 'externalServiceSlug4k', 'seasons',
        ]))));
    }

    /**
     * @param array<array-key, mixed> $services
     * @return array{name: string, connection: array{url: string, key: string}}|null
     */
    private static function resolveService(array $services, bool $is4k, int $recorded, ?int $routeId): ?array
    {
        if (!array_is_list($services)) {
            return null;
        }
        $byId = [];
        $defaults = [];
        foreach ($services as $service) {
            $id = $service['id'] ?? null;
            if (!is_int($id) || $id < 0 || isset($byId[$id])
                || !is_bool($service['is4k'] ?? null) || !is_bool($service['isDefault'] ?? null)) {
                return null;
            }
            $byId[$id] = $service;
            if ($service['isDefault'] && $service['is4k'] === $is4k) {
                $defaults[] = $id;
            }
        }
        // Both routes skip a recorded zero when overriding the edition's default.
        $effective = $routeId !== null && $routeId > 0 ? $routeId : (count($defaults) === 1 ? $defaults[0] : null);
        $server = $byId[$recorded] ?? null;
        if ($effective !== $recorded || $server === null || $server['is4k'] !== $is4k
            || !is_string($server['name'] ?? null) || trim($server['name']) === '') {
            return null;
        }
        $connection = self::connection($server);
        if ($connection === null) {
            return null;
        }
        foreach ($byId as $id => $other) {
            if ($id === $recorded) {
                continue;
            }
            $candidate = self::connection($other);
            if ($candidate !== null && ($candidate['url'] === $connection['url'] || $candidate['key'] === $connection['key'])) {
                return null;
            }
        }

        return ['name' => mb_strimwidth(trim($server['name']), 0, 120), 'connection' => $connection];
    }

    /** @param array<string, mixed> $settings @return array{url: string, key: string}|null */
    private static function connection(array $settings): ?array
    {
        $host = $settings['hostname'] ?? null;
        $port = $settings['port'] ?? null;
        $secure = $settings['useSsl'] ?? null;
        $base = $settings['baseUrl'] ?? '';
        $key = $settings['apiKey'] ?? null;
        if (!is_string($host) || preg_match('/^(?:[a-z0-9][a-z0-9.-]*|\[[0-9a-f:]+\])$/iD', $host) !== 1
            || !is_int($port) || $port < 1 || $port > 65535 || !is_bool($secure)
            || !is_string($base) || preg_match('~^(?:/[a-zA-Z0-9_-]+)*$~D', $base) !== 1
            || !is_string($key) || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $key) !== 1) {
            return null;
        }

        return ['url' => ($secure ? 'https://' : 'http://') . strtolower(rtrim($host, '.')) . ':' . $port . rtrim($base, '/'), 'key' => $key];
    }
}
