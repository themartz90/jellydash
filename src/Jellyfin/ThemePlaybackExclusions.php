<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Log;

final class ThemePlaybackExclusions
{
    private const BACKFILL_BATCH_SIZE = 25;

    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    private ThemePlaybackClassifier $classifier;
    /** @var array<string, array{type: string, epoch: int, version: int}> */
    private array $observedThemes = [];
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public function __construct(
        ?Database $database = null,
        private readonly string $serverKey = '',
        ?ThemePlaybackClassifier $classifier = null,
    ) {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->classifier = $classifier ?? new ThemePlaybackClassifier();
        $this->ensureSchema();
    }

    public static function serverKey(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');
        $parts = parse_url($normalized);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . ($parts['path'] ?? '')
                . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        return hash('sha256', $normalized);
    }

    public static function canonicalItemId(string $itemId): string
    {
        $itemId = trim($itemId);
        if (preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $itemId) === 1) {
            return strtolower(str_replace('-', '', $itemId));
        }

        return $itemId;
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    public function filterSessions(array $sessions, JellyfinClient $client, ?int $nowEpoch = null): array
    {
        $this->observedThemes = [];
        $nowEpoch ??= time();
        if (!$this->platform->isSqlite()) {
            return $this->filterObservedSessions($sessions, $client, $nowEpoch);
        }
        $timeout = (int) $this->db->query('PRAGMA busy_timeout')->fetchSingle();
        $this->db->query('PRAGMA busy_timeout = 0');
        try {
            return $this->filterObservedSessions($sessions, $client, $nowEpoch);
        } finally {
            $this->db->query('PRAGMA busy_timeout = %i', $timeout);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    private function filterObservedSessions(array $sessions, JellyfinClient $client, int $nowEpoch): array
    {
        $visible = [];
        $unresolved = [];

        foreach ($sessions as $index => $session) {
            $item = is_array($session['NowPlayingItem'] ?? null) ? $session['NowPlayingItem'] : [];
            $itemId = $this->storedItemId((string) ($item['Id'] ?? ''));
            if ($itemId === '') {
                $visible[$index] = $session;
                continue;
            }

            $embedded = $this->classifier->classify($item);
            if ($embedded !== null) {
                if ($embedded === ThemePlaybackClassifier::ORDINARY
                    && !in_array(strtolower((string) ($item['Type'] ?? '')), ['audio', 'video'], true)) {
                    $visible[$index] = $session;
                    continue;
                }
                $baseVersion = (int) ($this->row($itemId)['observation_version'] ?? 0);
                $this->persistClassification(fn () => $this->remember($itemId, (string) ($item['Type'] ?? ''), $embedded, $nowEpoch), true);
                if ($embedded !== ThemePlaybackClassifier::THEME) {
                    $session['_jellydash_theme_classification'] = $embedded;
                    $session['_jellydash_theme_observed_at_epoch'] = $nowEpoch;
                    $session['_jellydash_theme_base_version'] = $baseVersion;
                    $visible[$index] = $session;
                } else {
                    $this->observedThemes[$itemId] = ['type' => (string) ($item['Type'] ?? ''), 'epoch' => $nowEpoch, 'version' => $baseVersion];
                }
                continue;
            }

            $stored = $this->classification($itemId);
            if ($stored === ThemePlaybackClassifier::THEME) {
                continue;
            }
            if ($stored === ThemePlaybackClassifier::ORDINARY) {
                $visible[$index] = $session;
                continue;
            }

            $visible[$index] = $session;
            $existing = $this->row($itemId);
            if ($existing !== null && (int) ($existing['next_retry_epoch'] ?? 0) > $nowEpoch) {
                continue;
            }
            $unresolved[$index] = [
                'stored_id' => $itemId,
                'canonical_id' => self::canonicalItemId($itemId),
                'item_type' => (string) ($item['Type'] ?? ''),
            ];
        }

        if ($unresolved !== []) {
            $observed = [];
            $this->resolve($unresolved, $client, $nowEpoch, $observed);
            foreach ($unresolved as $index => $candidate) {
                $observation = $observed[$candidate['stored_id']] ?? null;
                $classification = $observation['classification'] ?? $this->classification($candidate['stored_id']);
                if ($classification === ThemePlaybackClassifier::THEME) {
                    $this->observedThemes[$candidate['stored_id']] = ['type' => $candidate['item_type'], 'epoch' => $nowEpoch,
                        'version' => (int) ($observation['version'] ?? 0)];
                    unset($visible[$index]);
                } elseif ($classification === ThemePlaybackClassifier::ORDINARY) {
                    if (isset($observed[$candidate['stored_id']])) {
                        $visible[$index]['_jellydash_theme_classification'] = $classification;
                        $visible[$index]['_jellydash_theme_observed_at_epoch'] = $nowEpoch;
                        $visible[$index]['_jellydash_theme_base_version'] = $observation['version'];
                    }
                }
            }
        }

        return array_values($visible);
    }

    /** @return list<array<string, mixed>> */
    public function observedThemeSamples(): array
    {
        $samples = [];
        foreach ($this->observedThemes as $itemId => $observation) {
            $itemId = (string) $itemId;
            // Retain only the classification, never a theme's user or progress.
            $samples[] = ['id' => 'theme:' . hash('sha256', $itemId), 'itemId' => $itemId,
                'itemType' => $observation['type'], 'themeClassification' => ThemePlaybackClassifier::THEME,
                'themeClassificationEpoch' => $observation['epoch'], 'themeClassificationVersion' => $observation['version']];
        }
        return $samples;
    }

    public function classifyHistoryBatch(JellyfinClient $client, int $limit = self::BACKFILL_BATCH_SIZE, ?int $nowEpoch = null): int
    {
        $nowEpoch ??= time();
        $limit = max(1, min(100, $limit));
        $idMatch = $this->platform->isSqlite() ? 'tc.item_id = ph.item_id COLLATE BINARY' : 'tc.item_id = BINARY ph.item_id';
        $rows = $this->db->query(
            'SELECT ph.item_id, ph.item_type, MIN(ph.id) AS first_id
             FROM play_history AS ph
             LEFT JOIN theme_item_classifications AS tc
               ON tc.server_key = %s AND ' . $idMatch . '
             WHERE ph.item_id <> %s
               AND (LOWER(ph.item_type) = %s OR LOWER(ph.item_type) = %s)
               AND (tc.item_id IS NULL OR (tc.classification = %s AND (tc.next_retry_epoch IS NULL OR tc.next_retry_epoch <= %i)))
             GROUP BY ph.item_id, ph.item_type, tc.item_id, tc.next_retry_epoch
             ORDER BY CASE WHEN tc.item_id IS NULL THEN 0 ELSE 1 END, COALESCE(tc.next_retry_epoch, 0), first_id
             LIMIT %i',
            $this->effectiveServerKey(),
            '',
            'audio',
            'video',
            ThemePlaybackClassifier::PENDING,
            $nowEpoch,
            $limit,
        )->fetchAll();

        $candidates = [];
        foreach ($rows as $index => $row) {
            $storedId = $this->storedItemId((string) $row['item_id']);
            if ($storedId === '') {
                continue;
            }
            $candidates[$index] = [
                'stored_id' => $storedId,
                'canonical_id' => self::canonicalItemId($storedId),
                'item_type' => (string) $row['item_type'],
            ];
        }

        return $this->resolve($candidates, $client, $nowEpoch);
    }

    /**
     * @param array<int, array{stored_id: string, canonical_id: string, item_type: string}> $candidates
     * @param array<string, array{classification: string, version: int}>|null $observed
     */
    private function resolve(array $candidates, JellyfinClient $client, int $nowEpoch, ?array &$observed = null): int
    {
        if ($candidates === []) {
            return 0;
        }

        try {
            $meta = $client->itemPlaybackMeta(array_values(array_unique(array_column($candidates, 'stored_id'))));
        } catch (\Throwable) {
            foreach ($candidates as $candidate) {
                $this->persistClassification(fn () => $this->pending($candidate['stored_id'], $candidate['item_type'], $nowEpoch), $observed !== null);
            }

            return 0;
        }

        $settled = 0;
        foreach ($candidates as $candidate) {
            $item = $meta[$candidate['canonical_id']] ?? null;
            if (!is_array($item)) {
                $this->persistClassification(fn () => $this->pending($candidate['stored_id'], $candidate['item_type'], $nowEpoch), $observed !== null);
                continue;
            }
            if (trim($item['Type']) === '') {
                $item['Type'] = $candidate['item_type'];
            }
            $classification = $this->classifier->classify($item);
            if ($classification === null) {
                $this->persistClassification(fn () => $this->pending($candidate['stored_id'], $candidate['item_type'], $nowEpoch), $observed !== null);
                continue;
            }
            if ($observed !== null) {
                $observed[$candidate['stored_id']] = ['classification' => $classification,
                    'version' => (int) ($this->row($candidate['stored_id'])['observation_version'] ?? 0)];
            }
            $this->persistClassification(fn () => $this->remember($candidate['stored_id'], $candidate['item_type'], $classification, $nowEpoch), $observed !== null);
            ++$settled;
        }

        return $settled;
    }

    /** @param callable(): void $write */
    private function persistClassification(callable $write, bool $optional): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            if (!$optional) {
                throw $e;
            }
            // The observed classification still filters this sample when its
            // optional cache write cannot run during a History restore.
            Log::logException($e);
        }
    }

