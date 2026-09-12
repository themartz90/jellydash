<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\AppSettings;
use Mk\Framework\Container;
use Mk\Framework\Database;

/**
 * @phpstan-type BackfillStatus array{state: 'pending'|'running'|'complete', required: bool, total: int, processed: int, percent: int, busy: bool}
 * @phpstan-type StoredState array{version: int, state: 'running'|'complete', high_watermark: int, cursor_id: int, total: int, processed: int, retry_after: int}
 */
final class HistoryLibraryBackfillService
{
    public const STATE_KEY = 'history_library_backfill_v1';

    private const STATE_VERSION = 1;
    private const DEFAULT_BATCH_SIZE = 100;

    private Database $database;
    private \Dibi\Connection $db;
    private PlayHistoryRepository $history;
    /** @var \Closure(array<int, string>): array<string, array{runtime_sec: int, library: string}> */
    private \Closure $metaLoader;
    private string $lockPath;

    /**
     * @param null|callable(array<int, string>): array<string, array{runtime_sec: int, library: string}> $metaLoader
     */
    public function __construct(
        ?Database $database = null,
        ?callable $metaLoader = null,
        ?string $lockPath = null,
    ) {
        $this->database = $database ?? Container::db();
        $this->db = $this->database->getDibi();
        $this->history = new PlayHistoryRepository($this->database);
        AppSettings::ensureSchema($this->database);

        if ($metaLoader !== null) {
            $this->metaLoader = \Closure::fromCallable($metaLoader);
        } else {
            $client = new JellyfinClient();
            $this->metaLoader = static fn (array $ids): array => $client->itemImportMeta($ids);
        }

        $this->lockPath = $lockPath ?? dirname(__DIR__, 2) . '/var/cache/history-library-backfill.lock';
    }

    /** @return BackfillStatus */
    public function status(): array
    {
        $stored = $this->loadState();
        if ($stored !== null) {
            return $this->publicStatus($stored);
        }

        [$total] = $this->candidateMetrics();

        return [
            'state' => $total > 0 ? 'pending' : 'complete',
            'required' => $total > 0,
            'total' => $total,
            'processed' => 0,
            'percent' => $total > 0 ? 0 : 100,
            'busy' => false,
        ];
    }

    /**
     * Process one bounded batch. The normal web flow starts the task; the
     * background poller only continues a task that a user has already seen.
     *
     * @return BackfillStatus
     */
    public function runBatch(int $limit = self::DEFAULT_BATCH_SIZE): array
    {
        return $this->processBatch($limit, true);
    }

    /** @return BackfillStatus|null */
    public function continueStartedBatch(int $limit = self::DEFAULT_BATCH_SIZE): ?array
    {
        $state = $this->loadState();
        if ($state === null || $state['state'] === 'complete') {
            return null;
        }
        if ($state['retry_after'] > time()) {
            return null;
        }

        return $this->processBatch($limit, false);
    }

    /** @return BackfillStatus */
    private function processBatch(int $limit, bool $startIfNeeded): array
    {
        $limit = max(1, min(500, $limit));
        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $status = $this->status();
            $status['busy'] = true;

            return $status;
        }

        try {
            $state = $this->loadState();
            if ($state === null && !$startIfNeeded) {
                return $this->status();
            }
            if ($state === null) {
                $state = $this->initializeState();
            }
            if ($state['state'] === 'complete') {
                return $this->publicStatus($state);
            }

            $rows = $this->candidateRows($state['cursor_id'], $state['high_watermark'], $limit);
            if ($rows === []) {
                $state['state'] = 'complete';
                $state['processed'] = $state['total'];
                $this->saveState($state);

                return $this->publicStatus($state);
            }

            $ids = [];
            foreach ($rows as $row) {
                if (trim((string) ($row['library_resolved_at'] ?? '')) !== '') {
                    continue;
                }
                $id = $this->normalizedItemId((string) ($row['item_id'] ?? ''));
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }

            try {
                $meta = $ids === [] ? [] : ($this->metaLoader)(array_values($ids));
            } catch (\Throwable $e) {
                $state['retry_after'] = time() + 300;
                $this->saveState($state);
                throw $e;
            }
            $libraries = $this->resolvedLibraries($meta);
            $last = $rows[array_key_last($rows)];
            $nowInstant = new \DateTimeImmutable('now');
            $now = $nowInstant->format('Y-m-d H:i:s');

            $this->db->begin();
            try {
                foreach ($rows as $row) {
                    if (trim((string) ($row['library_resolved_at'] ?? '')) !== '') {
                        continue;
                    }
                    $itemId = $this->normalizedItemId((string) ($row['item_id'] ?? ''));
                    if (!isset($libraries[$itemId])) {
                        continue;
                    }
                    $this->db->update('play_history', [
                        'library' => $libraries[$itemId],
                        'library_resolved_at' => $now,
                        'library_resolved_at_epoch' => $nowInstant->getTimestamp(),
                    ])->where('id = %i', (int) $row['id'])
                        ->where('library_resolved_at IS NULL')
                        ->execute();
                }

                $state['cursor_id'] = (int) $last['id'];
                $state['processed'] = min($state['total'], $state['processed'] + count($rows));
                $state['retry_after'] = 0;
                if ($state['cursor_id'] >= $state['high_watermark'] || count($rows) < $limit) {
                    $state['state'] = 'complete';
                    $state['processed'] = $state['total'];
                }
                $this->saveState($state);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }

            return $this->publicStatus($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function openLock()
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the History upgrade lock directory.');
        }

        $lock = @fopen($this->lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the History upgrade lock.');
        }

        return $lock;
    }

