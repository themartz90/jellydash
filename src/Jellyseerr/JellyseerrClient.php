<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Config;
use Mk\Framework\Http\RemoteRequestException;

/**
 * Thin client over the Jellyseerr (Overseerr-compatible) HTTP API.
 *
 * Authenticates with an `X-Api-Key` header. The key stays server-side.
 * Request-record writes and library file removal use separate endpoints.
 * Direct library-service access only reads and verifies a removal target.
 */
class JellyseerrClient
{
    private string $baseUrl;
    private string $apiKey;
    private bool $verifySsl;

    public function __construct(
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?bool $verifySsl = null,
        private int $readTimeoutMilliseconds = 10_000,
    ) {
        // Env keys match the spelling already used in .env (JELLYSEER_*).
        $this->baseUrl = rtrim((string) ($baseUrl ?? Config::get('JELLYSEER_URL', '')), '/');
        $this->apiKey = (string) ($apiKey ?? Config::get('JELLYSEER_API_TOKEN', ''));
        $this->verifySsl = $verifySsl ?? Config::bool('JELLYSEER_VERIFY_SSL', true);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function cacheIdentity(): string
    {
        return hash('sha256', serialize([$this->baseUrl, $this->apiKey, $this->verifySsl]));
    }

    /**
     * Most recently added requests. The list already carries the live request
     * and media status, so refreshing statuses costs a single call.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requests(int $take = 30): array
    {
        return $this->requestPage($take, 0);
    }

    /**
     * One page of requests, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requestPage(int $take, int $skip): array
    {
        $payload = $this->get('/api/v1/request', [
            'take' => max(1, $take),
            'skip' => max(0, $skip),
            'sort' => 'added',
        ]);

        $results = $payload['results'] ?? null;

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * Browse an upstream page independently of the poller's mirror.
     *
     * @return array{pageInfo: array{pages: int, pageSize: int, results: int, page: int}, results: list<array<string, mixed>>}
     */
    public function requestPageWithMetadata(int $page, string $filter = 'all', int $pageSize = 24): array
    {
        // Seerr parses the offset and size as JavaScript numbers.
        $maxInteger = (int) min(PHP_INT_MAX, 9_007_199_254_740_991);
        if ($page < 1 || $page > $maxInteger || $pageSize < 1 || $pageSize > $maxInteger
            || $page - 1 > intdiv($maxInteger, $pageSize)) {
            throw new \InvalidArgumentException('Invalid Seerr pagination.');
        }
        if (!in_array($filter, ['all', 'pending', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid Seerr request filter.');
        }

        $payload = $this->get('/api/v1/request', [
            'take' => $pageSize,
            'skip' => ($page - 1) * $pageSize,
            'sort' => 'added',
            'filter' => $filter,
        ]);
        $pageInfo = $payload['pageInfo'] ?? null;
        $results = $payload['results'] ?? null;
        if (!is_array($pageInfo) || ($pageInfo['page'] ?? null) !== $page || ($pageInfo['pageSize'] ?? null) !== $pageSize
            || !is_int($pageInfo['pages'] ?? null) || $pageInfo['pages'] < 0
            || !is_int($pageInfo['results'] ?? null) || $pageInfo['results'] < 0
            || !is_array($results) || !array_is_list($results) || count($results) > $pageSize
            || count(array_filter($results, 'is_array')) !== count($results)) {
            throw new \RuntimeException('Seerr returned an unexpected request page.');
        }

        return [
            'pageInfo' => [
                'pages' => $pageInfo['pages'],
                'pageSize' => $pageSize,
                'results' => $pageInfo['results'],
                'page' => $page,
            ],
            'results' => $results,
        ];
    }

    /** @return array<string, mixed> */
    public function requestDetail(int $requestId): array
    {
        $request = $this->get(self::requestPath($requestId));
        if (($request['id'] ?? null) !== $requestId) {
            throw new \RuntimeException('Seerr returned an unexpected request ID.');
        }

        return $request;
    }

    public function approve(int $requestId): void
    {
        $this->changeStatus($requestId, 'approve', RequestMapper::REQUEST_APPROVED);
    }

    public function decline(int $requestId): void
    {
        $this->changeStatus($requestId, 'decline', RequestMapper::REQUEST_DECLINED);
    }

    public function retry(int $requestId): void
    {
        $this->changeStatus($requestId, 'retry', RequestMapper::REQUEST_APPROVED);
    }

    public function deleteRequest(int $requestId): void
    {
        $response = $this->send(self::requestPath($requestId), 'DELETE', timeout: 5_000);
        if ($response['status'] !== 204) {
            throw new \RuntimeException('Seerr returned an unexpected deletion response.');
        }
    }

    public function removeLibraryFiles(int $mediaId, bool $is4k): void
    {
        if (RequestLink::id($mediaId) === null) {
            throw new \InvalidArgumentException('Invalid Seerr media ID.');
        }
        $response = $this->send('/api/v1/media/' . $mediaId . '/file', 'DELETE', ['is4k' => $is4k ? 'true' : 'false'], timeout: 10_000);
        if ($response['status'] !== 204) {
            throw new \RuntimeException('Seerr returned an unexpected file removal response.');
        }
    }

    public function libraryRemovalVersion(): ?string
    {
        $version = $this->get('/api/v1/settings/about')['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /** @return array<array-key, mixed> */
    public function libraryServices(string $mediaType): array
    {
        $path = match ($mediaType) {
            'movie' => 'radarr',
            'tv' => 'sonarr',
            default => throw new \InvalidArgumentException('Invalid library media type.'),
        };

        return $this->get('/api/v1/settings/' . $path);
    }

    /**
     * @param array{url: string, key: string} $service
     * @return array<array-key, mixed>
     */
    public function libraryLookup(array $service, string $kind, int $providerId): array
    {
        $client = new self($service['url'], $service['key'], true, $this->readTimeoutMilliseconds);

        return $client->get('/api/v3/' . $kind . '/lookup', ['term' => ($kind === 'movie' ? 'tmdb:' : 'tvdb:') . $providerId]);
    }

    /** @param array{url: string, key: string} $service @return array<string, mixed>|null */
    public function libraryEntry(array $service, string $kind, int $id): ?array
    {
        $client = new self($service['url'], $service['key'], true, $this->readTimeoutMilliseconds);
        try {
            $entry = $client->get('/api/v3/' . $kind . '/' . $id);
            if (($entry['id'] ?? null) !== $id) {
                throw new \RuntimeException('Unexpected library entry.');
            }
            return $entry;
        } catch (RemoteRequestException $error) {
            if ($error->getCode() === 404) {
                return null;
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $payload */
    public function editRequest(int $requestId, array $payload): bool
    {
        $response = $this->send(self::requestPath($requestId), 'PUT', timeout: 5_000, payload: $payload);

        return $response['status'] !== 202;
    }

    /** @return array<array-key, mixed> */
    public function requestServices(string $mediaType, ?int $id = null): array
    {
        $service = match ($mediaType) {
            'movie' => 'radarr',
            'tv' => 'sonarr',
            default => throw new \InvalidArgumentException('Invalid Seerr media type.'),
        };

        return $this->get('/api/v1/service/' . $service . ($id === null ? '' : '/' . $id));
    }

    /** @return array<string, mixed> */
    public function publicSettings(): array
    {
        return $this->get('/api/v1/settings/public');
    }

    private static function requestPath(int $requestId): string
    {
        if ($requestId < 1 || $requestId > 9_007_199_254_740_991) {
            throw new \InvalidArgumentException('Invalid Seerr request ID.');
        }

        return '/api/v1/request/' . $requestId;
    }

    private function changeStatus(int $requestId, string $operation, int $status): void
    {
        $response = $this->send(self::requestPath($requestId) . '/' . $operation, 'POST', timeout: 5_000);
        $request = self::decode($response['body']);
        if (($request['id'] ?? null) !== $requestId || ($request['status'] ?? null) !== $status) {
            throw new \RuntimeException('Seerr returned an unexpected updated request.');
        }
    }

    /**
     * TMDB details for a movie: title, releaseDate, posterPath.
     *
     * @return array<string, mixed>
     */
    public function movie(int $tmdbId): array
    {
        return $this->get('/api/v1/movie/' . $tmdbId);
    }

    /**
     * TMDB details for a series: name, firstAirDate, posterPath.
     *
     * @return array<string, mixed>
     */
    public function tv(int $tmdbId): array
    {
        return $this->get('/api/v1/tv/' . $tmdbId);
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return self::decode($this->send($path, 'GET', $query, $this->readTimeoutMilliseconds)['body']);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string}
     */
    private function send(string $path, string $method, array $query = [], int $timeout = 10_000, ?array $payload = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Jellyseerr URL or API key is missing.');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                ...($payload !== null ? ['Content-Type: application/json'] : []),
                'X-Api-Key: ' . $this->apiKey,
            ],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT_MS => min(3_000, $timeout),
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        } elseif ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, '');
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new \RuntimeException('Jellyseerr request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RemoteRequestException('Jellyseerr request failed with HTTP ' . $status . '.', $status);
        }

        return ['body' => (string) $body, 'status' => $status];
    }

    /** @return array<string, mixed> */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Jellyseerr returned invalid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Jellyseerr returned an unexpected payload.');
        }

        return $decoded;
    }
}
