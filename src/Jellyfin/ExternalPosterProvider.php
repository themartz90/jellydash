<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Searches the configured Jellyfin's TMDB provider without changing its library. */
final class ExternalPosterProvider
{
    public function __construct(private ?JellyfinClient $client = null)
    {
    }

    /** @return array{id: string, kind: string, title: string, year: ?int, path: string} */
    public function byId(string $kind, string $id): array
    {
        self::providerId($id);
        $results = $this->query($kind, ['ProviderIds' => ['Tmdb' => $id]], true);
        if (count($results) !== 1 || $results[0]['id'] !== $id) {
            throw new \RuntimeException('no_exact_match');
        }
        return $results[0];
    }

    /** @return list<array{id: string, kind: string, title: string, year: ?int, path: string}> */
    public function search(string $kind, string $title, ?int $year = null): array
    {
        $title = trim($title);
        if ($title === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 160
            || ($year !== null && ($year < 1000 || $year > 9999))) {
            throw new \InvalidArgumentException('Invalid poster search.');
        }
        $info = ['Name' => $title];
        if ($year !== null) {
            $info['Year'] = $year;
        }
        return $this->query($kind, $info);
    }

    /** @param array<string, mixed> $info
     * @return list<array{id: string, kind: string, title: string, year: ?int, path: string}>
     */
    private function query(string $kind, array $info, bool $exact = false): array
    {
        $expires = time() + ArtworkRepository::EXTERNAL_LIFETIME;
        if (!in_array($kind, ['Movie', 'Series'], true)) {
            throw new \InvalidArgumentException('Invalid poster type.');
        }
        $response = ($this->client ?? new JellyfinClient())->postJson(
            '/Items/RemoteSearch/' . $kind,
            ['SearchInfo' => $info, 'SearchProviderName' => 'TheMovieDb', 'IncludeDisabledProviders' => false],
            4,
            524288
        );
        if (!is_array($response) || !array_is_list($response)) {
            throw new \RuntimeException('provider_response');
        }
        if ($exact && count($response) !== 1) {
            throw new \RuntimeException('no_exact_match');
        }
        $results = [];
        foreach (array_slice($response, 0, 20) as $item) {
            if (!is_array($item) || !is_array($item['ProviderIds'] ?? null) || !is_string($item['Name'] ?? null)
                || !mb_check_encoding($item['Name'], 'UTF-8') || mb_strlen($item['Name']) > 16383
                || (isset($item['SearchProviderName']) && $item['SearchProviderName'] !== 'TheMovieDb')
                || (isset($item['Type']) && $item['Type'] !== $kind)) {
                continue;
            }
            $ids = [];
            foreach ($item['ProviderIds'] as $provider => $value) {
                if (is_string($provider) && strcasecmp($provider, 'Tmdb') === 0) {
                    $ids[] = $value;
                }
            }
            $id = $ids[0] ?? null;
            $path = is_string($item['ImageUrl'] ?? null) ? self::posterPath($item['ImageUrl']) : null;
            if (count($ids) !== 1 || !is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/D', $id) || $path === null) {
                continue;
            }
            $results[] = ['id' => $id, 'kind' => $kind, 'title' => $item['Name'],
                'year' => is_int($item['ProductionYear'] ?? null) ? $item['ProductionYear'] : null, 'path' => $path, 'expires_at' => $expires];
        }
        return $results;
    }

    public static function providerId(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid provider identity.');
        }
    }

    public static function posterPath(string $url): ?string
    {
        if (strlen($url) > 512 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'image.tmdb.org'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~^/t/p/(?:original|w[1-9][0-9]{1,3})/([A-Za-z0-9]{1,128}\.(?:jpg|jpeg|png|webp))$~D', $parts['path'] ?? '', $match)) {
            return null;
        }
        return $match[1];
    }

    /** @return array{body: string, contentType: string} */
    public function fetch(string $path): array
    {
        if (self::posterPath('https://image.tmdb.org/t/p/w342/' . $path) !== $path) {
            throw new \InvalidArgumentException('Invalid poster path.');
        }
        $handle = curl_init('https://image.tmdb.org/t/p/w342/' . $path);
        if ($handle === false) {
            throw new \RuntimeException('request_failed');
        }
        $body = '';
        $oversized = false;
        $retryAfter = 0;
        curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $retryAfter = 0;
                } elseif (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = max($retryAfter, \Mk\Framework\Http\RemoteRequestException::parseRetryAfter(substr($line, 12), time()));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > ArtworkRepository::MAX_IMAGE_BYTES) {
                    $oversized = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);
        if ($oversized) {
            if (in_array($status, [429, 503], true)) {
                throw new \Mk\Framework\Http\RemoteRequestException($status === 429 ? 'throttled' : 'request_failed', $status, $retryAfter);
            }
            throw new \RuntimeException('oversized');
        }
        if ($success === false || $status !== 200) {
            throw new \Mk\Framework\Http\RemoteRequestException($status === 429 ? 'throttled' : 'request_failed', $status, $retryAfter);
        }
        return ArtworkFetcher::validate($body, $type);
    }
}
