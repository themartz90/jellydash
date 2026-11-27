<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\JellyfinLinks;

/**
 * Verifies a Jellyfin navigation target using library metadata.
 *
 * @phpstan-type Binding array{item: string, server: string, type: 'Movie'|'Series',
 *     tmdb: int, tvdb: ?int, is4k: bool, seasons: list<int>}
 */
final class JellyfinRequestLink
{
    private const EPISODE_LIMIT = 128;
    private const SEASON_LIMIT = 4;

    public function __construct(
        private readonly JellyfinClient $client,
        private readonly string $linkBase,
    ) {
    }

    /** @param array<string, mixed> $request @return Binding|null */
    public static function binding(array $request): ?array
    {
        $card = RequestMapper::display($request);
        $is4k = $card['is4k'];
        $media = $request['media'] ?? null;
        $type = match ($card['mediaType']) { 'movie' => 'Movie', 'tv' => 'Series', default => null };
        if (!is_array($media) || $type === null || ($media['mediaType'] ?? null) !== $card['mediaType']
            || $is4k === null || $card['tmdbId'] === null
            || !in_array($card['requestStatus'], [RequestMapper::REQUEST_APPROVED, RequestMapper::REQUEST_COMPLETED], true)
            || !in_array($card['mediaStatus'], $type === 'Movie' ? [RequestMapper::MEDIA_AVAILABLE]
                : [RequestMapper::MEDIA_AVAILABLE, RequestMapper::MEDIA_PARTIALLY_AVAILABLE], true)
            || ($type === 'Series' && (!$card['seasonNumbers'] || count($card['seasonNumbers']) > self::SEASON_LIMIT))) {
            return null;
        }

        $item = self::jellyfinId($media[$is4k ? 'jellyfinMediaId4k' : 'jellyfinMediaId'] ?? null);
        $other = self::jellyfinId($media[$is4k ? 'jellyfinMediaId' : 'jellyfinMediaId4k'] ?? null);
        $url = $media[$is4k ? 'mediaUrl4k' : 'mediaUrl'] ?? null;
        $fragment = is_string($url) ? parse_url($url, PHP_URL_FRAGMENT) : null;
        if ($item === null || $item === $other || !is_string($fragment)
            || preg_match('~^!?/details\?(.+)$~D', $fragment, $match) !== 1) {
            return null;
        }
        // Only the server identity comes from Seerr's URL.
        parse_str($match[1], $query);
        $server = self::jellyfinId($query['serverId'] ?? null);
        if ($server === null || self::jellyfinId($query['id'] ?? null) !== $item) {
            return null;
        }
        $tvdb = $media['tvdbId'] ?? null;

        return ['item' => $item, 'server' => $server, 'type' => $type, 'tmdb' => $card['tmdbId'],
            'tvdb' => is_int($tvdb) && $tvdb > 0 ? $tvdb : null, 'is4k' => $is4k,
            'seasons' => $card['seasonNumbers'] ?? []];
    }

    /** @param Binding $binding */
    public function verifiedLink(array $binding): ?string
    {
        $base = JellyfinLinks::validatedBaseUrl($this->linkBase);
        if ($base === null) {
            return null;
        }
        $items = $this->items(['Ids' => $binding['item'], 'IncludeItemTypes' => $binding['type']], 2);
        $item = $items[0] ?? null;
        if (count($items) !== 1 || $item === null || !self::isItem($item, $binding['type'], $binding['server'])
            || self::jellyfinId($item['Id'] ?? null) !== $binding['item'] || !self::providerMatches($item, $binding)) {
            return null;
        }
        $matches = $binding['type'] === 'Movie' ? self::hasEditionSource($item, $binding['is4k'])
            : $this->hasRequestedSeasons($binding);

        return $matches ? $base . '/web/#/details?id=' . $binding['item'] . '&serverId=' . $binding['server'] : null;
    }

