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
     * @return array<int, array<string, mixed>>
     */
    public function users(): array
    {
        $payload = $this->getJson('/Users');
        if (!is_array($payload)) {
            return [];
        }

        return array_values(array_filter($payload, 'is_array'));
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
    private function getJson(string $path): mixed
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

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: MediaBrowser Token="' . $this->token . '"',
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new \RuntimeException('Jellyfin request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Jellyfin request failed with HTTP ' . $status . '.');
        }

        try {
            return json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Jellyfin returned invalid JSON.', previous: $e);
        }
    }
}
