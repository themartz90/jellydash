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

    public function __construct(
        ?PlaybackReportingParser $parser = null,
        ?PlayHistoryRepository $repository = null,
        ?JellyfinClient $client = null,
    ) {
        $this->parser = $parser ?? new PlaybackReportingParser();
        $this->repository = $repository ?? new PlayHistoryRepository();
        $this->client = $client ?? new JellyfinClient();
    }

    /**
     * @param 'tsv'|'sqlite'|null $kind
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int}
     */
    public function importFile(string $path, bool $dryRun = false, ?string $kind = null, ?callable $onProgress = null): array
    {
        $path = $this->readablePath($path);

        return $this->importRows($this->parseFile($path, $kind), $dryRun, $onProgress);
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int}
     */
    public function importTsvString(string $contents, bool $dryRun = false, ?callable $onProgress = null): array
    {
        return $this->importRows($this->parser->parseTsv($contents), $dryRun, $onProgress);
    }

    /**
     * @return array{parsed: int, kind: 'tsv'|'sqlite'}
     */
    public function previewFile(string $path): array
    {
        $path = $this->readablePath($path);
        $kind = $this->detectKind($path);
        $rows = $this->parseFile($path, $kind);

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
            'parsed' => $this->client->playbackReportingCount(),
            'kind' => 'plugin',
        ];
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int}
     */
    public function importFromPlugin(bool $dryRun = false, ?callable $onProgress = null): array
    {
        return $this->importRows($this->client->playbackReportingActivity($this->parser), $dryRun, $onProgress);
    }

    /**
     * @param 'tsv'|'sqlite'|null $kind
     * @return list<array<string, mixed>>
     */
    public function parseFile(string $path, ?string $kind = null): array
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
     * @param list<array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int}
     */
    public function importRows(array $rows, bool $dryRun = false, ?callable $onProgress = null): array
    {
        $named = $this->parser->applyUserNames($rows, $this->userNames());
        if ($onProgress !== null) {
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
        $result = $this->repository->importHistoricalPlays($enriched, $dryRun, $onProgress);

        if (!$dryRun && ($result['inserted'] > 0 || $result['repaired'] > 0)) {
            $this->refreshLibraryOverview();
        }

        return [
            'parsed' => count($enriched),
            'inserted' => $result['inserted'],
            'skipped' => $result['skipped'],
        ];
    }

    /**
     * Fill runtime_sec from a map of item id => seconds. PlayDuration is
     * session length, so a longer session is capped at the media runtime.
     * is_finished uses the same 95% rule as live history. Missing items keep
     * runtime_sec = 0.
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
            if ($runtime > 0 && $watched > $runtime) {
                $watched = $runtime;
            }

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