    public function remember(string $itemId, string $itemType, string $classification, ?int $nowEpoch = null, bool $fromQueue = false, ?int $expectedVersion = null): void
    {
        if (!in_array($classification, [ThemePlaybackClassifier::THEME, ThemePlaybackClassifier::ORDINARY], true)) {
            throw new \InvalidArgumentException('Invalid theme playback classification.');
        }
        $itemId = $this->storedItemId($itemId);
        if ($itemId === '') {
            return;
        }
        $nowEpoch ??= time();
        $data = [
            'item_type' => $itemType,
            'classification' => $classification,
            'attempts' => 0,
            'next_retry_epoch' => null,
            'updated_at_epoch' => $nowEpoch,
        ];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $existing = $this->row($itemId);
            if ($existing !== null && in_array($existing['classification'], [ThemePlaybackClassifier::THEME, ThemePlaybackClassifier::ORDINARY], true)) {
                $previousEpoch = (int) $existing['updated_at_epoch'];
                if ($previousEpoch > $nowEpoch || ($previousEpoch === $nowEpoch && $fromQueue
                    && ($expectedVersion === null || (int) $existing['observation_version'] !== $expectedVersion))) {
                    return;
                }
            }
            $data['observation_version'] = (int) ($existing['observation_version'] ?? 0) + 1;
            if ($existing === null) {
                try {
                    $this->db->insert('theme_item_classifications', [
                        'server_key' => $this->effectiveServerKey(), 'item_id' => $itemId, ...$data,
                    ])->execute();
                } catch (\Dibi\UniqueConstraintViolationException) {
                    continue;
                }
            } else {
                $this->db->update('theme_item_classifications', $data)
                    ->where('server_key = %s AND item_id = %s', $this->effectiveServerKey(), $itemId)
                    ->where('updated_at_epoch = %i AND classification = %s', (int) $existing['updated_at_epoch'], (string) $existing['classification'])
                    ->where('observation_version = %i', (int) $existing['observation_version'])
                    ->execute();
                if ($this->db->getAffectedRows() === 0) {
                    continue;
                }
            }
            if (($existing['classification'] ?? null) !== $classification
                && ($classification === ThemePlaybackClassifier::THEME || ($existing['classification'] ?? null) === ThemePlaybackClassifier::THEME)) {
                $this->bumpRevision();
            }
            return;
        }
        throw new HistoryWriteBusyException('Theme classification changed while recording playback. Captured samples will be retried.');
    }

    public function classification(string $itemId): ?string
    {
        $row = $this->row($this->storedItemId($itemId));

        return $row === null ? null : (string) $row['classification'];
    }

    public function revision(): int
    {
        return (int) $this->db->select('revision')->from('theme_classification_state')
            ->where('server_key = %s', $this->effectiveServerKey())->fetchSingle();
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->effectiveServerKey() . ':' . $this->revision());
    }

    public function visibilitySql(string $historyAlias = 'play_history', bool $alsoPending = false): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $historyAlias) !== 1) {
            throw new \InvalidArgumentException('Invalid history table alias.');
        }
        $classifications = $alsoPending
            ? [ThemePlaybackClassifier::THEME, ThemePlaybackClassifier::PENDING]
            : [ThemePlaybackClassifier::THEME];

        $idMatch = $this->platform->isSqlite()
            ? 'theme_filter.item_id = ' . $historyAlias . '.item_id COLLATE BINARY'
            : 'theme_filter.item_id = BINARY ' . $historyAlias . '.item_id';

        return $this->db->translate(
            'NOT EXISTS (SELECT 1 FROM theme_item_classifications AS theme_filter
                WHERE theme_filter.server_key = %s
                  AND ' . $idMatch . '
                  AND theme_filter.classification IN %in)',
            $this->effectiveServerKey(),
            $classifications,
        );
    }

    private function pending(string $itemId, string $itemType, int $nowEpoch): void
    {
        $itemId = $this->storedItemId($itemId);
        if ($itemId === '') {
            return;
        }
        $existing = $this->row($itemId);
        if ($existing !== null && in_array((string) $existing['classification'], [ThemePlaybackClassifier::THEME, ThemePlaybackClassifier::ORDINARY], true)) {
            return;
        }
        $attempts = min(20, (int) ($existing['attempts'] ?? 0) + 1);
        $delays = [60, 300, 1800, 21600, 86400];
        $delay = $delays[min($attempts - 1, count($delays) - 1)];
        $data = [
            'item_type' => $itemType,
            'classification' => ThemePlaybackClassifier::PENDING,
            'attempts' => $attempts,
            'next_retry_epoch' => $nowEpoch + $delay,
            'updated_at_epoch' => $nowEpoch,
        ];
        if ($existing !== null) {
            $this->db->update('theme_item_classifications', $data)
                ->where('server_key = %s', $this->effectiveServerKey())
                ->where('item_id = %s', $itemId)
                ->where('classification = %s', ThemePlaybackClassifier::PENDING)->execute();
            return;
        }
        try {
            $this->db->insert('theme_item_classifications', [
                'server_key' => $this->effectiveServerKey(), 'item_id' => $itemId, ...$data,
            ])->execute();
        } catch (\Dibi\Exception $error) {
            if ($this->row($itemId) === null) {
                throw $error;
            }
        }
    }

    private function storedItemId(string $itemId): string
    {
        return trim($itemId);
    }

    private function effectiveServerKey(): string
    {
        return $this->serverKey !== '' ? $this->serverKey : self::serverKey('');
    }

    private function row(string $itemId): ?\Dibi\Row
    {
        if ($itemId === '') {
            return null;
        }
        $row = $this->db->select('classification, attempts, next_retry_epoch, updated_at_epoch, observation_version')->from('theme_item_classifications')
            ->where('server_key = %s', $this->effectiveServerKey())
            ->where('item_id = %s', $itemId)
            ->fetch();

        return $row ?: null;
    }

    private function bumpRevision(): void
    {
        $this->db->query(
            'UPDATE theme_classification_state SET revision = revision + 1 WHERE server_key = %s',
            $this->effectiveServerKey(),
        );
        if ($this->db->getAffectedRows() > 0) {
            return;
        }
        try {
            $this->db->insert('theme_classification_state', [
                'server_key' => $this->effectiveServerKey(),
                'revision' => 1,
            ])->execute();
        } catch (\Dibi\Exception $error) {
            $this->db->query(
                'UPDATE theme_classification_state SET revision = revision + 1 WHERE server_key = %s',
                $this->effectiveServerKey(),
            );
            if ($this->revision() < 1) {
                throw $error;
            }
        }
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `theme_item_classifications` (
                `server_key` char(64) NOT NULL,
                `item_id` varchar(64) NOT NULL,
                `item_type` varchar(16) NOT NULL,
                `classification` varchar(16) NOT NULL,
                `attempts` tinyint NOT NULL DEFAULT 0,
                `next_retry_epoch` bigint DEFAULT NULL,
                `updated_at_epoch` bigint NOT NULL,
                `observation_version` bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (`server_key`, `item_id`),
                KEY `idx_theme_retry` (`server_key`, `classification`, `next_retry_epoch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            'CREATE TABLE IF NOT EXISTS `theme_item_classifications` (
                `server_key` TEXT NOT NULL,
                `item_id` TEXT NOT NULL,
                `item_type` TEXT NOT NULL,
                `classification` TEXT NOT NULL,
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `next_retry_epoch` INTEGER DEFAULT NULL,
                `updated_at_epoch` INTEGER NOT NULL,
                `observation_version` INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (`server_key`, `item_id`)
            )',
        );
        $this->platform->createSqliteIndex('idx_theme_retry', 'theme_item_classifications', ['server_key', 'classification', 'next_retry_epoch']);
        if (!$this->platform->columnExists('theme_item_classifications', 'observation_version')) {
            try {
                $this->platform->addColumn('theme_item_classifications', '`observation_version` bigint NOT NULL DEFAULT 0', '`observation_version` INTEGER NOT NULL DEFAULT 0');
            } catch (\Dibi\Exception $e) {
                if (!$this->platform->columnExists('theme_item_classifications', 'observation_version')) {
                    throw $e;
                }
            }
        }
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `theme_classification_state` (
                `server_key` char(64) NOT NULL,
                `revision` bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (`server_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            'CREATE TABLE IF NOT EXISTS `theme_classification_state` (
                `server_key` TEXT PRIMARY KEY,
                `revision` INTEGER NOT NULL DEFAULT 0
            )',
        );
        self::$schemaConnections[$this->db] = true;
    }
}
