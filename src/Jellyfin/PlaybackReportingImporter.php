<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Imports historical plays from the Jellyfin or Emby Playback Reporting plugin
 * into Jellydash. Sources: a TSV backup, the plugin SQLite file, or the plugin
 * API.
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
     * @return array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int}
     */
    public function importFile(string $path, bool $dryRun = false, ?string $kind = null, ?callable $onProgress = null): array
    {
        $path = $this->readablePath($path);
        $kind ??= $this->detectKind($path);
        $total = iterator_count($this->iterateFile($path, $kind));
        $result = $this->importIterable($this->iterateFile($path, $kind), $total, $dryRun, $onProgress);
        unset($result['repaired']);

        return $result;
    }

    /**
     * @return array{parsed: int, kind: 'tsv'|'sqlite', importable: int, skipped: int, conflicts: int}
     */
    public function previewFile(string $path): array
    {
        $path = $this->readablePath($path);
        $kind = $this->detectKind($path);

        $preview = $this->importFile($path, true, $kind);

        return [
            'parsed' => $preview['parsed'],
            'kind' => $kind,
            'importable' => $preview['inserted'],
            'skipped' => $preview['skipped'],
            'conflicts' => $preview['conflicts'],
        ];
    }

    /**
     * @return array{parsed: int, kind: 'plugin', importable: int, skipped: int, conflicts: int}
     */
    public function previewPlugin(): array
    {
        $preview = $this->importFromPlugin(true);

        return [
            'parsed' => $preview['parsed'],
            'kind' => 'plugin',
            'importable' => $preview['inserted'],
            'skipped' => $preview['skipped'],
            'conflicts' => $preview['conflicts'],
        ];
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int}
     */
    public function importFromPlugin(bool $dryRun = false, ?callable $onProgress = null): array
    {
        $boundary = $this->plugin->activityBoundary();
        $total = $boundary['count'];
        $userNames = $this->userNames();
        $this->emitPreparing($onProgress, $total);

        $stats = $this->emptyStats();
        $dryRunSeen = [];
        $cursor = 0;

        while (true) {
            $chunk = $this->plugin->activityPage($this->parser, $cursor, $boundary['lastRowId']);
            if ($chunk['fetched'] === 0) {
                break;
            }

            $this->addStats(
                $stats,
                $this->importChunk(
                    $chunk['rows'],
                    $dryRun,
                    $onProgress,
                    $userNames,
                    $stats['parsed'],
                    $total,
                    $stats['inserted'],
                    $stats['skipped'],
                    $stats['conflicts'],
                    $dryRunSeen,
                ),
            );
            if ($chunk['cursor'] <= $cursor) {
                throw new \RuntimeException('Playback Reporting import did not advance.');
            }
            $cursor = $chunk['cursor'];
        }

        $this->finishImport($stats, $dryRun, $onProgress, $total);
        unset($stats['repaired']);

        return $stats;
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
     * Fill runtime_sec from a map of item id => seconds. watched_sec is the
     * plugin's active playback time. is_finished uses the same 95% rule as
     * live history.
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
            $recordedEnd = trim((string) ($row['updated_at'] ?? ''));
            $endedAt = $recordedEnd !== ''
                ? $recordedEnd
                : $this->endedAt((string) ($row['started_at'] ?? ''), $watched);
            $row['runtime_sec'] = $runtime;
            $row['watched_sec'] = $watched;
            $row['watch_duration_sec'] = $watched;
            $row['is_finished'] = $finished ? 1 : 0;
            $row['updated_at'] = $endedAt ?? ($row['updated_at'] ?? null);
            $row['ended_at'] = $finished ? $endedAt : null;
        }
        unset($row);

        return $rows;
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

        $resolvedAt = new \DateTimeImmutable('now');
        foreach ($rows as &$row) {
            $library = $lookup[$this->normalizedItemId((string) ($row['item_id'] ?? ''))] ?? '';
            if ($library !== '') {
                $row['library'] = $library;
                $row['library_resolved_at'] = $resolvedAt->format('Y-m-d H:i:s');
                $row['library_resolved_at_epoch'] = $resolvedAt->getTimestamp();
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param 'tsv'|'sqlite'|null $kind
     * @return iterable<int, array<string, mixed>>
     */
    private function iterateFile(string $path, ?string $kind = null): iterable
    {
        $path = $this->readablePath($path);
        $kind ??= $this->detectKind($path);

        if ($kind === 'sqlite') {
            return $this->parser->iterateSqliteFile($path);
        }

        return $this->parser->iterateTsvFile($path);
    }

    /**
     * @param iterable<int, array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int, repaired: int}
     */
    private function importIterable(iterable $rows, int $total, bool $dryRun, ?callable $onProgress): array
    {
        $userNames = $this->userNames();
        $this->emitPreparing($onProgress, $total);

        $stats = $this->emptyStats();
        $batch = [];
        $dryRunSeen = [];

        foreach ($rows as $row) {
            $batch[] = $row;
            if (count($batch) >= PlaybackReportingClient::CHUNK_SIZE) {
                $this->addStats(
                    $stats,
                    $this->importChunk(
                        $batch,
                        $dryRun,
                        $onProgress,
                        $userNames,
                        $stats['parsed'],
                        $total,
                        $stats['inserted'],
                        $stats['skipped'],
                        $stats['conflicts'],
                        $dryRunSeen,
                    ),
                );
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->addStats(
                $stats,
                $this->importChunk(
                    $batch,
                    $dryRun,
                    $onProgress,
                    $userNames,
                    $stats['parsed'],
                    $total,
                    $stats['inserted'],
                    $stats['skipped'],
                    $stats['conflicts'],
                    $dryRunSeen,
                ),
            );
        }

        $this->finishImport($stats, $dryRun, $onProgress, $total);

        return $stats;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @param array<string, string> $userNames
     * @param array<string, true> $dryRunSeen
     * @return array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int, repaired: int}
     */
    private function importChunk(
        array $rows,
        bool $dryRun,
        ?callable $onProgress,
        array $userNames,
        int $processed,
        int $total,
        int $inserted,
        int $skipped,
        int $conflicts,
        array &$dryRunSeen,
    ): array {
        if ($rows === []) {
            return $this->emptyStats();
        }

        $named = $this->parser->applyUserNames($rows, $userNames);
        if ($this->repository->hasIgnoredUsers()) {
            foreach ($named as $row) {
                if (trim((string) ($row['user_name'] ?? '')) === '') {
                    throw new ImportValidationException('Playback Reporting import stopped: user names could not be resolved while ignored users are configured. Check the Jellyfin /Users connection and retry.');
                }
            }
        }
        $meta = $this->fetchItemMeta($named);
        $enriched = $this->applyRuntimes($named, $this->runtimesFromMeta($meta));
        $enriched = $this->applyLibraries($enriched, $this->librariesFromMeta($meta));
        $progress = $this->wrapBatchProgress($onProgress, $processed, $total, $inserted, $skipped, $conflicts);
        if ($dryRun) {
            $result = $this->repository->importHistoricalPlays($enriched, true, $progress, $dryRunSeen);
        } else {
            $result = $this->repository->importHistoricalPlays($enriched, false, $progress);
        }

        return [
            'parsed' => count($named),
            'inserted' => $result['inserted'],
            'skipped' => $result['skipped'],
            'conflicts' => $result['conflicts'],
            'unresolved' => $this->unresolvedCount($enriched),
            'repaired' => $result['repaired'],
        ];
    }

    /**
     * @return array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int, repaired: int}
     */
    private function emptyStats(): array
    {
        return [
            'parsed' => 0,
            'inserted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'unresolved' => 0,
            'repaired' => 0,
        ];
    }

    /**
     * @param array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int, repaired: int} $stats
     * @param array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int, repaired: int} $chunk
     */
    private function addStats(array &$stats, array $chunk): void
    {
        $stats['parsed'] += $chunk['parsed'];
        $stats['inserted'] += $chunk['inserted'];
        $stats['skipped'] += $chunk['skipped'];
        $stats['conflicts'] += $chunk['conflicts'];
        $stats['unresolved'] += $chunk['unresolved'];
        $stats['repaired'] += $chunk['repaired'];
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     */
    private function emitPreparing(?callable $onProgress, int $total): void
    {
        if ($onProgress === null) {
            return;
        }

        $onProgress([
            'phase' => 'preparing',
            'processed' => 0,
            'total' => $total,
            'inserted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
        ]);
    }

    /**
     * @param array{parsed: int, inserted: int, skipped: int, conflicts: int, unresolved: int, repaired: int} $stats
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     */
    private function finishImport(array $stats, bool $dryRun, ?callable $onProgress, int $total): void
    {
        if ($stats['parsed'] === 0 && $onProgress !== null) {
            $onProgress([
                'phase' => 'importing',
                'processed' => 0,
                'total' => $total,
                'inserted' => 0,
                'skipped' => 0,
                'conflicts' => 0,
            ]);
        }

        if (!$dryRun && ($stats['inserted'] > 0 || $stats['repaired'] > 0)) {
            $this->refreshLibraryOverview();
        }
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null
     */
    private function wrapBatchProgress(
        ?callable $onProgress,
        int $offset,
        int $total,
        int $inserted,
        int $skipped,
        int $conflicts,
    ): ?callable {
        if ($onProgress === null) {
            return null;
        }

        return static function (array $payload) use ($onProgress, $offset, $total, $inserted, $skipped, $conflicts): void {
            if (($payload['phase'] ?? '') !== 'importing') {
                return;
            }

            $onProgress([
                'phase' => 'importing',
                'processed' => $offset + (int) $payload['processed'],
                'total' => $total,
                'inserted' => $inserted + (int) $payload['inserted'],
                'skipped' => $skipped + (int) $payload['skipped'],
                'conflicts' => $conflicts + (int) ($payload['conflicts'] ?? 0),
            ]);
        };
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
        return ThemePlaybackExclusions::canonicalItemId($id);
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

        try {
            return $this->client->itemImportMeta(array_values($ids));
        } catch (\Throwable) {
            return [];
        }
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
            $key = $this->parser->idKey($user['id']);
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
            throw new ImportValidationException('Playback Reporting file is not readable.');
        }

        return $path;
    }
}
