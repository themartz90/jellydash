<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Http\RemoteRequestException;

/** Local review lists and explicit manager searches for missing historical posters. */
final class ExternalPosterManager
{
    private Database $database;
    private ArtworkRepository $art;
    private JellyfinClient $client;
    private ExternalPosterProvider $provider;
    private ?ExternalPosterCache $cache;

    public function __construct(?Database $database = null, ?ArtworkRepository $art = null, ?JellyfinClient $client = null, ?ExternalPosterCache $cache = null)
    {
        $this->database = $database ?? Container::db();
        $this->client = $client ?? new JellyfinClient();
        $this->art = $art ?? new ArtworkRepository($this->database, ThemePlaybackExclusions::serverKey($this->client->baseUrl()));
        $this->provider = new ExternalPosterProvider($this->client);
        $this->cache = $cache;
    }

    private function cache(): ExternalPosterCache
    {
        return $this->cache ??= new ExternalPosterCache($this->art->scope);
    }

    private function groupSql(): string
    {
        $skipped = 'CASE WHEN e.snoozed_until > ' . time() . ' THEN 1 ELSE 0 END';
        $fields = ['e.kind', 'i.poster_id', 'e.verified_id', "CASE WHEN i.item_type = 'Episode' THEN i.series_name ELSE i.title END", 'i.library', $skipped];
        $binary = $this->database->getPlatform()->isSqlite() ? static fn (string $field): string => '(' . $field . ') COLLATE BINARY'
            : static fn (string $field): string => 'BINARY (' . $field . ')';
        $grouping = implode(', ', array_map($binary, $fields));
        return 'SELECT e.kind, i.poster_id, e.verified_id, ' . $fields[3] . ' AS group_title, i.library, ' . $skipped . ' AS skipped, COUNT(*) AS total, MIN(e.snoozed_until) AS snoozed_until '
            . 'FROM artwork_external_bindings e JOIN artwork_items i ON i.source_key = e.source_key AND i.item_id = e.item_id '
            . 'WHERE e.source_key = %s AND e.presence = 0 AND e.suppressed = 0 AND ' . $this->needsReviewSql() . ' GROUP BY ' . $grouping;
    }

    private function needsReviewSql(): string
    {
        return "((e.state = 'review' OR (e.expires_at <= " . time() . " AND e.tmdb_id IS NOT NULL)) AND (e.origin = 'manual' OR e.verified_id IS NULL OR e.identity_conflict <> 0 OR COALESCE(e.last_error, '') IN ('no_exact_match', 'selection_changed', 'identity_conflict')))";
    }

    /** @param array<string, mixed> $group @return array<string, mixed> */
    private function group(array $group, bool $withDiscovery = false): array
    {
        $sql = 'SELECT i.item_id, i.title, i.series_name, i.evidence, e.snoozed_until, e.origin, e.verified_id, e.identity_conflict FROM artwork_external_bindings e JOIN artwork_items i ON i.source_key = e.source_key AND i.item_id = e.item_id WHERE e.source_key = %s AND e.presence = 0 AND e.suppressed = 0 AND ' . $this->needsReviewSql() . ' AND e.kind = %s';
        $args = [$this->art->scope, $group['kind']];
        $sql .= (int) $group['skipped'] === 1 ? ' AND e.snoozed_until > %i' : ' AND e.snoozed_until <= %i';
        $args[] = time();
        foreach (['i.poster_id' => $group['poster_id'], 'e.verified_id' => $group['verified_id'],
            "CASE WHEN i.item_type = 'Episode' THEN i.series_name ELSE i.title END" => $group['group_title'], 'i.library' => $group['library']] as $field => $value) {
            if ($value === null) {
                $sql .= ' AND ' . $field . ' IS NULL';
            } else {
                $sql .= ' AND ' . ($this->database->getPlatform()->isSqlite() ? '(' . $field . ') COLLATE BINARY' : 'BINARY (' . $field . ')') . ' = %s';
                $args[] = $value;
            }
        }
        $rows = $this->database->getDibi()->query($sql . ' ORDER BY e.snoozed_until, i.item_id LIMIT 100', ...$args)->fetchAll();
        $ids = array_map(static fn (\Dibi\Row $row): string => (string) $row['item_id'], $rows);
        $key = hash('sha256', json_encode([$group['kind'], $group['poster_id'], $group['verified_id'], $group['group_title'], $group['library'], (int) $group['skipped']], JSON_THROW_ON_ERROR));
        $result = ['key' => $key, 'fingerprint' => hash('sha256', $key . ':' . (int) $group['total'] . ':' . $this->art->externalFingerprint($ids)),
            'title' => (string) $group['group_title'], 'kind' => (string) $group['kind'], 'library' => (string) $group['library'],
            'count' => count($ids), 'ids' => $ids, 'items' => array_map(static fn (\Dibi\Row $row): array => ['id' => (string) $row['item_id'], 'title' => (string) $row['title']], $rows),
            'snoozedUntil' => min(array_map(static fn (\Dibi\Row $row): int => (int) $row['snoozed_until'], $rows)),
            'reason' => (int) $group['total'] > 100 ? 'Showing up to 100 items. Remaining items appear as these are reviewed or skipped.' : 'Choose the correct title before saving a poster.'];
        if ($withDiscovery) {
            $result['discovery'] = $this->discoverySummary($rows);
        }
        return $result;
    }

