<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Imports historical plays from the Jellyfin Playback Reporting plugin into
 * Jellydash. Sources: a TSV backup, the plugin SQLite file, or the plugin API.
 */
final class PlaybackReportingImporter
{
    private PlaybackReportingParser $parser;
    private PlayHistoryRepository $repository;
    private JellyfinClient $client;
    private PlaybackReportingClient $plugin;

    public function __construct(
        ?PlaybackReportingParser $parser = null,
        ?PlayHistoryRepository $repository = null,
        ?JellyfinClient $client = null,
        ?PlaybackReportingClient $plugin = null,
    ) {
        $this->parser = $parser ?? new PlaybackReportingParser();
        $this->repository = $repository ?? new PlayHistoryRepository();
        $this->client = $client ?? new JellyfinClient();
        $this->plugin = $plugin ?? new PlaybackReportingClient($this->client);
    }

    /**
     * @param 'tsv'|'sqlite'|null $kind
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int, unresolved: int}
     */
    public function importFile(string $path, bool $dryRun = false, ?string $kind = null, ?callable $onProgress = null): array
    {
        $path = $this->readablePath($path);

        $result = $this->importRows($this->parseFile($path, $kind), $dryRun, $onProgress);
        unset($result['repaired']);

        return $result;
    }

    /**
     * @return array{parsed: int, kind: 'tsv'|'sqlite'}
     */
    public function previewFile(string $path): array
    {
        $path = $this->readablePath($path);
        $kind = $this->detectKind($path);
        $rows = $this->mergeSameDayPlays($this->parseFile($path, $kind));

        return [
            'parsed' => count($rows),
            'kind' => $kind,
        ];
    }

    /**
     * @return array{parsed: int, kind: 'plugin'}
     */
    public function previewPlugin(): array
    {
        return [
            'parsed' => $this->plugin->count(),
            'kind' => 'plugin',
        ];
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int, unresolved: int}
     */
    public function importFromPlugin(bool $dryRun = false, ?callable $onProgress = null): array
    {
        $total = $this->plugin->count();
        $userNames = $this->userNames();
        if ($onProgress !== null) {
            $onProgress([
                'phase' => 'preparing',
                'processed' => 0,
                'total' => $total,
                'inserted' => 0,
                'skipped' => 0,
            ]);
        }

        $merged = [];
        $offset = 0;

        while (true) {
            $chunk = $this->plugin->activityChunk($this->parser, $offset, PlaybackReportingClient::CHUNK_SIZE);
            if ($chunk['fetched'] === 0) {
                break;
            }

            $merged = $this->mergeSameDayPlays(array_merge($merged, $chunk['rows']));
            $offset += PlaybackReportingClient::CHUNK_SIZE;
        }

        $result = $this->importRows($merged, $dryRun, $onProgress, true, $userNames);
        unset($result['repaired']);

        return $result;
    }

