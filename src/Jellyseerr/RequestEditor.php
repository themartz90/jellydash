<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Notifications\NotificationEndpoint;

/** Builds and validates pending-request edits from the exact upstream record and its available choices. */
final class RequestEditor
{
    private const MAX_SEASONS = 200;
    private const EAGER_DESTINATIONS = 4;

    public function __construct(private JellyseerrClient $client)
    {
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    public static function stored(array $request): ?array
    {
        $card = RequestMapper::display($request);
        $type = $card['mediaType'];
        $owner = $request['requestedBy']['id'] ?? null;
        $seasons = $card['seasonNumbers'];
        if ($card['requestStatus'] !== RequestMapper::REQUEST_PENDING || RequestManager::version($request, $card) === null
            || $type !== ($request['type'] ?? null) || !is_int($owner) || $owner < 1
            || $seasons === null || count($seasons) > self::MAX_SEASONS || count(array_filter($seasons, self::optionId(...))) !== count($seasons)
            || ($type === 'tv' && count($seasons) !== count($request['seasons'] ?? []))) {
            return null;
        }

        $fields = $type === 'tv' ? ['serverId', 'profileId', 'rootFolder', 'tags', 'languageProfileId']
            : ['serverId', 'profileId', 'rootFolder', 'tags'];
        $payload = ['mediaType' => $type];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $request)) {
                return null;
            }
            $value = $request[$field];
            $valid = $value === null || match ($field) {
                'tags' => is_array($value) && array_is_list($value) && count($value) <= 200
                    && count(array_filter($value, self::optionId(...))) === count($value),
                'rootFolder' => is_string($value) && strlen($value) <= 2048 && !str_contains($value, "\0"),
                'serverId' => self::optionId($value) || $value === -1,
                default => self::optionId($value),
            };
            if (!$valid) {
                return null;
            }
            $payload[$field] = $value;
        }
        if ($type === 'tv') {
            $payload['seasons'] = $seasons;
        }

        return [
            'identity' => [$card['requestId'], $card['mediaId'], $card['tmdbId'], $type, $card['is4k'], $owner, $card['requestedAt']],
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    public function options(array $request, ?string $destination = null): ?array
    {
        $stored = self::stored($request);
        if ($stored === null) {
            return null;
        }
        $payload = $stored['payload'];
        [, $mediaId, $tmdbId, $type, $is4k] = $stored['identity'];
        $seasons = $type === 'tv' ? $this->seasonChoices($request['id'], $mediaId, $tmdbId, $is4k, $payload['seasons']) : [];
        if ($seasons === null) {
            return null;
        }
        [$routes, $verified] = $this->routes($payload, $is4k, $destination);
        $currentRoute = self::routeKey($payload['serverId']);
        $canRoute = isset($routes[$currentRoute]) && (count($routes) > 1 || count($routes[$currentRoute]['profiles']) > 1);
        $canSeasons = $type === 'tv' && (count($seasons) > 1 || $seasons !== $payload['seasons']);
        $baseVersion = hash('sha256', serialize([$stored, $seasons, $routes]));
        $version = $baseVersion;
        if ($verified !== null && $destination !== null) {
            $version = hash('sha256', serialize([$baseVersion, $destination, $verified]));
            $routes[$destination] = $verified;
        }

        return [
            'version' => $version, 'baseVersion' => $baseVersion,
            'stored' => $stored, 'seasons' => $seasons, 'canSeasons' => $canSeasons,
            'routes' => $canRoute ? $routes : [], 'currentRoute' => $currentRoute,
            'currentProfile' => self::profileKey($payload['profileId']),
        ];
    }

    /** @param list<int> $current @return list<int>|null */
    private function seasonChoices(int $requestId, int $mediaId, int $tmdbId, bool $is4k, array $current): ?array
    {
        try {
            $show = $this->client->tv($tmdbId);
        } catch (\RuntimeException) {
            return null;
        }
        $media = $show['mediaInfo'] ?? null;
        $catalog = $show['seasons'] ?? null;
        if (($show['id'] ?? null) !== $tmdbId || !is_array($media) || ($media['id'] ?? null) !== $mediaId
            || ($media['tmdbId'] ?? null) !== $tmdbId || ($media['mediaType'] ?? null) !== 'tv'
            || !self::rows($media['requests'] ?? null) || !self::rows($media['seasons'] ?? null)
            || !self::rows($catalog) || count($catalog) > self::MAX_SEASONS) {
            return null;
        }
        $blocked = [];
        $found = false;
        foreach ($media['requests'] as $sibling) {
            if (!is_int($sibling['id'] ?? null) || !is_bool($sibling['is4k'] ?? null) || !in_array($sibling['status'] ?? null, [1, 2, 3, 4, 5], true)) {
                return null;
            }
            if ($sibling['id'] === $requestId) {
                $found = $sibling['is4k'] === $is4k && $sibling['status'] === RequestMapper::REQUEST_PENDING
                    && self::seasonRows($sibling['seasons'] ?? null) === $current;
            } elseif ($sibling['is4k'] === $is4k && !in_array($sibling['status'], [3, 5], true)) {
                $numbers = self::seasonRows($sibling['seasons'] ?? null);
                if ($numbers === null) {
                    return null;
                }
                $blocked = [...$blocked, ...$numbers];
            }
        }
        if (!$found || array_intersect($current, $blocked) !== []) {
            return null;
        }
        $edition = $is4k ? 'status4k' : 'status';
        foreach ($media['seasons'] as $season) {
            if (!self::optionId($season['seasonNumber'] ?? null) || !in_array($season[$edition] ?? null, [1, 2, 3, 4, 5, 6, 7], true)) {
                return null;
            }
            if (!in_array($season[$edition], [1, 7], true)) {
                $blocked[] = $season['seasonNumber'];
            }
        }
        $choices = $current;
        foreach ($catalog as $season) {
            $number = $season['seasonNumber'] ?? null;
            if (!self::optionId($number) || !self::optionId($season['episodeCount'] ?? null)) {
                return null;
            }
            if ($number > 0 && $season['episodeCount'] > 0 && !in_array($number, $blocked, true)) {
                $choices[] = $number;
            }
        }
        $choices = array_values(array_unique($choices));
        sort($choices, SORT_NUMERIC);

        return count($choices) <= self::MAX_SEASONS ? $choices : null;
    }

    /** @param array<string, mixed> $payload @return array{array<string, mixed>, ?array<string, mixed>} */
    private function routes(array $payload, bool $is4k, ?string $destination): array
    {
        $type = $payload['mediaType'];
        try {
            $list = $this->client->requestServices($type);
        } catch (\RuntimeException) {
            return [[], null];
        }
        if (!self::rows($list)) {
            return [[], null];
        }
        $servers = [];
        foreach ($list as $server) {
            $id = $server['id'] ?? null;
            if (!self::optionId($id) || !is_bool($server['is4k'] ?? null) || !is_bool($server['isDefault'] ?? null)) {
                return [[], null];
            }
            if ($server['is4k'] === $is4k) {
                if (isset($servers[$id])) {
                    return [[], null];
                }
                $servers[$id] = $server;
            }
        }
        $defaults = array_filter($servers, static fn (array $server): bool => $server['isDefault']);
        $defaultId = count($defaults) === 1 ? array_key_first($defaults) : null;
        $storedId = $payload['serverId'];
        $currentId = $storedId === null || $storedId === -1 ? $defaultId : $storedId;
        if ($currentId === null || !isset($servers[$currentId])) {
            return [[], null];
        }
        // IDs, folders and languages are instance-specific. Empty tags can
        // safely remain empty when switching destinations.
        $portable = $payload['profileId'] === null && $payload['rootFolder'] === null
            && ($payload['languageProfileId'] ?? null) === null && in_array($payload['tags'], [null, []], true);
        $candidates = $portable ? $servers : [$currentId => $servers[$currentId]];
        $onDemand = count($candidates) > self::EAGER_DESTINATIONS;
        ksort($candidates, SORT_NUMERIC);
        $routes = [];
        $verified = null;
        foreach ($candidates as $id => $server) {
            $key = self::routeKey($id);
            if ($onDemand && $id !== $currentId) {
                if (!is_string($server['name'] ?? null) || trim($server['name']) === '') {
                    continue;
                }
                $routes[$key] = ['label' => mb_strimwidth($server['name'], 0, 100), 'profiles' => []];
                if ($destination === $key) {
                    $selected = $this->route($type, $id, $is4k, $server, null);
                    if ($selected !== null && $selected['validDefaults']) {
                        $verified = $selected;
                    }
                }
                continue;
            }
            $route = $this->route($type, $id, $is4k, $server, $id === $currentId ? $payload['profileId'] : null);
            if ($route === null || ($id !== $currentId && !$route['validDefaults'])) {
                continue;
            }
            if ($id === $currentId) {
                $routes[self::routeKey($storedId)] = [
                    ...$route,
                    'label' => $storedId === null || $storedId === -1 ? 'Seerr default (' . $route['label'] . ')' : $route['label'],
                ];
            } else {
                $routes[$key] = $route;
            }
        }

        return isset($routes[self::routeKey($storedId)]) ? [$routes, $verified] : [[], null];
    }

    /** @param array<string, mixed> $listed @return array<string, mixed>|null */
    private function route(string $type, int $id, bool $is4k, array $listed, ?int $profile): ?array
    {
        try {
            $details = $this->client->requestServices($type, $id);
        } catch (\RuntimeException) {
            return null;
        }
        $server = $details['server'] ?? null;
        if (!is_array($server) || ($server['id'] ?? null) !== $id || ($server['is4k'] ?? null) !== $is4k
            || ($server['isDefault'] ?? null) !== $listed['isDefault'] || !self::rows($details['profiles'] ?? null)
            || count($details['profiles']) > 100 || !self::rows($details['rootFolders'] ?? null)
            || !is_string($server['name'] ?? null) || trim($server['name']) === '') {
            return null;
        }
        $profiles = [];
        foreach ($details['profiles'] as $choice) {
            $profileId = $choice['id'] ?? null;
            if (!self::optionId($profileId) || $profileId === 0 || !is_string($choice['name'] ?? null) || trim($choice['name']) === ''
                || isset($profiles[self::profileKey($profileId)])) {
                return null;
            }
            $profiles[self::profileKey($profileId)] = mb_strimwidth($choice['name'], 0, 100);
        }
        $activeProfile = $server['activeProfileId'] ?? null;
        $activeFolder = $server['activeDirectory'] ?? null;
        $validProfile = self::optionId($activeProfile) && isset($profiles[self::profileKey($activeProfile)]);
        $validDefaults = $validProfile && is_string($activeFolder) && $activeFolder !== ''
            && in_array($activeFolder, array_column($details['rootFolders'], 'path'), true);
        if ($profile === null) {
            $profiles = ['default' => 'Seerr settings', ...$profiles];
        }
        if ($profile !== null && !isset($profiles[self::profileKey($profile)])) {
            $profiles[self::profileKey($profile)] = 'Keep current override';
        }
        // Changes in destination configuration invalidate the offered options.
        $settings = array_intersect_key($server, array_flip([
            'activeDirectory', 'activeProfileId', 'activeTags', 'activeAnimeDirectory',
            'activeAnimeProfileId', 'activeLanguageProfileId', 'activeAnimeLanguageProfileId', 'activeAnimeTags',
        ]));
        ksort($settings);

        return ['serverId' => $id, 'label' => mb_strimwidth($server['name'], 0, 100), 'profiles' => $profiles,
            'validDefaults' => $validDefaults, 'settings' => $settings];
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $edit @return array<string, mixed>|null */
    public static function payload(array $options, array $edit): ?array
    {
        $payload = $options['stored']['payload'];
        if (isset($edit['seasons'])) {
            if (!$options['canSeasons'] || array_diff($edit['seasons'], $options['seasons']) !== []) {
                return null;
            }
            $payload['seasons'] = $edit['seasons'];
        }
        if (isset($edit['destination'], $edit['profile'])) {
            if (!isset($options['routes'][$edit['destination']]['profiles'][$edit['profile']])) {
                return null;
            }
            if ($edit['destination'] !== $options['currentRoute']) {
                $payload['serverId'] = (int) substr($edit['destination'], 3);
            }
            $payload['profileId'] = $edit['profile'] === 'default' ? null : (int) substr($edit['profile'], 3);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public static function submitted(mixed $input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['optionsVersion', 'seasons', 'destination', 'profile']) !== []
            || !is_string($input['optionsVersion'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $input['optionsVersion']) !== 1) {
            throw new \InvalidArgumentException('Invalid Seerr edit.');
        }
        if (array_key_exists('seasons', $input)) {
            $seasons = $input['seasons'];
            if (!is_array($seasons) || !array_is_list($seasons) || $seasons === [] || count($seasons) > self::MAX_SEASONS
                || count(array_filter($seasons, self::optionId(...))) !== count($seasons)
                || count(array_unique($seasons)) !== count($seasons)) {
                throw new \InvalidArgumentException('Invalid season selection.');
            }
            sort($seasons, SORT_NUMERIC);
            $input['seasons'] = $seasons;
        }
        if (array_key_exists('destination', $input) || array_key_exists('profile', $input)) {
            if (!is_string($input['destination'] ?? null) || !is_string($input['profile'] ?? null)
                || preg_match('/^(default|id:[0-9]{1,10})$/D', $input['destination']) !== 1
                || preg_match('/^(default|id:[0-9]{1,10})$/D', $input['profile']) !== 1) {
                throw new \InvalidArgumentException('Invalid destination or profile.');
            }
        }
        if (count($input) < 2) {
            throw new \InvalidArgumentException('Missing Seerr edit.');
        }
        ksort($input, SORT_STRING);

        return $input;
    }

    /** @param array<string, mixed> $request */
    public function browserLink(array $request): ?string
    {
        $card = RequestMapper::display($request);
        if ($card['mediaType'] === null || $card['tmdbId'] === null) {
            return null;
        }
        try {
            $published = $this->client->publicSettings()['applicationUrl'] ?? null;
        } catch (\RuntimeException) {
            return null;
        }
        $url = is_string($published) ? NotificationEndpoint::baseUrl($published) : null;
        $host = $url !== null ? parse_url($url, PHP_URL_HOST) : null;
        if (!is_string($host) || !str_contains($host, '.') || str_starts_with($host, '127.') || str_starts_with($host, '0.')
            || str_ends_with(strtolower(rtrim($host, '.')), '.localhost')) {
            return null;
        }

        return $url . $card['mediaType'] . '/' . $card['tmdbId'];
    }

    /** @phpstan-assert-if-true int<0, 2147483647> $id */
    private static function optionId(mixed $id): bool
    {
        return is_int($id) && $id >= 0 && $id <= 2_147_483_647;
    }

    /** @phpstan-assert-if-true list<array<string, mixed>> $rows */
    private static function rows(mixed $rows): bool
    {
        return is_array($rows) && array_is_list($rows) && count(array_filter($rows, is_array(...))) === count($rows);
    }

    /** @return list<int>|null */
    private static function seasonRows(mixed $rows): ?array
    {
        if (!self::rows($rows)) {
            return null;
        }
        $numbers = [];
        foreach ($rows as $row) {
            if (!self::optionId($row['seasonNumber'] ?? null) || isset($numbers[$row['seasonNumber']])) {
                return null;
            }
            $numbers[$row['seasonNumber']] = $row['seasonNumber'];
        }
        sort($numbers, SORT_NUMERIC);

        return $numbers;
    }

    private static function routeKey(?int $id): string
    {
        return $id === -1 ? 'default' : self::profileKey($id);
    }

    private static function profileKey(?int $id): string
    {
        return $id === null ? 'default' : 'id:' . $id;
    }
}
