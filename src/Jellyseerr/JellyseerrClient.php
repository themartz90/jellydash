<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Config;

/**
 * Thin client over the Jellyseerr (Overseerr-compatible) HTTP API.
 *
 * Authenticates with an `X-Api-Key` header; the key stays server-side and never
 * reaches the browser. Only the read endpoints the Requests page needs are
 * exposed; the integration is deliberately read-only.
 */
class JellyseerrClient
{
    private string $baseUrl;
    private string $apiKey;
    private bool $verifySsl;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?bool $verifySsl = null)
    {
        // Env keys match the spelling already used in .env (JELLYSEER_*).
        $this->baseUrl = rtrim((string) ($baseUrl ?? Config::get('JELLYSEER_URL', '')), '/');
        $this->apiKey = (string) ($apiKey ?? Config::get('JELLYSEER_API_TOKEN', ''));
        $this->verifySsl = $verifySsl ?? Config::bool('JELLYSEER_VERIFY_SSL', true);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
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
                'X-Api-Key: ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new \RuntimeException('Jellyseerr request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Jellyseerr request failed with HTTP ' . $status . '.');
        }

        try {
            $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Jellyseerr returned invalid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Jellyseerr returned an unexpected payload.');
        }

        return $decoded;
    }
}
