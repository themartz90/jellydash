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
        $kind ??= $this->detectKind($path);
        $total = iterator_count($this->iterateFile($path, $kind));
        $result = $this->importIterable($this->iterateFile($path, $kind), $total, $dryRun, $onProgress);
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

        return [
            'parsed' => iterator_count($this->iterateFile($path, $kind)),
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
        $this->emitPreparing($onProgress, $total);

        $stats = $this->emptyStats();
        $offset = 0;

        while (true) {
            $chunk = $this->plugin->activityChunk($this->parser, $offset, PlaybackReportingClient::CHUNK_SIZE);
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
                ),
            );
            $offset += PlaybackReportingClient::CHUNK_SIZE;
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
     * Fill runtime_sec from a map of item id => seconds. PlayDuration is
     * session length and is kept as recorded. is_finished uses the same 95%
     * rule as live history.
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
     * @return array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int}
     */
    private function importIterable(iterable $rows, int $total, bool $dryRun, ?callable $onProgress): array
    {
        $userNames = $this->userNames();
        $this->emitPreparing($onProgress, $total);

        $stats = $this->emptyStats();
        $batch = [];

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
     * @return array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int}
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
    ): array {
        if ($rows === []) {
            return $this->emptyStats();
        }

        $named = $this->parser->applyUserNames($rows, $userNames);
        $meta = $this->fetchItemMeta($named);
        $enriched = $this->applyRuntimes($named, $this->runtimesFromMeta($meta));
        $enriched = $this->applyLibraries($enriched, $this->librariesFromMeta($meta));
        $progress = $this->wrapBatchProgress($onProgress, $processed, $total, $inserted, $skipped);
        $result = $this->repository->importHistoricalPlays($enriched, $dryRun, $progress);

        return [
            'parsed' => count($named),
            'inserted' => $result['inserted'],
            'skipped' => $result['skipped'],
            'unresolved' => $this->unresolvedCount($enriched),
            'repaired' => $result['repaired'],
        ];
    }

    /**
     * @return array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int}
     */
    private function emptyStats(): array
    {
        return [
            'parsed' => 0,
            'inserted' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'repaired' => 0,
        ];
    }

    /**
     * @param array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int} $stats
     * @param array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int} $chunk
     */
    private function addStats(array &$stats, array $chunk): void
    {
        $stats['parsed'] += $chunk['parsed'];
        $stats['inserted'] += $chunk['inserted'];
        $stats['skipped'] += $chunk['skipped'];
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
        ]);
    }

    /**
     * @param array{parsed: int, inserted: int, skipped: int, unresolved: int, repaired: int} $stats
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
    ): ?callable {
        if ($onProgress === null) {
            return null;
        }

        return static function (array $payload) use ($onProgress, $offset, $total, $inserted, $skipped): void {
            if (($payload['phase'] ?? '') !== 'importing') {
                return;
            }

            $onProgress([
                'phase' => 'importing',
                'processed' => $offset + (int) $payload['processed'],
                'total' => $total,
                'inserted' => $inserted + (int) $payload['inserted'],
                'skipped' => $skipped + (int) $payload['skipped'],
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
