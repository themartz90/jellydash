<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;

final class JellyfinClient
{
    private string $baseUrl;
    private string $token;
    private bool $verifySsl;

    /** @var array<string, array<int, string>>|null lowercased library name => physical locations */
    private static ?array $libraryLocations = null;

    /** @var array<int, string>|null Original-case library names, for display. */
    private static ?array $libraryNames = null;

    /** @var array<string, string> per-process cache of itemId => file path */
    private static array $itemPathCache = [];

    public function __construct(?string $baseUrl = null, ?string $token = null, ?bool $verifySsl = null)
    {
        $this->baseUrl = rtrim((string) ($baseUrl ?? Config::get('JELLYFIN_URL', '')), '/');
        $this->token = (string) ($token ?? Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', '')));
        $this->verifySsl = $verifySsl ?? Config::bool('JELLYFIN_VERIFY_SSL', true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessions(): array
    {
        $payload = $this->getJson('/Sessions');

        if (!is_array($payload)) {
            throw new \RuntimeException('Jellyfin /Sessions returned an invalid payload.');
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mediaFolders(): array
    {
        $payload = $this->getJson('/Library/MediaFolders');
        $items = is_array($payload) && is_array($payload['Items'] ?? null) ? $payload['Items'] : [];

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param array<string, string|int|bool> $query
     * @return array<int, array<string, mixed>>
     */
    public function items(array $query): array
    {
        $items = [];
        $start = 0;
        $limit = 500;

        do {
            $payload = $this->getJson('/Items?' . http_build_query(
                array_merge($query, ['StartIndex' => $start, 'Limit' => $limit]),
                '',
                '&',
                PHP_QUERY_RFC3986
            ));

            $pageItems = is_array($payload) && is_array($payload['Items'] ?? null) ? array_values(array_filter($payload['Items'], 'is_array')) : [];
            $total = is_array($payload) ? (int) ($payload['TotalRecordCount'] ?? count($pageItems)) : count($pageItems);
            array_push($items, ...$pageItems);
            $start += $limit;
        } while ($start < $total && $pageItems !== []);

        return $items;
    }

    /**
     * @param array<string, string|int|bool> $query
     */
    public function itemCount(array $query): int
    {
        $payload = $this->getJson('/Items?' . http_build_query(
            array_merge($query, ['StartIndex' => 0, 'Limit' => 0]),
            '',
            '&',
            PHP_QUERY_RFC3986
        ));

        return is_array($payload) ? (int) ($payload['TotalRecordCount'] ?? 0) : 0;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Jellyfin users (id + name). Needs an admin API token.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function users(): array
    {
        $payload = $this->getJson('/Users');
        if (!is_array($payload)) {
            return [];
        }

        $users = [];
        foreach ($payload as $user) {
            if (!is_array($user)) {
                continue;
            }
            $id = (string) ($user['Id'] ?? '');
            $name = trim((string) ($user['Name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $users[] = ['id' => $id, 'name' => $name];
            }
        }

        return $users;
    }

    /**
     * Map of lowercased library name => its physical folder locations, from
     * Jellyfin's VirtualFolders. Used to map an item's path back to a library.
     * Cached per process. Requires an admin API token.
     *
     * @return array<string, array<int, string>>
     */
    public function libraryLocations(): array
    {
        if (self::$libraryLocations !== null) {
            return self::$libraryLocations;
        }

        self::$libraryLocations = [];
        self::$libraryNames = [];
        $payload = $this->getJson('/Library/VirtualFolders');

        if (is_array($payload)) {
            foreach ($payload as $folder) {
                if (!is_array($folder)) {
                    continue;
                }
                $displayName = trim((string) ($folder['Name'] ?? ''));
                $name = mb_strtolower($displayName);
                $locations = is_array($folder['Locations'] ?? null)
                    ? array_values(array_filter(array_map('strval', $folder['Locations'])))
                    : [];
                if ($name !== '' && $locations !== []) {
                    self::$libraryLocations[$name] = $locations;
                    self::$libraryNames[] = $displayName;
                }
            }
        }

        return self::$libraryLocations;
    }

    /**
     * Library names in their original casing (the locations map lowercases its
     * keys for case-insensitive matching; these are for display).
     *
     * @return array<int, string>
     */
    public function libraryNames(): array
    {
        if (self::$libraryNames === null) {
            $this->libraryLocations();
        }

        return self::$libraryNames ?? [];
    }

    /**
     * Runtime and real library name for items that still exist in Jellyfin.
     * Library is resolved from the item path against VirtualFolders. Missing
     * items are omitted; items with no matching folder keep library = ''.
     *
     * @param array<int, string> $ids
     * @return array<string, array{runtime_sec: int, library: string}>
     */
    public function itemImportMeta(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $normalized = $this->normalizedItemId((string) $id);
            if ($normalized !== '') {
                $unique[$normalized] = $normalized;
            }
        }

        $locations = [];
        $names = [];
        $librariesLoaded = false;
        $meta = [];
        foreach (array_chunk(array_values($unique), 100) as $chunk) {
            $payload = $this->getJson('/Items?' . http_build_query(
                [
                    'Ids' => implode(',', $chunk),
                    'Fields' => 'RunTimeTicks,Path',
                    'Limit' => count($chunk),
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            ), 15);

            $items = is_array($payload) && is_array($payload['Items'] ?? null)
                ? array_values(array_filter($payload['Items'], 'is_array'))
                : [];

            foreach ($items as $item) {
                $id = $this->normalizedItemId((string) ($item['Id'] ?? ''));
                if ($id === '') {
                    continue;
                }

                $ticks = (int) ($item['RunTimeTicks'] ?? 0);
                $runtime = $ticks > 0 ? (int) floor($ticks / 10000000) : 0;
                $path = (string) ($item['Path'] ?? '');
                if ($path !== '' && !$librariesLoaded) {
                    try {
                        $locations = $this->libraryLocations();
                        $names = $this->libraryNames();
                    } catch (\Throwable) {
                        $locations = [];
                        $names = [];
                    }
                    $librariesLoaded = true;
                }

                $library = $path !== ''
                    ? ($this->libraryNameForPath($path, $locations, $names) ?? '')
                    : '';

                if ($runtime <= 0 && $library === '') {
                    continue;
                }

                $meta[$id] = [
                    'runtime_sec' => $runtime,
                    'library' => $library,
                ];
            }
        }

        return $meta;
    }

    /**
     * Original-case library name whose folder contains $path. Longest prefix
     * wins when libraries nest. Pass $locations / $names to avoid a Jellyfin
     * round-trip (keys of $locations are lowercased library names).
     *
     * @param array<string, array<int, string>>|null $locations
     * @param array<int, string>|null $names
     */
    public function libraryNameForPath(string $path, ?array $locations = null, ?array $names = null): ?string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '') {
            return null;
        }

        $locations ??= $this->libraryLocations();
        $names ??= $this->libraryNames();

        $bestName = null;
        $bestLen = -1;
        foreach ($locations as $lowerName => $prefixes) {
            foreach ($prefixes as $prefix) {
                $prefix = rtrim(str_replace('\\', '/', (string) $prefix), '/');
                if ($prefix === '') {
                    continue;
                }
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    $len = strlen($prefix);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $bestName = (string) $lowerName;
                    }
                }
            }
        }

        if ($bestName === null) {
            return null;
        }

        foreach ($names as $name) {
            if (mb_strtolower((string) $name) === $bestName) {
                return (string) $name;
            }
        }

        return $bestName;
    }

    /**
     * Jellyfin sometimes returns Ids with dashes and sometimes without.
     */
    private function normalizedItemId(string $id): string
    {
        return strtolower(str_replace('-', '', trim($id)));
    }

    /**
     * Absolute file path of an item, or '' if unavailable. Cached per process.
     */
    public function itemPath(string $itemId): string
    {
        if ($itemId === '') {
            return '';
        }

        if (array_key_exists($itemId, self::$itemPathCache)) {
            return self::$itemPathCache[$itemId];
        }

        $payload = $this->getJson('/Items?' . http_build_query(
            ['Ids' => $itemId, 'Fields' => 'Path', 'Limit' => 1],
            '',
            '&',
            PHP_QUERY_RFC3986
        ));

        $path = is_array($payload) && is_array($payload['Items'][0] ?? null)
            ? (string) ($payload['Items'][0]['Path'] ?? '')
            : '';

        return self::$itemPathCache[$itemId] = $path;
    }

    /**
     * @return mixed
     */
    public function getJson(string $path, int $timeout = 8): mixed
    {
        return $this->requestJson($path, 'GET', null, $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    public function postJson(string $path, array $payload, int $timeout = 8): mixed
    {
        return $this->requestJson($path, 'POST', $payload, $timeout);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return mixed
     */
    private function requestJson(string $path, string $method = 'GET', ?array $payload = null, int $timeout = 8): mixed
    {
        if ($this->baseUrl === '' || $this->token === '') {
            throw new \RuntimeException('Jellyfin URL or API token is missing.');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }

        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize cURL.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: MediaBrowser Token="' . $this->token . '"',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];

        if (strtoupper($method) === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new \RuntimeException('Jellyfin request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($this->httpErrorMessage($path, $status));
        }

        try {
            return json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Jellyfin returned invalid JSON.', previous: $e);
        }
    }

    private function httpErrorMessage(string $path, int $status): string
    {
        return 'Jellyfin request failed with HTTP ' . $status . ' (' . $path . ').';
    }
}