    /** @param list<\Dibi\Row> $rows @return array<string, mixed> */
    private function discoverySummary(array $rows): array
    {
        $input = $this->art->discoveryInput((string) $rows[0]['item_id']);
        $record = $input === null ? null : $this->art->discoveryRecord($input['key'], time());
        $eligible = false;
        $source = $this->art->source();
        foreach ($rows as $row) {
            if ($input !== null && $row['origin'] === 'automatic' && $row['verified_id'] === null && (int) $row['identity_conflict'] === 0
                && (int) $row['snoozed_until'] <= time() && ((int) $source['legacy_confirmed'] !== 0 || in_array($row['evidence'], ['observed', 'manual'], true))) {
                $eligible = true;
                break;
            }
        }
        $missing = !$this->art->externalEnabled() ? 'paused' : ($eligible ? 'waiting' : 'manual');
        return ['count' => $record === null ? 0 : count($record['candidates']),
            'status' => $record === null ? $missing : ($record['last_error'] === 'pending' ? $missing
                : ($record['last_error'] !== null ? 'unavailable' : ($record['candidates'] === [] ? 'empty' : 'ready'))),
            'checkedAt' => $record === null ? null : (int) $record['acquired_at']];
    }

    /** @param list<string> $ids @return array{years: list<int>, covered: int, total: int} */
    private function recordedYears(array $ids): array
    {
        $years = [];
        $covered = 0;
        foreach ($ids as $id) {
            $item = $this->art->item($id);
            if ($item === null) {
                continue;
            }
            $target = is_string($item['poster_id']) ? $item['poster_id'] : ($item['item_type'] === 'Movie' ? $id : null);
            $poster = $target === null ? null : $this->art->poster($target);
            if ($poster !== null && (int) $poster['provider_conflict'] === 0 && $poster['provider_year'] !== null) {
                $years[] = (int) $poster['provider_year'];
                ++$covered;
            }
        }
        $years = array_values(array_unique($years));
        sort($years, SORT_NUMERIC);
        return ['years' => $years, 'covered' => $covered, 'total' => count($ids)];
    }

    public function noticeCount(): int
    {
        $source = $this->art->source();
        if (!$this->art->externalEnabled() || $this->art->externalBlocked()) {
            return 0;
        }
        return (int) $this->database->getDibi()->query('SELECT COUNT(*) FROM (' . $this->groupSql() . ') groups_to_review WHERE snoozed_until <= %i', $this->art->scope, time())->fetchSingle();
    }