    /** @return StoredState */
    private function initializeState(): array
    {
        [$total, $highWatermark] = $this->candidateMetrics();
        $state = [
            'version' => self::STATE_VERSION,
            'state' => $total > 0 ? 'running' : 'complete',
            'high_watermark' => $highWatermark,
            'cursor_id' => 0,
            'total' => $total,
            'processed' => 0,
            'retry_after' => 0,
        ];
        $this->saveState($state);

        return $state;
    }

    /** @return array{int, int} */
    private function candidateMetrics(): array
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS row_count, COALESCE(MAX(id), 0) AS maximum_id
             FROM play_history
             WHERE item_id <> '' AND item_type <> 'TvChannel'
               AND " . $this->history->visibleHistorySql('play_history')
        )->fetch();
        if ($row === false) {
            return [0, 0];
        }

        return [(int) $row['row_count'], (int) $row['maximum_id']];
    }

    /** @return list<\Dibi\Row> */
    private function candidateRows(int $afterId, int $highWatermark, int $limit): array
    {
        return $this->db->select('id, item_id, library_resolved_at')
            ->from('play_history')
            ->where('id > %i', $afterId)
            ->where('id <= %i', $highWatermark)
            ->where('item_id <> %s', '')
            ->where('item_type <> %s', 'TvChannel')
            ->where($this->history->visibleHistorySql('play_history'))
            ->orderBy('id')
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * @param array<string, array{runtime_sec: int, library: string}> $meta
     * @return array<string, string>
     */
    private function resolvedLibraries(array $meta): array
    {
        $libraries = [];
        foreach ($meta as $itemId => $item) {
            $itemId = $this->normalizedItemId((string) $itemId);
            $library = trim($item['library']);
            if ($itemId !== '' && $library !== '') {
                $libraries[$itemId] = $library;
            }
        }

        return $libraries;
    }

    private function normalizedItemId(string $itemId): string
    {
        return strtolower(str_replace('-', '', trim($itemId)));
    }

    /** @return StoredState|null */
    private function loadState(): ?array
    {
        $raw = $this->db->select('setting_value')->from('app_settings')
            ->where('setting_key = %s', self::STATE_KEY)
            ->fetchSingle();
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($state)
            || (int) ($state['version'] ?? 0) !== self::STATE_VERSION
            || !in_array($state['state'] ?? null, ['running', 'complete'], true)
        ) {
            return null;
        }

        return [
            'version' => self::STATE_VERSION,
            'state' => $state['state'],
            'high_watermark' => max(0, (int) ($state['high_watermark'] ?? 0)),
            'cursor_id' => max(0, (int) ($state['cursor_id'] ?? 0)),
            'total' => max(0, (int) ($state['total'] ?? 0)),
            'processed' => max(0, (int) ($state['processed'] ?? 0)),
            'retry_after' => max(0, (int) ($state['retry_after'] ?? 0)),
        ];
    }

    /** @param StoredState $state */
    private function saveState(array $state): void
    {
        $value = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        try {
            $this->db->insert('app_settings', [
                'setting_key' => self::STATE_KEY,
                'setting_value' => $value,
                'updated_at' => $now,
            ])->execute();
        } catch (\Dibi\UniqueConstraintViolationException) {
            $this->db->update('app_settings', [
                'setting_value' => $value,
                'updated_at' => $now,
            ])->where('setting_key = %s', self::STATE_KEY)->execute();
        }
    }

    /**
     * @param StoredState $state
     * @return BackfillStatus
     */
    private function publicStatus(array $state): array
    {
        $total = $state['total'];
        $processed = min($total, $state['processed']);
        $complete = $state['state'] === 'complete';
        $percent = $complete || $total === 0
            ? 100
            : (int) floor(($processed / $total) * 100);

        return [
            'state' => $state['state'],
            'required' => !$complete,
            'total' => $total,
            'processed' => $processed,
            'percent' => $percent,
            'busy' => false,
        ];
    }
}
