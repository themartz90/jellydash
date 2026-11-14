<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;

/** Manager-only artwork repair; matching changes images, never recorded plays. */
final class ArtworkManager
{
    private Database $database;
    private JellyfinClient $client;
    private ArtworkRepository $repository;

    public function __construct(?Database $database = null, ?JellyfinClient $client = null, ?ArtworkRepository $repository = null)
    {
        $this->database = $database ?? Container::db();
        $this->client = $client ?? new JellyfinClient();
        $this->repository = $repository ?? new ArtworkRepository($this->database, ThemePlaybackExclusions::serverKey($this->client->baseUrl()));
    }

    /** @return array<string, mixed> */
    public function status(int $page = 1): array
    {
        $db = $this->database->getDibi();
        $scope = $this->repository->scope;
        $title = "CASE WHEN item_type = 'Episode' AND series_name <> '' THEN series_name ELSE title END";
        $grouping = $this->database->getPlatform()->isSqlite()
            ? 'item_type, group_title COLLATE BINARY, library COLLATE BINARY' : 'item_type, BINARY group_title, BINARY library';
        $groupSql = 'SELECT item_type, ' . $title . ' AS group_title, library, COUNT(*) AS total FROM artwork_items '
            . 'WHERE source_key = %s AND status = %s GROUP BY ' . $grouping;
        $groupCount = (int) $db->query('SELECT COUNT(*) FROM (' . $groupSql . ') grouped', $scope, 'review')->fetchSingle();
        $posterCount = (int) $db->select('COUNT(*)')->from('artwork_posters')->where('source_key = %s', $scope)->fetchSingle();
        $pages = max(1, (int) ceil(max($groupCount, $posterCount) / 10));
        $page = max(1, min($pages, $page));
        $groups = [];
        foreach ($db->query(
            $groupSql . ' ORDER BY group_title, item_type, library LIMIT %i OFFSET %i',
            $scope,
            'review',
            10,
            ($page - 1) * 10
        )->fetchAll() as $group) {
            $exactTitle = $this->database->getPlatform()->isSqlite() ? '(' . $title . ') COLLATE BINARY = %s' : 'BINARY (' . $title . ') = %s';
            $exactLibrary = $this->database->getPlatform()->isSqlite() ? 'library COLLATE BINARY = %s' : 'BINARY library = %s';
            $items = array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $db->select('*')->from('artwork_items')
                ->where('source_key = %s AND status = %s AND item_type = %s', $scope, 'review', $group['item_type'])
                ->where($exactTitle, $group['group_title'])->where($exactLibrary, $group['library'])->orderBy('item_id')->limit(100)->fetchAll());
            $groups[] = ['title' => (string) $group['group_title'], 'library' => (string) $group['library'],
                'kind' => (string) $group['item_type'], 'count' => (int) $group['total'],
                'ids' => array_map(static fn (array $item): string => (string) $item['item_id'], $items),
                'fingerprint' => ArtworkRepository::fingerprint($items)];
        }
        $posters = [];
        foreach ($db->query('SELECT p.*, EXISTS(SELECT 1 FROM artwork_images i WHERE i.source_key = p.source_key AND i.poster_id = p.poster_id) AS has_image '
            . 'FROM artwork_posters p WHERE p.source_key = %s ORDER BY p.poster_id LIMIT %i OFFSET %i', $scope, 10, ($page - 1) * 10)->fetchAll() as $poster) {
            $item = $db->select('title, series_name')->from('artwork_items')->where('source_key = %s AND poster_id = %s', $scope, $poster['poster_id'])->limit(1)->fetch();
            $manual = array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $db->select('*')->from('artwork_items')
                ->where('source_key = %s AND poster_id = %s AND evidence = %s', $scope, $poster['poster_id'], 'manual')->orderBy('item_id')->limit(100)->fetchAll());
            $posters[] = ['id' => (string) $poster['poster_id'], 'title' => $item !== null
                ? (string) ($poster['kind'] === 'Series' && $item['series_name'] !== '' ? $item['series_name'] : $item['title']) : 'Saved poster',
                'kind' => (string) $poster['kind'], 'hasImage' => (bool) $poster['has_image'], 'suppressed' => (bool) $poster['suppressed'],
                'revision' => (int) $poster['revision'], 'error' => $poster['last_error'],
                'manualIds' => array_map(static fn (array $row): string => (string) $row['item_id'], $manual),
                'manualFingerprint' => ArtworkRepository::fingerprint($manual)];
        }
        $source = $this->repository->source();
        $capacity = (int) $db->select('COUNT(*)')->from('artwork_posters')->where('source_key = %s AND last_error = %s AND suppressed = 0', $scope, 'capacity')->fetchSingle();
        return ['source' => ['blocked' => (bool) $source['blocked'], 'error' => $source['last_error'] ?? ($capacity > 0 ? 'capacity' : null),
            'legacyConfirmed' => (bool) $source['legacy_confirmed'],
            'configured' => $this->client->baseUrl() !== '' && (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', '')) !== '',
            'otherSources' => (int) $db->select('COUNT(DISTINCT source_key)')->from('artwork_images')->where('source_key <> %s', $scope)->fetchSingle()],
            'summary' => ['saved' => (int) $db->select('COUNT(*)')->from('artwork_images')->where('source_key = %s', $scope)->fetchSingle(),
                'pending' => (int) $db->query('SELECT COUNT(*) FROM artwork_posters p LEFT JOIN artwork_images i ON p.source_key = i.source_key AND p.poster_id = i.poster_id '
                    . 'WHERE p.source_key = %s AND p.suppressed = 0 AND i.poster_id IS NULL', $scope)->fetchSingle()
                    + (int) $db->select('COUNT(*)')->from('artwork_items')->where('source_key = %s AND status = %s', $scope, 'pending')->fetchSingle(),
                'review' => (int) $db->select('COUNT(*)')->from('artwork_items')->where('source_key = %s AND status = %s', $scope, 'review')->fetchSingle(),
                'bytes' => (int) $source['used_bytes'], 'limitBytes' => ArtworkRepository::budget()],
            'groups' => $groups, 'posters' => $posters, 'page' => $page, 'pages' => $pages];
    }

    /** @return list<array{id: string, title: string, year: ?string, kind: string, poster: string}> */
    public function search(string $query, string $kind): array
    {
        if (!in_array($kind, ['Movie', 'Series'], true) || trim($query) === '' || mb_strlen($query) > 160) {
            throw new \InvalidArgumentException('Enter a title of up to 160 characters.');
        }
        $this->verifyCurrent();
        $payload = $this->client->getJson('/Items?' . http_build_query(['SearchTerm' => $query, 'IncludeItemTypes' => $kind,
            'Recursive' => 'true', 'CollapseBoxSetItems' => 'false', 'Limit' => 20], '', '&', PHP_QUERY_RFC3986), 4);
        if (!is_array($payload) || !is_array($payload['Items'] ?? null)) {
            throw new \RuntimeException('Jellyfin titles are unavailable.');
        }
        $candidates = [];
        foreach (array_slice($payload['Items'], 0, 20) as $item) {
            if (!is_array($item) || !is_string($item['Id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $item['Id'])
                || ($item['Type'] ?? null) !== $kind || !is_string($item['Name'] ?? null)) {
                continue;
            }
            $candidates[] = ['id' => $item['Id'], 'title' => $item['Name'],
                'year' => is_int($item['ProductionYear'] ?? null) ? (string) $item['ProductionYear'] : null,
                'kind' => $kind, 'poster' => '/api/image.php?item=' . rawurlencode($item['Id']) . '&type=Primary&maxWidth=120'];
        }
        return $candidates;
    }

    /** @param list<string> $ids */
    public function link(array $ids, string $fingerprint, string $target): void
    {
        $rows = $this->repository->items($ids);
        if (count($rows) !== count($ids) || $rows === [] || !hash_equals(ArtworkRepository::fingerprint($rows), $fingerprint)) {
            throw new \RuntimeException('The selection changed. Refresh and try again.');
        }
        $first = $rows[0];
        foreach ($rows as $row) {
            if ($row['status'] !== 'review' || $row['item_type'] !== $first['item_type'] || $row['library'] !== $first['library']
                || ($row['item_type'] === 'Episode' ? $row['series_name'] !== $first['series_name'] : $row['title'] !== $first['title'])) {
                throw new \RuntimeException('Choose one History group at a time.');
            }
        }
        $this->verifyCurrent();
        $collector = new ArtworkCollector($this->database, $this->client, $this->repository);
        $current = $collector->lookup($target);
        $kind = $first['item_type'] === 'Episode' ? 'Series' : 'Movie';
        if (($current['Type'] ?? null) !== $kind) {
            throw new \RuntimeException('Choose a current item of the matching type.');
        }
        $payload = $this->client->getJson('/Items?' . http_build_query(['Ids' => implode(',', $ids), 'Limit' => 101, 'EnableTotalRecordCount' => 'true',
            'CollapseBoxSetItems' => 'false'], '', '&', PHP_QUERY_RFC3986), 4);
        if (!is_array($payload) || !is_array($payload['Items'] ?? null) || !array_is_list($payload['Items'])) {
            throw new \RuntimeException('History item metadata is unavailable.');
        }
        if (!is_int($payload['TotalRecordCount'] ?? null) || $payload['TotalRecordCount'] !== count($payload['Items'])
            || count($payload['Items']) > count($ids)) {
            throw new \RuntimeException('The History item check was incomplete.');
        }
        $seen = [];
        foreach ($payload['Items'] as $live) {
            if (!is_array($live) || !is_string($live['Id'] ?? null)
                || !in_array(self::canonical($live['Id']), array_map(self::canonical(...), $ids), true)
                || ($live['Type'] ?? null) !== $first['item_type']
                || ($kind === 'Series' ? !is_string($live['SeriesId'] ?? null) || self::canonical($live['SeriesId']) !== self::canonical($target)
                    : self::canonical($live['Id']) !== self::canonical($target))) {
                throw new \RuntimeException('A current History item has a different identity.');
            }
            $key = self::canonical($live['Id']);
            if (isset($seen[$key])) {
                throw new \RuntimeException('The History item check was incomplete.');
            }
            $seen[$key] = true;
        }
        $this->verifyCurrent();
        $this->repository->matchItems($ids, (string) $current['Id'], $fingerprint);
    }

    public function confirmHistory(): void
    {
        (new ArtworkCollector($this->database, $this->client, $this->repository))->verifySource();
        $this->repository->confirmLegacy();
    }

    private function verifyCurrent(): void
    {
        $source = $this->repository->source();
        $info = $this->client->getJson('/System/Info/Public', 4);
        if ((int) $source['blocked'] !== 0 || $source['server_id'] === '' || !is_array($info)
            || ($info['Id'] ?? null) !== $source['server_id']) {
            throw new \RuntimeException('The Jellyfin server identity could not be verified.');
        }
    }

    private static function canonical(string $id): string
    {
        $guid = strtolower(str_replace('-', '', $id));
        return preg_match('/^[a-f0-9]{32}$/D', $guid) ? $guid : $id;
    }
}