    /** @return array<string, mixed> */
    public function status(int $page = 1): array
    {
        $db = $this->database->getDibi();
        $scope = $this->art->scope;
        $source = $this->art->source();
        if ($this->art->externalBlocked()) {
            return ['enabled' => $this->art->externalEnabled(), 'configRevision' => (int) $source['external_revision'],
                'source' => ['blocked' => true, 'confirmed' => false, 'error' => 'server_changed'], 'noticeCount' => 0,
                'review' => [], 'jobs' => [], 'assets' => [], 'page' => 1, 'pages' => 1];
        }
        $count = (int) $db->query('SELECT COUNT(*) FROM (' . $this->groupSql() . ') groups_to_review', $scope)->fetchSingle();
        $jobsFilter = '(NOT ' . $this->needsReviewSql() . ' OR e.presence <> 0 OR e.suppressed <> 0) AND (e.tmdb_id IS NULL OR e.expires_at > ' . time() . ')';
        $jobsCount = (int) $db->select('COUNT(*)')->from('artwork_external_bindings')->as('e')->where('source_key = %s', $scope)->where($jobsFilter)->fetchSingle();
        $assetCount = (int) $db->select('COUNT(*)')->from('artwork_external_assets')->where('source_key = %s AND expires_at > %i', $scope, time())->fetchSingle();
        $pages = max(1, (int) ceil(max($count, $jobsCount, $assetCount) / 10));
        $page = max(1, min($pages, $page));
        $review = [];
        foreach ($db->query($this->groupSql() . ' ORDER BY skipped, group_title, kind, library, poster_id, verified_id LIMIT %i OFFSET %i', $scope, 10, ($page - 1) * 10)->fetchAll() as $group) {
            $review[] = $this->group($group->toArray(), true);
        }
        $jobs = [];
        foreach ($db->query('SELECT e.*, i.title, i.series_name FROM artwork_external_bindings e JOIN artwork_items i ON e.source_key = i.source_key AND e.item_id = i.item_id WHERE e.source_key = %s AND ' . $jobsFilter . ' ORDER BY i.title, e.item_id LIMIT %i OFFSET %i', $scope, 10, ($page - 1) * 10)->fetchAll() as $row) {
            $binding = $this->art->externalBinding((string) $row['item_id']);
            $state = (string) ($binding['state'] ?? 'review');
            if ($state === 'review' && $binding !== null && $binding['origin'] === 'automatic'
                && $binding['verified_id'] !== null && (int) $binding['identity_conflict'] === 0) {
                $state = 'waiting';
            }
            $jobs[] = ['ids' => [(string) $row['item_id']], 'fingerprint' => $this->art->externalFingerprint([(string) $row['item_id']]),
                'title' => (string) ($row['kind'] === 'Series' ? $row['series_name'] : $row['title']), 'selectedTitle' => (string) ($binding['selected_title'] ?? ''),
                'state' => $state, 'error' => $row['last_error']];
        }
        $assets = [];
        foreach ($db->query('SELECT a.*, (SELECT COUNT(*) FROM artwork_external_bindings e WHERE e.source_key = a.source_key AND (e.asset_key = a.asset_key OR (e.kind = a.kind AND e.tmdb_id = a.provider_id AND e.poster_path = a.poster_path))) AS refs FROM artwork_external_assets a WHERE a.source_key = %s AND a.expires_at > %i ORDER BY a.title, a.asset_key LIMIT %i OFFSET %i', $scope, time(), 10, ($page - 1) * 10)->fetchAll() as $asset) {
            $assets[] = ['key' => $asset['asset_key'], 'title' => $asset['title'], 'kind' => $asset['kind'], 'providerId' => $asset['provider_id'],
                'bodySize' => (int) $asset['body_size'], 'refCount' => (int) $asset['refs'], 'fingerprint' => $this->art->externalAssetFingerprint((string) $asset['asset_key']),
                'imageUrl' => '/api/external-poster-preview.php?asset=' . $asset['asset_key']];
        }
        $unconfirmed = (int) $source['legacy_confirmed'] === 0 && (int) $db->select('COUNT(*)')->from('artwork_items')->where('source_key = %s AND evidence IN %in', $scope, ['legacy', 'provisional'])->fetchSingle() > 0;
        return ['enabled' => $this->art->externalEnabled(), 'configRevision' => (int) $source['external_revision'],
            'source' => ['blocked' => (bool) $source['blocked'], 'confirmed' => !$unconfirmed && $source['server_id'] !== '',
                'error' => $source['external_last_error'] ?? $source['last_error']], 'noticeCount' => $this->noticeCount(),
            'review' => $review, 'jobs' => $jobs, 'assets' => $assets, 'page' => $page, 'pages' => $pages];
    }

