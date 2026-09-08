<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;

/** Restores a native Jellydash History CSV without firing playback alerts. */
final class HistoryCsvImporter
{
    private const BATCH_SIZE = 500;

    private Database $database;
    private HistoryCsvParser $parser;
    private PlayHistoryRepository $repository;

    public function __construct(
        ?Database $database = null,
        ?HistoryCsvParser $parser = null,
        ?PlayHistoryRepository $repository = null,
    ) {
        $this->database = $database ?? Container::db();
        $this->parser = $parser ?? new HistoryCsvParser();
        $this->repository = $repository ?? new PlayHistoryRepository($this->database);
    }

    /** @return array{parsed: int, importable: int, skipped: int, kind: 'jellydash'} */
    public function previewFile(string $path): array
    {
        $path = $this->readablePath($path);
        $stats = $this->process($path, true);

        return [
            'parsed' => $stats['parsed'],
            'importable' => $stats['inserted'],
            'skipped' => $stats['skipped'],
            'kind' => 'jellydash',
        ];
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int}
     */
    public function importFile(string $path, ?callable $onProgress = null): array
    {
        $path = $this->readablePath($path);
        $total = iterator_count($this->parser->iterateFile($path));
        $this->emit($onProgress, 'preparing', 0, $total, 0, 0);

        $connection = $this->database->getDibi();
        $connection->begin();
        try {
            $stats = $this->process($path, false, $onProgress, $total);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }

        return $stats;
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{parsed: int, inserted: int, skipped: int}
     */
    private function process(
        string $path,
        bool $dryRun,
        ?callable $onProgress = null,
        ?int $knownTotal = null,
    ): array {
        $total = $knownTotal ?? iterator_count($this->parser->iterateFile($path));
        $stats = ['parsed' => 0, 'inserted' => 0, 'skipped' => 0];
        $batch = [];

        foreach ($this->parser->iterateFile($path) as $row) {
            $batch[] = $row;
            if (count($batch) >= self::BATCH_SIZE) {
                $this->importBatch($batch, $dryRun, $onProgress, $total, $stats);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->importBatch($batch, $dryRun, $onProgress, $total, $stats);
        }

        return $stats;
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @param array{parsed: int, inserted: int, skipped: int} $stats
     */
    private function importBatch(
        array $batch,
        bool $dryRun,
        ?callable $onProgress,
        int $total,
        array &$stats,
    ): void {
        $offset = $stats['parsed'];
        $inserted = $stats['inserted'];
        $skipped = $stats['skipped'];
        $progress = $onProgress === null ? null : function (array $payload) use (
            $onProgress,
            $offset,
            $inserted,
            $skipped,
            $total,
        ): void {
            $this->emit(
                $onProgress,
                'importing',
                $offset + (int) $payload['processed'],
                $total,
                $inserted + (int) $payload['inserted'],
                $skipped + (int) $payload['skipped'],
            );
        };

        $result = $this->repository->importNativeHistoricalPlays($batch, $dryRun, $progress);
        $stats['parsed'] += count($batch);
        $stats['inserted'] += $result['inserted'];
        $stats['skipped'] += $result['skipped'];
    }

    /** @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress */
    private function emit(
        ?callable $onProgress,
        string $phase,
        int $processed,
        int $total,
        int $inserted,
        int $skipped,
    ): void {
        if ($onProgress === null) {
            return;
        }

        $onProgress([
            'phase' => $phase,
            'processed' => $processed,
            'total' => $total,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ]);
    }

    private function readablePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Jellydash History CSV is not readable.');
        }

        return $path;
    }
}
