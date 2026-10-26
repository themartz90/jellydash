<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

/**
 * Local mirror of Jellyseerr requests.
 *
 * Titles and posters never change, so each request is looked up once and stored;
 * only the two status fields are refreshed from the request list. That
 * makes the Requests page a plain SELECT: no API calls in the request path, and
 * it keeps working when Jellyseerr is unreachable.
 */
final class SeerrRequestRepository
{
    private const FRESH_WINDOW_SECONDS = 600;

    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public function __construct(?Database $database = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->ensureSchema();
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function count(): int
    {
        return (int) $this->db->select('COUNT(*)')->from('seerr_requests')->fetchSingle();
    }

    /**
     * Which of the given Jellyseerr request ids we already store.
     *
     * @param array<int, int> $requestIds
     * @return array<int, int>
     */
    public function knownIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = $this->db->select('request_id')
            ->from('seerr_requests')
            ->where('request_id IN %in', $requestIds)
            ->fetchPairs(null, 'request_id');

        return array_map('intval', $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): void
    {
        try {
            $this->db->insert('seerr_requests', $row)->execute();
        } catch (\Dibi\UniqueConstraintViolationException) {
            // Another sync inserted it first; nothing to do.
        }
    }

    public function updateStatuses(int $requestId, int $requestStatus, int $mediaStatus, ?int $requestedAtEpoch = null): void
    {
        $data = [
            'request_status' => $requestStatus,
            'media_status' => $mediaStatus,
        ];
        if ($requestedAtEpoch !== null) {
            $data['requested_at_epoch'] = $requestedAtEpoch;
        }
        $this->db->update('seerr_requests', $data)
            ->where('request_id = %i', $requestId)
            ->execute();
    }

    /** @return list<\Dibi\Row> */
    public function pendingMetadata(int $limit, int $nowEpoch): array
    {
        return $this->db->select('*')->from('seerr_requests')
            ->where("(metadata_pending = 1 OR (metadata_pending IS NULL AND year IS NULL AND poster_path IS NULL AND ((media_type = 'movie' AND title = 'Unknown title') OR (media_type = 'tv' AND title = 'Unknown series'))))")
            ->where('tmdb_id > 0')
            ->where('(metadata_retry_at_epoch IS NULL OR metadata_retry_at_epoch <= %i)', $nowEpoch)
            ->orderBy('COALESCE(metadata_retry_at_epoch, 0)')
            ->orderBy('request_id')
            ->limit(max(1, min(5, $limit)))
            ->fetchAll();
    }

    /** @param array{title: string, year: ?string, posterPath: ?string}|null $details */
    public function finishMetadataAttempt(\Dibi\Row $pending, ?array $details, int $nowEpoch): void
    {
        $attempts = min(1_000_000, (int) $pending['metadata_attempts'] + 1);
        $data = $details === null ? [
            'metadata_pending' => 1,
            'metadata_attempts' => $attempts,
            'metadata_retry_at_epoch' => $nowEpoch + min(3600, 60 * (2 ** min(6, $attempts - 1))),
        ] : [
            'title' => $details['title'],
            'year' => $details['year'],
            'poster_path' => $details['posterPath'],
            'metadata_pending' => 0,
            'metadata_attempts' => 0,
            'metadata_retry_at_epoch' => null,
        ];
        $this->db->update('seerr_requests', $data)
            ->where('request_id = %i', (int) $pending['request_id'])
            ->where('(metadata_pending IS NULL OR metadata_pending = 1)')
            ->where('metadata_attempts = %i', (int) $pending['metadata_attempts'])
            ->where('COALESCE(metadata_retry_at_epoch, 0) = %i', (int) ($pending['metadata_retry_at_epoch'] ?? 0))
            ->execute();
    }

    /**
     * Apply one completely fetched remote prefix as a transaction, so a failed
     * row cannot establish a newer boundary while leaving older requests out.
     *
     * @param array<int, array{request_id: int, request_status: int, media_status: int, requested_at_epoch?: ?int}> $statusUpdates
     * @param array<int, array<string, mixed>> $inserts
     */
    public function applySyncBatch(array $statusUpdates, array $inserts): int
    {
        $inserted = 0;
        $this->db->begin();
        try {
            foreach ($statusUpdates as $update) {
                $this->updateStatuses($update['request_id'], $update['request_status'], $update['media_status'], $update['requested_at_epoch'] ?? null);
            }
            foreach ($inserts as $row) {
                try {
                    $this->db->insert('seerr_requests', $row)->execute();
                    ++$inserted;
                } catch (\Dibi\UniqueConstraintViolationException) {
                    // A concurrent completed sync already stored this request.
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $inserted;
    }

    /**
     * Newest requests first, for the page.
     *
     * @return array<int, \Dibi\Row>
     */
    public function latest(int $limit = 24): array
    {
        return $this->db->select('*')
            ->from('seerr_requests')
            ->orderBy('requested_at')->desc()
            ->orderBy('request_id')->desc()
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /** A disabled notification switch makes already-mirrored requests a baseline. */
    public function retireUnnotified(): void
    {
        $this->db->update('seerr_requests', [
            'notified' => 1,
            'notification_claim_token' => null,
            'notification_claimed_at_epoch' => null,
            'notification_next_attempt_at_epoch' => null,
        ])->where('notified = 0')->execute();
    }

    /**
     * Lease requests that haven't been announced yet. Delivery acknowledges the
     * lease; total failure releases it with bounded retries and backoff.
     *
     * @return array<int, \Dibi\Row>
     */
    public function claimUnnotified(?int $nowEpoch = null): array
    {
        $nowEpoch ??= time();
        // Allow one configured polling interval plus five minutes for sync
        // processing. The ordinary two-minute poll retains a ten-minute window.
        $since = $nowEpoch - max(self::FRESH_WINDOW_SECONDS, Config::interval('SEERR_POLL_INTERVAL', 120) + 300);
        $this->recoverExpiredNotificationClaims($nowEpoch);

        // A request discovered after an outage or a disabled channel is not
        // a new alert. Keep it in the mirror, but retire its notification.
        // Legacy rows have no unambiguous instant until sync sees them again.
        $this->db->update('seerr_requests', [
            'notified' => 1,
            'notification_claim_token' => null,
            'notification_claimed_at_epoch' => null,
            'notification_next_attempt_at_epoch' => null,
        ])->where('notified = 0')->where('(requested_at_epoch IS NULL OR requested_at_epoch < %i)', $since)->execute();

        $rows = $this->db->select('*')
            ->from('seerr_requests')
            ->where('notified = 0')
            ->where('requested_at_epoch >= %i', $since)
            ->where('notification_attempts < %i', 3)
            ->where('notification_claim_token IS NULL')
            ->where('(notification_next_attempt_at_epoch IS NULL OR notification_next_attempt_at_epoch <= %i)', $nowEpoch)
            ->orderBy('requested_at')->asc()
            ->fetchAll();

        if ($rows === []) {
            return [];
        }

        $claimed = [];
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(32));
            $this->db->query(
                'UPDATE `seerr_requests` SET `notification_attempts` = `notification_attempts` + 1, `notification_claim_token` = %s, `notification_claimed_at_epoch` = %i, `notification_next_attempt_at_epoch` = NULL WHERE `id` = %i AND `requested_at_epoch` >= %i AND `notified` = 0 AND `notification_attempts` < 3 AND `notification_claim_token` IS NULL AND (`notification_next_attempt_at_epoch` IS NULL OR `notification_next_attempt_at_epoch` <= %i)',
                $token,
                $nowEpoch,
                (int) $row['id'],
                $since,
                $nowEpoch,
            );

            if ($this->db->getAffectedRows() === 1) {
                $row['notification_claim_token'] = $token;
                $row['notification_attempts'] = (int) ($row['notification_attempts'] ?? 0) + 1;
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function acknowledgeNotificationClaim(int $id, string $token): void
    {
        $this->finishNotificationClaim($id, $token, true, time());
    }

    public function failNotificationClaim(int $id, string $token, ?int $nowEpoch = null): void
    {
        $this->finishNotificationClaim($id, $token, false, $nowEpoch ?? time());
    }

    private function finishNotificationClaim(int $id, string $token, bool $delivered, int $nowEpoch): void
    {
        $attempts = (int) $this->db->select('notification_attempts')->from('seerr_requests')
            ->where('id = %i', $id)->where('notification_claim_token = %s', $token)->fetchSingle();
        if ($attempts < 1) {
            return;
        }
        $terminal = $delivered || $attempts >= 3;
        $this->db->update('seerr_requests', [
            'notified' => $terminal ? 1 : 0,
            'notification_claim_token' => null,
            'notification_claimed_at_epoch' => null,
            'notification_next_attempt_at_epoch' => $terminal ? null : $nowEpoch + ($attempts === 1 ? 60 : 300),
        ])->where('id = %i', $id)->where('notification_claim_token = %s', $token)->execute();
    }

    private function recoverExpiredNotificationClaims(int $nowEpoch): void
    {
        $expired = $nowEpoch - 300;
        $this->db->query('UPDATE `seerr_requests` SET `notified` = 1, `notification_claim_token` = NULL, `notification_claimed_at_epoch` = NULL, `notification_next_attempt_at_epoch` = NULL WHERE `notified` = 0 AND `notification_attempts` >= 3 AND `notification_claimed_at_epoch` IS NOT NULL AND `notification_claimed_at_epoch` <= %i', $expired);
        $this->db->query('UPDATE `seerr_requests` SET `notification_claim_token` = NULL, `notification_claimed_at_epoch` = NULL, `notification_next_attempt_at_epoch` = %i WHERE `notified` = 0 AND `notification_attempts` < 3 AND `notification_claimed_at_epoch` IS NOT NULL AND `notification_claimed_at_epoch` <= %i', $nowEpoch, $expired);
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `seerr_requests` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `request_id` int NOT NULL,
                `media_type` varchar(16) NOT NULL,
                `tmdb_id` int NOT NULL,
                `title` varchar(255) NOT NULL,
                `year` varchar(4) DEFAULT NULL,
                `poster_path` varchar(255) DEFAULT NULL,
                `requested_by` varchar(128) DEFAULT NULL,
                `request_status` tinyint NOT NULL DEFAULT 0,
                `media_status` tinyint NOT NULL DEFAULT 0,
                `is_4k` tinyint(1) NOT NULL DEFAULT 0,
                `season_count` int DEFAULT NULL,
                `requested_at` datetime NOT NULL,
                `requested_at_epoch` bigint DEFAULT NULL,
                `notified` tinyint(1) NOT NULL DEFAULT 0,
                `notification_attempts` tinyint NOT NULL DEFAULT 0,
                `notification_claim_token` varchar(64) DEFAULT NULL,
                `notification_claimed_at_epoch` bigint DEFAULT NULL,
                `notification_next_attempt_at_epoch` bigint DEFAULT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_request_id` (`request_id`),
                KEY `idx_requested_at` (`requested_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `seerr_requests` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `request_id` INTEGER NOT NULL,
                `media_type` TEXT NOT NULL,
                `tmdb_id` INTEGER NOT NULL,
                `title` TEXT NOT NULL,
                `year` TEXT DEFAULT NULL,
                `poster_path` TEXT DEFAULT NULL,
                `requested_by` TEXT DEFAULT NULL,
                `request_status` INTEGER NOT NULL DEFAULT 0,
                `media_status` INTEGER NOT NULL DEFAULT 0,
                `is_4k` INTEGER NOT NULL DEFAULT 0,
                `season_count` INTEGER DEFAULT NULL,
                `requested_at` TEXT NOT NULL,
                `requested_at_epoch` INTEGER DEFAULT NULL,
                `notified` INTEGER NOT NULL DEFAULT 0,
                `notification_attempts` INTEGER NOT NULL DEFAULT 0,
                `notification_claim_token` TEXT DEFAULT NULL,
                `notification_claimed_at_epoch` INTEGER DEFAULT NULL,
                `notification_next_attempt_at_epoch` INTEGER DEFAULT NULL,
                `created_at` TEXT NOT NULL,
                UNIQUE (`request_id`)
            )'
        );
        $this->platform->createSqliteIndex('idx_requested_at', 'seerr_requests', ['requested_at']);

        $this->ensureColumn('requested_at_epoch', '`requested_at_epoch` bigint DEFAULT NULL AFTER `requested_at`', '`requested_at_epoch` INTEGER DEFAULT NULL');
        $this->ensureColumn('metadata_pending', '`metadata_pending` tinyint DEFAULT NULL', '`metadata_pending` INTEGER DEFAULT NULL');
        $this->ensureColumn('metadata_attempts', '`metadata_attempts` int NOT NULL DEFAULT 0', '`metadata_attempts` INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('metadata_retry_at_epoch', '`metadata_retry_at_epoch` bigint DEFAULT NULL', '`metadata_retry_at_epoch` INTEGER DEFAULT NULL');
        $this->ensureColumn('notification_attempts', '`notification_attempts` tinyint NOT NULL DEFAULT 0 AFTER `notified`', '`notification_attempts` INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('notification_claim_token', '`notification_claim_token` varchar(64) DEFAULT NULL AFTER `notification_attempts`', '`notification_claim_token` TEXT DEFAULT NULL');
        $this->ensureColumn('notification_claimed_at_epoch', '`notification_claimed_at_epoch` bigint DEFAULT NULL AFTER `notification_claim_token`', '`notification_claimed_at_epoch` INTEGER DEFAULT NULL');
        $this->ensureColumn('notification_next_attempt_at_epoch', '`notification_next_attempt_at_epoch` bigint DEFAULT NULL AFTER `notification_claimed_at_epoch`', '`notification_next_attempt_at_epoch` INTEGER DEFAULT NULL');

        self::$schemaConnections[$this->db] = true;
    }

    private function ensureColumn(string $column, string $mariaDbDefinition, string $sqliteDefinition): void
    {
        if (!$this->platform->columnExists('seerr_requests', $column)) {
            try {
                $this->platform->addColumn('seerr_requests', $mariaDbDefinition, $sqliteDefinition);
            } catch (\Dibi\Exception $e) {
                if (!$this->platform->columnExists('seerr_requests', $column)) {
                    throw $e;
                }
            }
        }
    }
}