    /** @param list<string> $ids @return array<string, mixed> */
    private function currentGroup(array $ids, string $fingerprint): array
    {
        $item = $this->art->item($ids[0]);
        $binding = $this->art->externalBinding($ids[0]);
        if ($item === null || $binding === null) {
            throw new \RuntimeException('The poster selection changed.');
        }
        $sql = 'SELECT * FROM (' . $this->groupSql() . ') grouped WHERE kind = %s AND skipped = %i';
        $args = [$this->art->scope, $binding['kind'], (int) $binding['snoozed_until'] > time() ? 1 : 0];
        foreach (['poster_id' => $item['poster_id'], 'verified_id' => $binding['verified_id'],
            'group_title' => $item['item_type'] === 'Episode' ? $item['series_name'] : $item['title'], 'library' => $item['library']] as $field => $value) {
            if ($value === null) {
                $sql .= ' AND ' . $field . ' IS NULL';
            } else {
                $sql .= ' AND ' . ($this->database->getPlatform()->isSqlite() ? $field . ' COLLATE BINARY' : 'BINARY ' . $field) . ' = %s';
                $args[] = $value;
            }
        }
        $row = $this->database->getDibi()->query($sql . ' LIMIT 1', ...$args)->fetch();
        if ($row !== null) {
            $group = $this->group($row->toArray());
            if (hash_equals($group['fingerprint'], $fingerprint) && array_diff($ids, $group['ids']) === []) {
                return $group;
            }
        }
        throw new \RuntimeException('The poster selection changed.');
    }

    private function allowOnline(bool $outbound = false): void
    {
        $source = $this->art->source();
        if (!$this->art->externalEnabled() || $this->art->externalBlocked() || $source['server_id'] === ''
            || ($outbound && (int) $source['external_next_attempt'] > time())) {
            throw new \RuntimeException('Online poster recovery is paused.');
        }
    }

