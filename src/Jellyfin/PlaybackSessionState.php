<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Database;

/** Keeps the current viewing generation separately from immutable historical rows. */
final class PlaybackSessionState
{
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;
    public function __construct(private Database $database)
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$database->getDibi()])) {
            return;
        }
        $database->getPlatform()->createTable(
            'CREATE TABLE IF NOT EXISTS history_playback_sessions (source_key varchar(64) NOT NULL, connection_key varchar(64) NOT NULL, owner_key varchar(64) NOT NULL, server_key varchar(64) NOT NULL, journal_key varchar(32) NOT NULL, capture_order bigint NOT NULL, observed_at bigint NOT NULL, is_open tinyint NOT NULL DEFAULT 0, item_id varchar(64) DEFAULT NULL, stream_key varchar(128) DEFAULT NULL, PRIMARY KEY(source_key,connection_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS history_playback_sessions (source_key TEXT NOT NULL, connection_key TEXT NOT NULL, owner_key TEXT NOT NULL, server_key TEXT NOT NULL, journal_key TEXT NOT NULL, capture_order INTEGER NOT NULL, observed_at INTEGER NOT NULL, is_open INTEGER NOT NULL DEFAULT 0, item_id TEXT DEFAULT NULL, stream_key TEXT DEFAULT NULL, PRIMARY KEY(source_key,connection_key))'
        );
        foreach (['pending_item_id' => 64, 'pending_stream_key' => 128, 'pending_owner_key' => 64, 'pending_server_key' => 64] as $column => $size) {
            if (!$database->getPlatform()->columnExists('history_playback_sessions', $column)) {
                $database->getPlatform()->addColumn('history_playback_sessions', '`' . $column . '` varchar(' . $size . ') DEFAULT NULL', '`' . $column . '` TEXT DEFAULT NULL');
            }
        }
        $database->getPlatform()->createTable(
            'CREATE TABLE IF NOT EXISTS history_capture_receipts (source_key varchar(64) NOT NULL, receipt_id varchar(32) NOT NULL, observed_at bigint NOT NULL, PRIMARY KEY(source_key,receipt_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS history_capture_receipts (source_key TEXT NOT NULL, receipt_id TEXT NOT NULL, observed_at INTEGER NOT NULL, PRIMARY KEY(source_key,receipt_id))'
        );
        self::$schemaConnections[$database->getDibi()] = true;
    }

    /** @return array<string, mixed>|null */
    public function connection(string $source, string $id): ?array
    {
        $row = $this->database->getDibi()->select('*')->from('history_playback_sessions')
            ->where('source_key = %s AND connection_key = %s', $source, hash('sha256', $id))->fetch();
        return $row === null ? null : $row->toArray();
    }

    public function captureOrder(string $source, string $journal): int
    {
        $db = $this->database->getDibi();
        $sqlite = $this->database->getPlatform()->isSqlite();
        $timeout = $sqlite ? (int) $db->query('PRAGMA busy_timeout')->fetchSingle() : 0;
        if ($sqlite) {
            $db->query('PRAGMA busy_timeout = 0');
        }
        try {
            return (int) $db->select('MAX(capture_order)')->from('history_playback_sessions')->where('source_key = %s AND journal_key = %s', $source, $journal)->fetchSingle();
        } finally {
            if ($sqlite) {
                $db->query('PRAGMA busy_timeout = %i', $timeout);
            }
        }
    }

    /** @param array<string, mixed> $frame @param array<string, mixed> $connection
     * @return array<string, mixed>|null
     */
    public function next(array $frame, array $connection, \DateTimeImmutable $at): ?array
    {
        $old = $this->connection($frame['source'], $connection['id']);
        $sameJournal = $old !== null && $old['journal_key'] === $frame['journal'];
        if ($sameJournal && (int) $old['capture_order'] >= $frame['order'] && ($frame['receipt_id'] ?? null) === null) {
            return null;
        }
        $sameOwner = $old !== null && $old['owner_key'] === $connection['owner'];
        $emptyServer = hash('sha256', '');
        $sameServer = $old !== null && ($old['server_key'] === $emptyServer || $connection['server'] === $emptyServer || $old['server_key'] === $connection['server']);
        $pendingOwner = $old !== null && $old['pending_owner_key'] === $connection['owner'];
        $pendingServer = $old !== null && ($old['pending_server_key'] === $emptyServer || $connection['server'] === $emptyServer || $old['pending_server_key'] === $connection['server']);
        if ($connection['idle'] && ($old === null || !$sameJournal || !(($sameOwner && $sameServer) || ($pendingOwner && $pendingServer)))) {
            return null;
        }
        $continue = $sameJournal && $sameOwner && $sameServer && (int) $old['is_open'] === 1 && $old['item_id'] === $connection['item'];
        $pendingContinue = $sameJournal && $pendingOwner && $pendingServer && $old['pending_item_id'] === $connection['item'];
        $next = ['source_key' => $frame['source'], 'connection_key' => hash('sha256', $connection['id']),
            'owner_key' => $connection['owner'], 'server_key' => $connection['server'] === $emptyServer && $sameOwner && $sameServer ? $old['server_key'] : $connection['server'],
            'journal_key' => $frame['journal'], 'capture_order' => $sameJournal ? max((int) $old['capture_order'], $frame['order']) : $frame['order'], 'observed_at' => (int) $at->format('Uu'),
            'is_open' => $connection['idle'] ? 0 : 1, 'item_id' => $connection['item'],
            'stream_key' => $connection['idle'] ? $old['stream_key'] : ($continue ? $old['stream_key'] : ($pendingContinue ? $old['pending_stream_key'] : $this->newStreamKey($frame['source']))),
            'pending_item_id' => null, 'pending_stream_key' => null, 'pending_owner_key' => null, 'pending_server_key' => null];
        if ($connection['idle'] && !($sameOwner && $sameServer)) {
            foreach (['owner_key', 'server_key', 'is_open', 'item_id', 'stream_key'] as $field) {
                $next[$field] = $old[$field];
            }
        }
        if (!$connection['idle'] && !($connection['eligible'] ?? true)) {
            foreach (['owner_key', 'server_key', 'is_open', 'item_id', 'stream_key'] as $field) {
                $next[$field] = $sameJournal ? $old[$field] : match ($field) {
                    'owner_key' => $connection['owner'], 'server_key' => $connection['server'], 'is_open' => 0, default => null,
                };
            }
            $next['pending_item_id'] = $connection['item'];
            $next['pending_owner_key'] = $connection['owner'];
            $next['pending_server_key'] = $pendingContinue && $connection['server'] === $emptyServer ? $old['pending_server_key'] : $connection['server'];
            $next['pending_stream_key'] = $pendingContinue ? $old['pending_stream_key'] : $this->newStreamKey($frame['source']);
        }
        if (!$connection['idle'] && ($connection['eligible'] ?? true) && $pendingContinue && $connection['server'] === $emptyServer) {
            $next['server_key'] = $old['pending_server_key'];
        }
        return $next;
    }

    private function newStreamKey(string $source): string
    {
        return 'jd-view-' . hash('sha256', $source . random_bytes(32));
    }

    public function hasReceipt(string $source, string $id): bool
    {
        return $this->database->getDibi()->select('receipt_id')->from('history_capture_receipts')->where('source_key = %s AND receipt_id = %s', $source, $id)->fetchSingle() !== null;
    }

    public function acknowledge(string $source, string $id, \DateTimeImmutable $at): void
    {
        $this->database->getDibi()->insert('history_capture_receipts', ['source_key' => $source, 'receipt_id' => $id, 'observed_at' => (int) $at->format('Uu')])->execute();
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $db = $this->database->getDibi();
        $db->delete('history_playback_sessions')->where('source_key = %s AND connection_key = %s', $state['source_key'], $state['connection_key'])->execute();
        $db->insert('history_playback_sessions', $state)->execute();
    }
}