    /**
     * @return 'tsv'|'sqlite'
     */
    public function detectKind(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['db', 'sqlite', 'sqlite3'], true)) {
            return 'sqlite';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'tsv';
        }

        $magic = fread($handle, 16);
        fclose($handle);

        return is_string($magic) && str_starts_with($magic, 'SQLite format 3') ? 'sqlite' : 'tsv';
    }

    /**
     * Fill runtime_sec from a map of item id => seconds. PlayDuration is
     * session length (or the summed length of same-day sessions) and is kept
     * as recorded. is_finished uses the same 95% rule as live history.
     * Missing items keep runtime_sec = 0.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int> $runtimes
     * @return list<array<string, mixed>>
     */
    public function applyRuntimes(array $rows, array $runtimes): array
    {
        $lookup = [];
        foreach ($runtimes as $id => $seconds) {
            $key = $this->normalizedItemId((string) $id);
            if ($key !== '') {
                $lookup[$key] = max(0, (int) $seconds);
            }
        }

        foreach ($rows as &$row) {
            $runtime = $lookup[$this->normalizedItemId((string) ($row['item_id'] ?? ''))] ?? 0;
            $watched = max(0, (int) ($row['watched_sec'] ?? 0));

            $finished = PlayHistoryRepository::isPlayFinished($watched, $runtime);
            $endedAt = $this->endedAt((string) ($row['started_at'] ?? ''), $watched);
            $row['runtime_sec'] = $runtime;
            $row['watched_sec'] = $watched;
            $row['is_finished'] = $finished ? 1 : 0;
            $row['updated_at'] = $endedAt ?? ($row['updated_at'] ?? null);
            $row['ended_at'] = $finished ? $endedAt : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Collapse sessions for the same user, item, and calendar day into one
     * play. Watch time is summed; metadata comes from the earliest start.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function mergeSameDayPlays(array $rows): array
    {
        $groups = [];
        $order = [];

        foreach ($rows as $row) {
            $key = $this->sameDayKey($row);
            if ($key === '') {
                $order[] = $row;
                continue;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $order[] = $key;
                continue;
            }

            $groups[$key] = $this->combineSameDayPlays($groups[$key], $row);
        }

        $merged = [];
        foreach ($order as $item) {
            $merged[] = is_string($item) ? $groups[$item] : $item;
        }

        return $merged;
    }

    /**
     * Replace the type-based library label with the real Jellyfin library
     * when the item path was resolved. Unknown items keep Movie → Movies etc.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $libraries
     * @return list<array<string, mixed>>
     */
    public function applyLibraries(array $rows, array $libraries): array
    {
        $lookup = [];
        foreach ($libraries as $id => $name) {
            $key = $this->normalizedItemId((string) $id);
            $library = trim((string) $name);
            if ($key !== '' && $library !== '') {
                $lookup[$key] = $library;
            }
        }

        foreach ($rows as &$row) {
            $library = $lookup[$this->normalizedItemId((string) ($row['item_id'] ?? ''))] ?? '';
            if ($library !== '') {
                $row['library'] = $library;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param 'tsv'|'sqlite'|null $kind
     * @return list<array<string, mixed>>
     */
    private function parseFile(string $path, ?string $kind = null): array
    {
        $path = $this->readablePath($path);
        $kind ??= $this->detectKind($path);

        if ($kind === 'sqlite') {
            return $this->parser->parseSqliteFile($path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the Playback Reporting file.');
        }

        return $this->parser->parseTsv($contents);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @param array<string, string>|null $userNames
     * @return array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int}
     */
    private function importRows(
        array $rows,
        bool $dryRun = false,
        ?callable $onProgress = null,
        bool $refreshOverview = true,
        ?array $userNames = null,
    ): array {
        $named = $this->mergeSameDayPlays(
            $this->parser->applyUserNames($rows, $userNames ?? $this->userNames())
        );
        if ($onProgress !== null && $refreshOverview) {
            $onProgress([
                'phase' => 'preparing',
                'processed' => 0,
                'total' => count($named),
                'inserted' => 0,
                'skipped' => 0,
            ]);
        }

        $meta = $this->fetchItemMeta($named);
        $enriched = $this->applyRuntimes($named, $this->runtimesFromMeta($meta));
        $enriched = $this->applyLibraries($enriched, $this->librariesFromMeta($meta));
        $unresolved = $this->unresolvedCount($enriched);
        $result = $this->repository->importHistoricalPlays($enriched, $dryRun, $onProgress);

        if ($refreshOverview && !$dryRun && ($result['inserted'] > 0 || $result['repaired'] > 0)) {
            $this->refreshLibraryOverview();
        }

        return [
            'parsed' => count($enriched),
            'inserted' => $result['inserted'],
            'skipped' => $result['skipped'],
            'unresolved' => $unresolved,
            'repaired' => $result['repaired'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sameDayKey(array $row): string
    {
        $day = substr((string) ($row['started_at'] ?? ''), 0, 10);
        $item = $this->normalizedItemId((string) ($row['item_id'] ?? ''));
        if ($item === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return '';
        }

        return $this->normalizedItemId((string) ($row['user_id'] ?? '')) . "\0" . $item . "\0" . $day;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function combineSameDayPlays(array $left, array $right): array
    {
        $leftStart = (string) ($left['started_at'] ?? '');
        $rightStart = (string) ($right['started_at'] ?? '');
        $earliest = $rightStart !== '' && ($leftStart === '' || $rightStart < $leftStart)
            ? $right
            : $left;
        $earliest['watched_sec'] = max(0, (int) ($left['watched_sec'] ?? 0))
            + max(0, (int) ($right['watched_sec'] ?? 0));

        return $earliest;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function unresolvedCount(array $rows): int
    {
        $unresolved = 0;
        foreach ($rows as $row) {
            if ($this->normalizedItemId((string) ($row['item_id'] ?? '')) !== ''
                && max(0, (int) ($row['runtime_sec'] ?? 0)) <= 0
            ) {
                $unresolved++;
            }
        }

        return $unresolved;
    }

    private function normalizedItemId(string $id): string
    {
        return strtolower(str_replace('-', '', trim($id)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{runtime_sec: int, library: string}>
     */
    private function fetchItemMeta(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $this->normalizedItemId((string) ($row['item_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return $this->client->itemImportMeta(array_values($ids));
    }

    /**
     * @param array<string, array{runtime_sec: int, library: string}> $meta
     * @return array<string, int>
     */
    private function runtimesFromMeta(array $meta): array
    {
        $runtimes = [];
        foreach ($meta as $id => $info) {
            $runtime = max(0, (int) $info['runtime_sec']);
            if ($runtime > 0) {
                $runtimes[$id] = $runtime;
            }
        }

        return $runtimes;
    }

    /**
     * @param array<string, array{runtime_sec: int, library: string}> $meta
     * @return array<string, string>
     */
    private function librariesFromMeta(array $meta): array
    {
        $libraries = [];
        foreach ($meta as $id => $info) {
            $library = trim($info['library']);
            if ($library !== '') {
                $libraries[$id] = $library;
            }
        }

        return $libraries;
    }

    private function refreshLibraryOverview(): void
    {
        try {
            (new LibraryOverviewService())->refreshCache();
        } catch (\Throwable) {
            // The background warmer will pick the new plays up on its next run.
        }
    }

    private function endedAt(string $startedAt, int $watchedSec): ?string
    {
        if ($startedAt === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($startedAt))
                ->modify('+' . max(0, $watchedSec) . ' seconds')
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function userNames(): array
    {
        try {
            $users = $this->client->users();
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($users as $user) {
            $key = $this->parser->strippedId($user['id']);
            $name = trim($user['name']);
            if ($key !== '' && $name !== '') {
                $map[$key] = $name;
            }
        }

        return $map;
    }

    private function readablePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Playback Reporting file is not readable.');
        }

        return $path;
    }
}
