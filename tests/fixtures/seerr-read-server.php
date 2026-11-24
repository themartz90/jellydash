<?php

declare(strict_types=1);

// Disposable request route and entity shapes from Seerr v3.5.2 and Jellyseerr v2.7.3.
$isSeerr = ($argv[1] ?? null) === '3.5.2';
$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}
echo stream_socket_get_name($server, false) . PHP_EOL;
fflush(STDOUT);

$movie = ['id' => 401, 'tmdbId' => 801, 'mediaType' => 'movie', 'status' => 5, 'status4k' => 3];
$show = ['id' => 402, 'tmdbId' => 802, 'mediaType' => 'tv', 'status' => 4, 'status4k' => 5];
$request = static function (int $id, array $media, int $status, bool $is4k = false, array $seasons = []) use ($isSeerr): array {
    $by = $isSeerr ? ['displayName' => 'Fixture Viewer'] : ['jellyfinUsername' => 'Fixture Viewer'];
    return [
        'id' => $id,
        'type' => $media['mediaType'],
        'is4k' => $is4k,
        'status' => $status,
        'media' => $media,
        'seasons' => array_map(static fn (int $n): array => ['seasonNumber' => $n, 'status' => $status], $seasons),
        'requestedBy' => ['id' => 20, 'email' => 'viewer@example.test', ...$by],
        'createdAt' => '2026-11-22T10:00:00.000Z',
        'profileId' => 10,
        'rootFolder' => '/fixture/private/path',
    ] + ($isSeerr ? ['ignoreQuota' => false] : []);
};
$requests = [
    16 => $request(16, $movie, 2),
    15 => $request(15, $movie, 2, true),
    14 => $request(14, $show, 2, false, [3, 0]),
    13 => $request(13, $show, 1, false, [2]),
    12 => $request(12, $show, 4, true, [4]),
    11 => $request(11, $movie, 3),
];

while ($connection = @stream_socket_accept($server, 10)) {
    stream_set_timeout($connection, 3);
    [$method, $target] = array_pad(explode(' ', trim((string) fgets($connection)), 3), 2, '');
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower($name)] = trim($value);
    }

    $code = 200;
    $path = parse_url($target, PHP_URL_PATH);
    if (($headers['x-api-key'] ?? null) !== 'seerr-read-fixture-key' || $path === '/api/v1/request/99') {
        $code = 403;
        $response = ['message' => 'Denied: synthetic private diagnostic'];
    } elseif ($method !== 'GET') {
        $code = 405;
        $response = ['message' => 'GET required'];
    } elseif ($path === '/api/v1/request') {
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $take = (int) ($query['take'] ?? 10);
        $skip = (int) ($query['skip'] ?? 0);
        $status = ['pending' => 1, 'failed' => 4][$query['filter'] ?? 'all'] ?? null;
        $filtered = array_values(array_filter($requests, static fn (array $r): bool => $status === null || $r['status'] === $status));
        $response = [
            'pageInfo' => ['pages' => (int) ceil(count($filtered) / $take), 'pageSize' => $take,
                'results' => count($filtered), 'page' => (int) ceil($skip / $take) + 1],
            'results' => array_slice($filtered, $skip, $take),
        ] + ($isSeerr ? ['serviceErrors' => ['radarr' => [], 'sonarr' => []]] : []);
    } elseif (preg_match('~^/api/v1/request/([0-9]+)$~D', (string) $path, $match) && isset($requests[(int) $match[1]])) {
        $response = $requests[(int) $match[1]];
    } elseif ($path === '/api/v1/movie/801') {
        $response = ['title' => 'Fixture Movie', 'releaseDate' => '2024-03-01', 'posterPath' => '/movie.jpg'];
    } else {
        $code = 404;
        $response = ['message' => 'Not found'];
    }

    $body = json_encode($response, JSON_THROW_ON_ERROR);
    fwrite($connection, "HTTP/1.1 $code Fixture\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($connection);
}