    /** @param Binding $binding */
    private function hasRequestedSeasons(array $binding): bool
    {
        $filter = ['ParentId' => $binding['item'], 'IncludeItemTypes' => 'Episode', 'Recursive' => 'true', 'IsMissing' => 'false'];
        if (count($binding['seasons']) === 1) {
            $filter['ParentIndexNumber'] = $binding['seasons'][0];
        }
        $matches = [];
        foreach ($this->items($filter, self::EPISODE_LIMIT) as $episode) {
            $season = $episode['ParentIndexNumber'] ?? null;
            if (!in_array($season, $binding['seasons'], true)) {
                continue;
            }
            if (!self::isItem($episode, 'Episode', $binding['server'])
                || self::jellyfinId($episode['SeriesId'] ?? null) !== $binding['item']) {
                return false;
            }
            $number = $episode['IndexNumber'] ?? null;
            if (is_int($number) && $number > 0 && self::hasEditionSource($episode, $binding['is4k'])) {
                $matches[$season] = true;
            }
        }

        return count($matches) === count($binding['seasons']);
    }

    /** @param array<string, scalar> $filter @return list<array<string, mixed>> */
    private function items(array $filter, int $limit): array
    {
        $query = $filter + ['Limit' => $limit, 'StartIndex' => 0, 'EnableTotalRecordCount' => 'true',
            'EnableUserData' => 'false', 'EnableImages' => 'false', 'Fields' => 'ProviderIds,MediaSources'];
        $result = $this->client->getJson('/Items?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), 2, 2_097_152);
        $items = is_array($result) ? ($result['Items'] ?? null) : null;
        if (!is_array($items) || !array_is_list($items) || count($items) > $limit
            || ($result['TotalRecordCount'] ?? null) !== count($items) || ($result['StartIndex'] ?? null) !== 0
            || count(array_filter($items, 'is_array')) !== count($items)) {
            return [];
        }

        return $items;
    }

    /** @param array<string, mixed> $item */
    private static function isItem(array $item, string $type, string $server): bool
    {
        return ($item['Type'] ?? null) === $type && self::jellyfinId($item['ServerId'] ?? null) === $server
            && self::jellyfinId($item['Id'] ?? null) !== null && ($item['LocationType'] ?? null) === 'FileSystem';
    }

    /** @param array<string, mixed> $item @param Binding $binding */
    private static function providerMatches(array $item, array $binding): bool
    {
        $providers = $item['ProviderIds'] ?? null;
        if (!is_array($providers)) {
            return false;
        }
        $ids = [];
        foreach ($providers as $key => $value) {
            $provider = match (strtolower((string) $key)) { 'themoviedb', 'tmdb' => 'tmdb', 'tvdb' => 'tvdb', default => null };
            if ($provider !== null && $binding[$provider] !== null) {
                if ((!is_int($value) && !is_string($value)) || (string) $value !== (string) $binding[$provider]) {
                    return false;
                }
                $ids[$provider] = true;
            }
        }

        return isset($ids['tmdb']) || ($binding['type'] === 'Series' && isset($ids['tvdb']));
    }

    /** @param array<string, mixed> $item */
    private static function hasEditionSource(array $item, bool $is4k): bool
    {
        $sources = $item['MediaSources'] ?? null;
        if (!is_array($sources) || !array_is_list($sources) || count($sources) !== 1 || !is_array($sources[0])
            || ($sources[0]['Protocol'] ?? null) !== 'File' || !is_array($sources[0]['MediaStreams'] ?? null)
            || self::jellyfinId($sources[0]['Id'] ?? null) === null) {
            return false;
        }
        $video = array_values(array_filter($sources[0]['MediaStreams'],
            static fn (mixed $stream): bool => is_array($stream) && ($stream['Type'] ?? null) === 'Video'));
        $width = $video[0]['Width'] ?? null;

        return count($video) === 1 && is_int($width) && $width > 0 && ($width > 2000) === $is4k;
    }

    private static function jellyfinId(mixed $value): ?string
    {
        if (!is_string($value)
            || preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/iD', $value) !== 1) {
            return null;
        }
        $id = strtolower(str_replace('-', '', $value));

        return $id === str_repeat('0', 32) ? null : $id;
    }
}
