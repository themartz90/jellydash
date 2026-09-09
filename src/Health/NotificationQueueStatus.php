<?php

declare(strict_types=1);

namespace Mk\Framework\Health;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;

final class NotificationQueueStatus
{
    private \Dibi\Connection $db;

    public function __construct(?Database $database = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
    }

    /** @return array{pending_retries: int, in_flight: int, stalled: int} */
    public function snapshot(
        bool $includePlayback,
        bool $includeRequests,
        ?int $now = null,
    ): array {
        $now ??= time();
        $totals = ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0];

        if ($includePlayback) {
            new PlayHistoryRepository(new Database($this->db));
            $activeSince = (new \DateTimeImmutable('@' . ($now - 600)))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
            $this->addCounts($totals, 'play_history', $now, $activeSince);
        }
        if ($includeRequests) {
            new SeerrRequestRepository(new Database($this->db));
            $this->addCounts($totals, 'seerr_requests', $now);
        }

        return $totals;
    }

    /** @param array{pending_retries: int, in_flight: int, stalled: int} $totals */
    private function addCounts(array &$totals, string $table, int $now, ?string $activeSince = null): void
    {
        $selection = $this->db->select(
            'COALESCE(SUM(CASE WHEN notification_claim_token IS NULL THEN 1 ELSE 0 END), 0) AS pending_retries,
            COALESCE(SUM(CASE WHEN notification_claim_token IS NOT NULL AND notification_claimed_at_epoch > %i THEN 1 ELSE 0 END), 0) AS in_flight,
            COALESCE(SUM(CASE WHEN notification_claim_token IS NOT NULL AND notification_claimed_at_epoch <= %i THEN 1 ELSE 0 END), 0) AS stalled',
            $now - 300,
            $now - 300,
        )->from($table)
            ->where('notified = 0')
            ->where('notification_attempts > 0');
        if ($activeSince !== null) {
            $selection->where('started_at >= %s', $activeSince);
        }
        $row = $selection->fetch();

        foreach ($totals as $key => $value) {
            $totals[$key] = $value + (int) ($row[$key] ?? 0);
        }
    }
}