    private function verifyCurrent(): void
    {
        $this->allowOnline(true);
        $info = $this->client->getJson('/System/Info/Public', 4, 65536);
        if (!is_array($info) || ($info['Id'] ?? null) !== $this->art->source()['server_id']) {
            if (is_array($info) && is_string($info['Id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $info['Id'])) {
                $this->art->anchor($info['Id']);
            }
            throw new \RuntimeException('Jellyfin identity could not be verified.');
        }
        $this->allowOnline(true);
    }

    /** @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function network(\Closure $operation, bool $exclusive = true): mixed
    {
        $this->allowOnline(true);
        $now = time();
        $source = $this->art->source();
        $lease = $exclusive ? $this->art->claim($now, 20) : null;
        if ($exclusive && $lease === null) {
            throw new \RuntimeException('Poster recovery is busy. Try again shortly.');
        }
        try {
            $this->verifyCurrent();
            return $operation();
        } catch (RemoteRequestException $error) {
            $this->art->externalNetworkFailure(
                (string) $source['server_id'],
                (int) $source['external_revision'],
                $error->getCode() === 429 ? 'throttled' : 'request_failed',
                $now + max(60, $error->retryAfterSeconds)
            );
            throw $error;
        } finally {
            if ($lease !== null) {
                $this->art->release($lease);
            }
        }
    }

    /** @param list<string> $ids @return array<string, mixed> */
    public function search(array $ids, string $fingerprint, string $query, string $kind, ?int $year = null): array
    {
        $group = $this->currentGroup($ids, $fingerprint);
        if ($group['kind'] !== $kind) {
            throw new \InvalidArgumentException('Choose the matching media type.');
        }
        $selectedFingerprint = $this->art->externalFingerprint($ids);
        $revision = (int) $this->art->source()['external_revision'];
        $results = $this->network(fn (): array => $this->provider->search($kind, $query, $year));
        $this->allowOnline();
        $this->currentGroup($ids, $fingerprint);
        if (!hash_equals($selectedFingerprint, $this->art->externalFingerprint($ids)) || (int) $this->art->source()['external_revision'] !== $revision) {
            throw new \RuntimeException('The poster selection changed.');
        }
        return $this->candidateTokens($ids, $fingerprint, $selectedFingerprint, $revision, $results);
    }

    /** Reads already discovered metadata, never performs a provider search.
     * @param list<string> $ids @return array<string, mixed>
     */
    public function suggestions(array $ids, string $fingerprint): array
    {
        $this->allowOnline();
        $group = $this->currentGroup($ids, $fingerprint);
        $input = $this->art->discoveryInput($ids[0]);
        if ($input === null) {
            throw new \RuntimeException('Enter a title to search.');
        }
        foreach ($ids as $id) {
            if ($this->art->discoveryInput($id) !== $input) {
                throw new \RuntimeException('Choose one title group.');
            }
        }
        $record = $this->art->discoveryRecord($input['key'], time());
        if ($record === null || $record['candidates'] === [] || $record['kind'] !== $group['kind']) {
            throw new \RuntimeException('Search suggestions expired or are unavailable. Search online again.');
        }
        return $this->candidateTokens($ids, $fingerprint, $this->art->externalFingerprint($ids), (int) $this->art->source()['external_revision'], $record['candidates'], (int) $record['expires_at'])
            + ['origin' => 'discovery', 'queryTitle' => $input['title']];
    }

    /** @param list<string> $ids @param list<array<string, mixed>> $results @return array<string, mixed> */
    private function candidateTokens(array $ids, string $fingerprint, string $selectedFingerprint, int $revision, array $results, int $deadline = PHP_INT_MAX): array
    {
        $this->currentGroup($ids, $fingerprint);
        $years = $this->recordedYears($ids);
        $input = $this->art->discoveryInput($ids[0]);
        $candidates = [];
        foreach ($results as $candidate) {
            $expires = min(time() + 600, $deadline, (int) ($candidate['expires_at'] ?? 0));
            if ($expires <= time()) {
                continue;
            }
            $token = $this->cache()->put(['candidate' => $candidate, 'ids' => $ids, 'fingerprint' => $fingerprint,
                'selectedFingerprint' => $selectedFingerprint, 'revision' => $revision, 'serverId' => $this->art->source()['server_id'], 'expires' => $expires]);
            $candidates[] = ['id' => $candidate['id'], 'kind' => $candidate['kind'], 'title' => $candidate['title'], 'year' => $candidate['year'],
                'token' => $token, 'previewUrl' => '/api/external-poster-preview.php?token=' . $token,
                'recordedYears' => $years['years'], 'yearCoverage' => $years['covered'], 'selectedCount' => $years['total'],
                'sameRecordedTitle' => $input !== null && ExternalPosterDiscovery::sameTitle($candidate['title'], $input['title'])];
        }
        return ['candidates' => $candidates, 'fingerprint' => $fingerprint];
    }

    /** @return array<string, mixed> */
    private function token(string $token): array
    {
        $this->allowOnline();
        $entry = $this->cache()->get($token);
        if ((int) ($entry['candidate']['expires_at'] ?? 0) <= time() || $entry['serverId'] !== $this->art->source()['server_id'] || (int) $entry['revision'] !== (int) $this->art->source()['external_revision']
            || !hash_equals($this->art->externalFingerprint($entry['ids']), $entry['selectedFingerprint'])) {
            throw new \RuntimeException('The poster search expired.');
        }
        $this->currentGroup($entry['ids'], $entry['fingerprint']);
        return $entry;
    }

    /** @param list<string> $ids */
    public function select(array $ids, string $fingerprint, string $token): void
    {
        $entry = $this->token($token);
        $left = $entry['ids'];
        $right = $ids;
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);
        if ($left !== $right || !hash_equals($entry['fingerprint'], $fingerprint)) {
            throw new \RuntimeException('Search again after changing the selected History items.');
        }
        $this->art->externalSelect(
            $ids,
            $entry['selectedFingerprint'],
            $entry['candidate'],
            false,
            function () use ($ids, $fingerprint, $entry): void {
                $this->currentGroup($ids, $fingerprint);
                if ((int) $entry['expires'] <= time() || (int) ($entry['candidate']['expires_at'] ?? 0) <= time()) {
                    throw new \RuntimeException('The poster search expired.');
                }
            }
        );
    }

    /** @param list<string> $ids */
    public function act(array $ids, string $fingerprint, string $action, bool $suppressed = true): void
    {
        $guard = null;
        try {
            $group = $this->currentGroup($ids, $fingerprint);
            if (count($ids) !== count($group['ids'])) {
                throw new \RuntimeException('Use the complete displayed group.');
            }
            $groupFingerprint = $fingerprint;
            $guard = fn () => $this->currentGroup($ids, $groupFingerprint);
            $fingerprint = $this->art->externalFingerprint($ids);
        } catch (\RuntimeException $error) {
            if (!hash_equals($this->art->externalFingerprint($ids), $fingerprint)) {
                throw $error;
            }
        }
        if ($action === 'suppress') {
            $this->art->externalSuppress($ids, $fingerprint, $suppressed, $guard);
        } else {
            $this->art->manageExternal($ids, $fingerprint, $action, $guard);
        }
    }

    /** @return array{body: string, contentType: string} */
    public function preview(string $token): array
    {
        $entry = $this->token($token);
        $image = $this->cache()->preview($token, fn (): array => $this->network(fn (): array => $this->provider->fetch($entry['candidate']['path']), false));
        $this->token($token);
        return $image;
    }
}
