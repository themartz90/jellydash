<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

final class PlayHistoryRepository implements LibraryHistorySource
{
    /**
     * Fields consumed by PlaybackStatisticsService. Keeping this projection
     * explicit avoids hydrating the larger History rows for every Statistics
     * range while retaining the confirmed library metadata used by its strips.
     *
     * @var array<int, string>
     */
    private const STATISTICS_COLUMNS = [
        'item_id',
        'item_type',
        'item_name',
        'series_name',
        'library',
        'library_resolved_at',
        'user_id',
        'user_name',
        'client',
        'play_method',
        'watched_sec',
        'watch_duration_sec',
        'source_video_codec',
        'transcode_reasons',
        'started_at',
    ];

    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    private ?MonitoringExclusions $monitoringExclusions;
    private ThemePlaybackExclusions $themePlaybackExclusions;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    // A gap longer than this between updates means the previous play ended and a
    // new viewing started (e.g. a re-watch on a client that keeps one session id
    // alive). We deliberately do NOT treat a backwards position jump as a new
    // play; that's just the viewer seeking within the same play.
    private const PLAY_GAP_SECONDS = 1800;

    // Imported Playback Reporting rows that start within this window of a live
    // poller row (same user + item) are treated as the same play.
    private const LIVE_OVERLAP_SECONDS = 300;
    private const MAX_SAMPLE_GAP_SECONDS = 120;

    /** @param (\Closure(array<string, mixed>): void)|null $beforeImportWrite */
    public function __construct(
        ?Database $database = null,
        ?MonitoringExclusions $monitoringExclusions = null,
        private ?\Closure $beforeImportWrite = null,
        ?ThemePlaybackExclusions $themePlaybackExclusions = null,
    ) {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->monitoringExclusions = $monitoringExclusions;
        $this->ensureSchema();
        $this->themePlaybackExclusions = $themePlaybackExclusions ?? new ThemePlaybackExclusions(
            $database,
            ThemePlaybackExclusions::serverKey((string) Config::get('JELLYFIN_URL', '')),
        );
    }

    public function themePlaybackExclusions(): ThemePlaybackExclusions
    {
        return $this->themePlaybackExclusions;
    }

    public function visibleHistorySql(string $historyAlias = 'play_history', bool $alsoPending = false): string
    {
        return $this->themePlaybackExclusions->visibilitySql($historyAlias, $alsoPending);
    }

    /**
     * @param array<int, array<string, mixed>> $streams
     */
    public function logActiveStreams(array $streams, ?\DateTimeImmutable $now = null, int $casAttempt = 0): void
    {
        $now ??= new \DateTimeImmutable('now');
        $nowSql = $now->format('Y-m-d H:i:s');

        foreach ($streams as $stream) {
            if ($this->exclusions()->excludes($this->nullableString($stream['user'] ?? null))) {
                continue;
            }
            $previousSampleEpoch = null;
            $sessionKey = (string) ($stream['id'] ?? '');
            $itemId = (string) ($stream['itemId'] ?? '');

            if ($sessionKey === '' || $itemId === '') {
                continue;
            }
            if ($this->themePlaybackExclusions->classification($itemId) === ThemePlaybackClassifier::THEME) {
                continue;
            }

            $existing = $this->db->select('id, watched_sec, watch_duration_sec, updated_at, updated_at_epoch, is_finished, last_sample_epoch, last_sample_position_sec, last_sample_paused, last_sample_rate')
                ->from('play_history')
                ->where('session_key = %s', $sessionKey)
                ->where('item_id = %s', $itemId)
                ->fetch();

            $position = max(0, (int) ($stream['watchedSec'] ?? 0));
            $runtimeSec = max(0, (int) ($stream['runtimeSec'] ?? 0));
            $paused = ($stream['isPaused'] ?? false) === true;
            $playbackRate = self::playbackRate($stream['playbackRate'] ?? 1.0);

            // Continuation of the existing row, or a brand-new play?
            $isNewPlay = false;
            if ($existing) {
                $previousSampleEpoch = $existing['last_sample_epoch'] === null
                    ? null
                    : (int) $existing['last_sample_epoch'];
                if ($previousSampleEpoch !== null && $now->getTimestamp() <= $previousSampleEpoch) {
                    continue;
                }
                $updatedEpoch = $existing['updated_at_epoch'] !== null
                    ? (int) $existing['updated_at_epoch']
                    : (new \DateTimeImmutable((string) $existing['updated_at']))->getTimestamp();
                $secondsSinceUpdate = $now->getTimestamp() - $updatedEpoch;

                // A finished row only becomes a new play when the position
                // jumped back well below what was already watched (an actual
                // restart). A finished item still playing out (credits) is a
                // continuation; treating it as new would reset the row and
                // re-fire its alert on every poll until the session ends.
                $restarted = (bool) $existing['is_finished']
                    && $position + 60 < (int) $existing['watched_sec'];

                $isNewPlay = $restarted || $secondsSinceUpdate > self::PLAY_GAP_SECONDS;
            }

            $previousWatchedSec = ($existing && !$isNewPlay) ? (int) $existing['watched_sec'] : 0;
            $watchedSec = max($position, $previousWatchedSec);
            $isFinished = self::isPlayFinished($watchedSec, $runtimeSec);
            $library = $this->nullableString($stream['library'] ?? null);
            $libraryResolved = ($stream['libraryResolved'] ?? false) === true;

            $data = [
                'user_id' => $this->nullableString($stream['userId'] ?? null),
                'user_name' => $this->nullableString($stream['user'] ?? null),
                'item_type' => (string) ($stream['itemType'] ?? ''),
                'series_name' => $this->nullableString($stream['seriesName'] ?? null),
                'item_name' => $this->nullableString($stream['itemName'] ?? null),
                'season_ep' => $this->nullableString($stream['seasonEp'] ?? null),
                'library' => $library,
                'library_resolved_at' => $libraryResolved ? $nowSql : null,
                'play_method' => (string) ($stream['playMethod'] ?? ''),
                'play_method_detail' => $this->nullableString($stream['methodLabel'] ?? null),
                'client' => $this->nullableString($stream['client'] ?? null),
                'device' => $this->nullableString($stream['device'] ?? null),
                'source_video_codec' => $this->nullableString($stream['sourceVideoCodec'] ?? null),
                'source_audio_codec' => $this->nullableString($stream['sourceAudioCodec'] ?? null),
                'source_container' => $this->nullableString($stream['sourceContainer'] ?? null),
                'target_video_codec' => $this->nullableString($stream['targetVideoCodec'] ?? null),
                'target_audio_codec' => $this->nullableString($stream['targetAudioCodec'] ?? null),
                'target_container' => $this->nullableString($stream['targetContainer'] ?? null),
                'is_video_direct' => isset($stream['isVideoDirect']) ? (filter_var($stream['isVideoDirect'], FILTER_VALIDATE_BOOL) ? 1 : 0) : null,
                'is_audio_direct' => isset($stream['isAudioDirect']) ? (filter_var($stream['isAudioDirect'], FILTER_VALIDATE_BOOL) ? 1 : 0) : null,
                'transcode_reasons' => $this->encodedReasons($stream['transcodeReasons'] ?? []),
                'watched_sec' => $watchedSec,
                'runtime_sec' => $runtimeSec,
                'updated_at' => $nowSql,
                'updated_at_epoch' => $now->getTimestamp(),
                'ended_at' => $isFinished ? $nowSql : null,
                'ended_at_epoch' => $isFinished ? $now->getTimestamp() : null,
                'library_resolved_at_epoch' => $libraryResolved ? $now->getTimestamp() : null,
                'is_finished' => $isFinished ? 1 : 0,
            ];

            if ($existing) {
                // A fresh play reuses the row (session_key + item_id is unique)
                // but resets the start time so the history shows the new viewing.
                // Resetting `notified` lets the alert fire again for the re-watch;
                // a plain continuation leaves it untouched so it never re-fires.
                if ($isNewPlay) {
                    $data['started_at'] = $nowSql;
                    $data['started_at_epoch'] = $now->getTimestamp();
                    $data['notified'] = 0;
                    $data['notification_attempts'] = 0;
                    $data['notification_claim_token'] = null;
                    $data['notification_claimed_at_epoch'] = null;
                    $data['notification_next_attempt_at_epoch'] = null;
                    $data['watch_duration_sec'] = 0;
                    $data['last_sample_epoch'] = $now->getTimestamp();
                    $data['last_sample_position_sec'] = $position;
                    $data['last_sample_paused'] = $paused ? 1 : 0;
                    $data['last_sample_rate'] = $playbackRate;
                } else {
                    if ($existing['watch_duration_sec'] !== null && $previousSampleEpoch !== null) {
                        $data['watch_duration_sec'] = (int) $existing['watch_duration_sec'] + self::sampledViewingSeconds(
                            $previousSampleEpoch,
                            (int) ($existing['last_sample_position_sec'] ?? 0),
                            (bool) ($existing['last_sample_paused'] ?? false),
                            $now->getTimestamp(),
                            $position,
                            $paused,
                            (float) ($existing['last_sample_rate'] ?? $playbackRate),
                        );
                    }
                    $data['last_sample_epoch'] = $now->getTimestamp();
                    $data['last_sample_position_sec'] = $position;
                    $data['last_sample_paused'] = $paused ? 1 : 0;
                    $data['last_sample_rate'] = $playbackRate;
                }
                if (!$libraryResolved) {
                    unset($data['library'], $data['library_resolved_at'], $data['library_resolved_at_epoch']);
                }

                $update = $this->db->update('play_history', $data)
                    ->where('id = %i', (int) $existing['id']);
                if ($previousSampleEpoch === null) {
                    $update->where('last_sample_epoch IS NULL');
                } else {
                    $update->where('last_sample_epoch = %i', $previousSampleEpoch);
                }
                $update->execute();
                if ($this->db->getAffectedRows() === 0 && $casAttempt < 2) {
                    $this->logActiveStreams([$stream], $now, $casAttempt + 1);
                }
                continue;
            }

            $data['session_key'] = $sessionKey;
            $data['item_id'] = $itemId;
            $data['started_at'] = $nowSql;
            $data['started_at_epoch'] = $now->getTimestamp();
            $data['notified'] = 0;
            $data['notification_attempts'] = 0;
            $data['notification_claim_token'] = null;
            $data['notification_claimed_at_epoch'] = null;
            $data['notification_next_attempt_at_epoch'] = null;
            $data['watch_duration_sec'] = 0;
            $data['last_sample_epoch'] = $now->getTimestamp();
            $data['last_sample_position_sec'] = $position;
            $data['last_sample_paused'] = $paused ? 1 : 0;
            $data['last_sample_rate'] = $playbackRate;

            try {
                $this->db->insert('play_history', $data)->execute();
            } catch (\Dibi\UniqueConstraintViolationException) {
                // Another writer (the poller and an open dashboard both call this)
                // inserted the same session+item first, so update that row instead.
                // The winning writer may already have claimed this row for a
                // notification. Preserve its flag when applying our fresher
                // playback details so the alert cannot become eligible again.
                // Re-read through the CAS path. A same-time sample becomes a
                // no-op, while a genuinely newer sample can add its interval.
                if ($casAttempt < 2) {
                    $this->logActiveStreams([$stream], $now, $casAttempt + 1);
                }
            }
        }
    }

    private static function playbackRate(mixed $rate): float
    {
        $rate = is_numeric($rate) ? (float) $rate : 1.0;

        return $rate >= 0.1 && $rate <= 16.0 ? $rate : 1.0;
    }

    private static function sampledViewingSeconds(
        int $previousEpoch,
        int $previousPosition,
        bool $previousPaused,
        int $epoch,
        int $position,
        bool $paused,
        float $previousRate,
    ): int {
        $elapsed = $epoch - $previousEpoch;
        $positionAdvance = $position - $previousPosition;
        if ($elapsed <= 0 || $elapsed > self::MAX_SAMPLE_GAP_SECONDS
            || $previousPaused || $paused || $positionAdvance <= 0) {
            return 0;
        }

        return min($elapsed, (int) ceil($positionAdvance / self::playbackRate($previousRate)));
    }

    /**
     * Atomically lease freshly-started plays that haven't been notified yet.
     * A successful delivery acknowledges the row. Total failure releases it
     * with bounded retries and backoff. Plays older than $withinSeconds are
     * retired silently so a poller that was down doesn't fire stale alerts.
     *
     * @param array<int, string> $ignoreUsers
     * @return array<int, \Dibi\Row>
     */
    public function claimUnnotifiedPlays(array $ignoreUsers, int $withinSeconds, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $nowEpoch = $now->getTimestamp();
        $since = $now->modify('-' . max(1, $withinSeconds) . ' seconds')->format('Y-m-d H:i:s');

        $this->recoverExpiredNotificationClaims($nowEpoch);

        // Retire anything too old to alert about so it neither fires late nor
        // lingers unnotified forever.
        $this->db->update('play_history', [
            'notified' => 1,
            'notification_claim_token' => null,
            'notification_claimed_at_epoch' => null,
            'notification_next_attempt_at_epoch' => null,
        ])
            ->where('notified = 0')
            ->where('started_at < %s', $since)
            ->execute();

        // Confirmed theme media can never become a playback alert. Retire any
        // old unnotified row once its classification becomes known.
        $this->db->update('play_history', [
            'notified' => 1,
            'notification_claim_token' => null,
            'notification_claimed_at_epoch' => null,
            'notification_next_attempt_at_epoch' => null,
        ])
            ->where('notified = 0')
            ->where('NOT (' . $this->visibleHistorySql() . ')')
            ->execute();

        $rows = $this->db->select('*')
            ->from('play_history')
            ->where('notified = 0')
            ->where('started_at >= %s', $since)
            ->where('notification_attempts < %i', 3)
            ->where('notification_claim_token IS NULL')
            ->where('(notification_next_attempt_at_epoch IS NULL OR notification_next_attempt_at_epoch <= %i)', $nowEpoch)
            ->where($this->visibleHistorySql())
            ->orderBy('started_at')->asc()
            ->fetchAll();

        if ($rows === []) {
            return [];
        }

        // Claim row by row with a conditional lease. If another writer already
        // leased the row, the update touches nothing and this worker skips it.
        $claimed = [];
        $ignore = array_map(
            static fn (string $u): string => mb_strtolower(trim($u)),
            [...$ignoreUsers, ...$this->exclusions()->names()]
        );
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(32));
            $this->db->query(
                'UPDATE `play_history` SET `notification_attempts` = `notification_attempts` + 1, `notification_claim_token` = %s, `notification_claimed_at_epoch` = %i, `notification_next_attempt_at_epoch` = NULL WHERE `id` = %i AND `started_at` = %s AND `notified` = 0 AND `notification_attempts` < 3 AND `notification_claim_token` IS NULL AND (`notification_next_attempt_at_epoch` IS NULL OR `notification_next_attempt_at_epoch` <= %i)',
                $token,
                $nowEpoch,
                (int) $row['id'],
                (string) $row['started_at'],
                $nowEpoch,
            );

            if ($this->db->getAffectedRows() === 1) {
                $row['notification_claim_token'] = $token;
                $row['notification_attempts'] = (int) ($row['notification_attempts'] ?? 0) + 1;
                $user = mb_strtolower(trim((string) ($row['user_name'] ?? '')));
                if ($user === '' || in_array($user, $ignore, true)) {
                    $this->acknowledgeNotificationClaim((int) $row['id'], $token);
                    continue;
                }
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
        $attempts = (int) $this->db->select('notification_attempts')->from('play_history')
            ->where('id = %i', $id)->where('notification_claim_token = %s', $token)->fetchSingle();
        if ($attempts < 1) {
            return;
        }
        $terminal = $delivered || $attempts >= 3;
        $this->db->update('play_history', [
            'notified' => $terminal ? 1 : 0,
            'notification_claim_token' => null,
            'notification_claimed_at_epoch' => null,
            'notification_next_attempt_at_epoch' => $terminal ? null : $nowEpoch + ($attempts === 1 ? 60 : 300),
        ])->where('id = %i', $id)->where('notification_claim_token = %s', $token)->execute();
    }

    private function recoverExpiredNotificationClaims(int $nowEpoch): void
    {
        $expired = $nowEpoch - 300;
        $this->db->query('UPDATE `play_history` SET `notified` = 1, `notification_claim_token` = NULL, `notification_claimed_at_epoch` = NULL, `notification_next_attempt_at_epoch` = NULL WHERE `notified` = 0 AND `notification_attempts` >= 3 AND `notification_claimed_at_epoch` IS NOT NULL AND `notification_claimed_at_epoch` <= %i', $expired);
        $this->db->query('UPDATE `play_history` SET `notification_claim_token` = NULL, `notification_claimed_at_epoch` = NULL, `notification_next_attempt_at_epoch` = %i WHERE `notified` = 0 AND `notification_attempts` < 3 AND `notification_claimed_at_epoch` IS NOT NULL AND `notification_claimed_at_epoch` <= %i', $nowEpoch, $expired);
    }

    public function watchTimeToday(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now');
        $start = $now->setTime(0, 0)->format('Y-m-d H:i:s');
        $end = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $selection = $this->db->select('COALESCE(SUM(COALESCE(watch_duration_sec, watched_sec)), 0)')
            ->from('play_history');
        $this->excludeConfiguredUsers($selection);
        $this->excludeThemePlayback($selection);

        return (int) $selection
            ->where('started_at BETWEEN %s AND %s', $start, $end)
            ->fetchSingle();
    }

    public function watchTimeTodayIsEstimated(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now');
        $start = $now->setTime(0, 0)->format('Y-m-d H:i:s');
        $end = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $selection = $this->db->select('COUNT(*)')->from('play_history');
        $this->excludeConfiguredUsers($selection);
        $this->excludeThemePlayback($selection);

        return (int) $selection
            ->where('started_at BETWEEN %s AND %s', $start, $end)
            ->where('watch_duration_sec IS NULL')
            ->fetchSingle() > 0;
    }

    /**
     * Confirmed libraries already stored for these exact active sessions.
     * The resolution flag matters when the real library is literally named
     * Movies or TV Shows, which is indistinguishable from a generic label.
     *
     * @param array<int, array<string, mixed>> $streams
     * @return array<int, string> Stream index => confirmed library.
     */
    public function resolvedLibrariesForStreams(
        array $streams,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable('now');
        $freshSince = $now->modify('-' . self::PLAY_GAP_SECONDS . ' seconds');
        $resolved = [];

        foreach ($streams as $index => $stream) {
            $sessionKey = (string) ($stream['id'] ?? '');
            $itemId = (string) ($stream['itemId'] ?? '');
            if ($sessionKey === '' || $itemId === '' || ($stream['isLive'] ?? false) === true) {
                continue;
            }

            $row = $this->db->select('library, library_resolved_at')
                ->from('play_history')
                ->where('session_key = %s', $sessionKey)
                ->where('item_id = %s', $itemId)
                ->fetch();
            if (!$row) {
                continue;
            }
            $library = trim((string) ($row['library'] ?? ''));
            $resolvedAtValue = trim((string) ($row['library_resolved_at'] ?? ''));
            if ($library === '' || $resolvedAtValue === '') {
                continue;
            }

            try {
                $resolvedAt = new \DateTimeImmutable($resolvedAtValue);
            } catch (\Exception) {
                continue;
            }
            if ($resolvedAt < $freshSince) {
                continue;
            }

            $resolved[$index] = $library;
        }

        return $resolved;
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    public function historyRows(HistoryFilters $filters, ?\DateTimeImmutable $now = null): array
    {
        $selection = $this->filteredSelection($filters, $now)
            ->orderBy('started_at')->desc()
            ->orderBy('id')->desc()
            ->limit($filters->limit)
            ->offset($filters->offset);

        return $selection->fetchAll();
    }

    /**
     * Stream every filtered row in the same stable order as the History page.
     * Pagination is cursor-based so large exports do not live in PHP memory.
     *
     * @return \Generator<int, \Dibi\Row>
     */
    public function historyExportRows(HistoryFilters $filters, ?\DateTimeImmutable $now = null): \Generator
    {
        $cursorStartedAt = null;
        $cursorId = 0;
        $batchSize = 500;

        do {
            $selection = $this->filteredSelection($filters, $now)
                ->orderBy('started_at')->desc()
                ->orderBy('id')->desc()
                ->limit($batchSize);

            if ($cursorStartedAt !== null) {
                $selection->where(
                    '(started_at < %s OR (started_at = %s AND id < %i))',
                    $cursorStartedAt,
                    $cursorStartedAt,
                    $cursorId,
                );
            }

            $rows = $selection->fetchAll();
            foreach ($rows as $row) {
                yield $row;
            }

            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            if ($last === null) {
                break;
            }

            $startedAt = $last['started_at'];
            $cursorStartedAt = $startedAt instanceof \DateTimeInterface
                ? $startedAt->format('Y-m-d H:i:s')
                : (string) $startedAt;
            $cursorId = (int) $last['id'];
        } while (count($rows) === $batchSize);
    }

    public function historyTotal(HistoryFilters $filters, ?\DateTimeImmutable $now = null): int
    {
        return (int) $this->filteredSelection($filters, $now, 'COUNT(*)')
            ->fetchSingle();
    }

    /** @return array{plays: int, unique_users: int, watch_sec: int, estimated_plays: int, transcodes: int} */
    public function historyAggregate(HistoryFilters $filters, ?\DateTimeImmutable $now = null): array
    {
        $exactTranscode = $this->platform->isSqlite()
            ? "play_method COLLATE BINARY = 'Transcode'"
            : "BINARY play_method = 'Transcode'";
        $uniqueUsers = $this->platform->isSqlite()
            ? "COUNT(DISTINCT COALESCE(user_name, '') COLLATE BINARY)"
            : "COUNT(DISTINCT BINARY COALESCE(user_name, ''))";
        $row = $this->filteredSelection(
            $filters,
            $now,
            "COUNT(*) AS plays,
                {$uniqueUsers} AS unique_users,
                COALESCE(SUM(COALESCE(watch_duration_sec, watched_sec)), 0) AS watch_sec,
                COALESCE(SUM(CASE WHEN watch_duration_sec IS NULL THEN 1 ELSE 0 END), 0) AS estimated_plays,
                COALESCE(SUM(CASE WHEN {$exactTranscode} THEN 1 ELSE 0 END), 0) AS transcodes",
        )->fetch();

        return [
            'plays' => (int) ($row['plays'] ?? 0),
            'unique_users' => (int) ($row['unique_users'] ?? 0),
            'watch_sec' => (int) ($row['watch_sec'] ?? 0),
            'estimated_plays' => (int) ($row['estimated_plays'] ?? 0),
            'transcodes' => (int) ($row['transcodes'] ?? 0),
        ];
    }

    public function totalRows(): int
    {
        $selection = $this->db->select('COUNT(*)')->from('play_history');
        $this->excludeConfiguredUsers($selection);
        $this->excludeThemePlayback($selection);

        return (int) $selection->fetchSingle();
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    public function statisticsRows(string $range, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');

        $since = StatisticsPeriod::currentStart($range, $now);

        return $this->statisticsRowsForPeriod($since, null);
    }

    /**
     * One summary row per item_id: play count, watch time, and the latest play.
     * Used by the Libraries overview so it does not load every play_history row.
     *
     * @return array<int, \Dibi\Row>
     */
    public function itemPlaySummaries(): array
    {
        $excluded = $this->excludedStoredUserNames();
        $conditions = [$this->visibleHistorySql('play_history')];
        if ($excluded !== []) {
            $conditions[] = $this->exactUserExclusionSql($excluded);
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);

        return $this->db->query(
            'SELECT item_id, library, plays, watch_sec, estimated_plays, started_at, series_name, item_name, season_ep, user_name
            FROM (
                SELECT item_id, library, started_at, series_name, item_name, season_ep, user_name,
                    COUNT(*) OVER (PARTITION BY item_id) AS plays,
                    SUM(COALESCE(watch_duration_sec, watched_sec)) OVER (PARTITION BY item_id) AS watch_sec,
                    SUM(CASE WHEN watch_duration_sec IS NULL THEN 1 ELSE 0 END) OVER (PARTITION BY item_id) AS estimated_plays,
                    ROW_NUMBER() OVER (PARTITION BY item_id ORDER BY started_at DESC, id DESC) AS rn
                FROM play_history' . $where . '
            ) AS ranked
            WHERE rn = 1'
        )->fetchAll();
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    public function statisticsRowsForPeriod(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $selection = $this->db->select(implode(', ', self::STATISTICS_COLUMNS))->from('play_history');
        $this->excludeConfiguredUsers($selection);
        $this->excludeThemePlayback($selection);

        if ($start !== null) {
            $selection->where('started_at >= %s', $start->format('Y-m-d H:i:s'));
        }

        if ($end !== null) {
            $selection->where('started_at < %s', $end->format('Y-m-d H:i:s'));
        }

        return $selection->orderBy('started_at')->asc()->fetchAll();
    }

    public static function isPlayFinished(int $watchedSec, int $runtimeSec): bool
    {
        return $runtimeSec > 0 && $watchedSec >= (int) floor($runtimeSec * 0.95);
    }

    /**
     * Insert historical plays (Playback Reporting import). Already-imported
     * rows are skipped via the session_key + item_id unique key. A re-import
     * can still fill runtime_sec when the stored value is 0, and replace a
     * type-based library label with the real Jellyfin library. Plays that
     * overlap a live poller row (same user, item, and start time) are skipped.
     * Imported plays must already have notified=1 so they never fire a
     * "started watching" alert.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{inserted: int, skipped: int, repaired: int}
     */
    public function importHistoricalPlays(array $rows, bool $dryRun = false, ?callable $onProgress = null): array
    {
        if ($dryRun) {
            return $this->importHistoricalPlaysBatch($rows, true, $onProgress);
        }

        $this->db->begin();
        try {
            $result = $this->importHistoricalPlaysBatch($rows, false, null);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->reportImportProgress($onProgress, count($rows), count($rows), $result['inserted'], $result['skipped']);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{inserted: int, skipped: int, repaired: int}
     */
    private function importHistoricalPlaysBatch(array $rows, bool $dryRun, ?callable $onProgress): array
    {
        $inserted = 0;
        $skipped = 0;
        $repaired = 0;
        $livePlays = $this->livePlaysNearImport($rows);
        $total = count($rows);
        $processed = 0;

        foreach ($rows as $row) {
            $sessionKey = (string) ($row['session_key'] ?? '');
            $itemId = (string) ($row['item_id'] ?? '');
            if ($this->exclusions()->excludes($this->nullableString($row['user_name'] ?? null))) {
                $skipped++;
            } elseif ($sessionKey === '' || $itemId === '') {
                $skipped++;
            } elseif ($this->themePlaybackExclusions->classification($itemId) === ThemePlaybackClassifier::THEME) {
                $skipped++;
            } elseif ($this->overlapsLivePlay($row, $livePlays)) {
                $skipped++;
            } elseif ($dryRun) {
                $exists = $this->db->select('id')
                    ->from('play_history')
                    ->where('session_key = %s', $sessionKey)
                    ->where('item_id = %s', $itemId)
                    ->fetch();
                if ($exists) {
                    $skipped++;
                } else {
                    $inserted++;
                }
            } else {
                if ($this->beforeImportWrite !== null) {
                    ($this->beforeImportWrite)($row);
                }
                try {
                    $this->db->insert('play_history', $row)->execute();
                    $inserted++;
                } catch (\Dibi\UniqueConstraintViolationException) {
                    if ($this->repairImportedRow($sessionKey, $itemId, $row)) {
                        $repaired++;
                    }
                    $skipped++;
                }
            }

            $processed++;
            $this->reportImportProgress($onProgress, $processed, $total, $inserted, $skipped);
        }

        if ($total === 0) {
            $this->reportImportProgress($onProgress, 0, 0, 0, 0);
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'repaired' => $repaired];
    }

    /**
     * Restore native Jellydash History rows using their exact CSV identity.
     * Existing session_key + item_id pairs are left unchanged. Unlike Playback
     * Reporting imports, native backups are not matched approximately to live
     * plays and never repair stored rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     * @return array{inserted: int, skipped: int}
     */
    public function importNativeHistoricalPlays(
        array $rows,
        bool $dryRun = false,
        ?callable $onProgress = null,
    ): array {
        $inserted = 0;
        $skipped = 0;
        $total = count($rows);
        $processed = 0;

        foreach ($rows as $row) {
            $sessionKey = (string) ($row['session_key'] ?? '');
            $itemId = (string) ($row['item_id'] ?? '');
            if ($this->exclusions()->excludes($this->nullableString($row['user_name'] ?? null))) {
                $skipped++;
            } elseif ($sessionKey === '' || $itemId === '') {
                $skipped++;
            } elseif ($this->themePlaybackExclusions->classification($itemId) === ThemePlaybackClassifier::THEME) {
                $skipped++;
            } elseif ($dryRun) {
                $exists = $this->db->select('id')
                    ->from('play_history')
                    ->where('session_key = %s', $sessionKey)
                    ->where('item_id = %s', $itemId)
                    ->fetch();
                if ($exists) {
                    $skipped++;
                } else {
                    $inserted++;
                }
            } else {
                try {
                    $this->db->insert('play_history', $row)->execute();
                    $inserted++;
                } catch (\Dibi\UniqueConstraintViolationException) {
                    $skipped++;
                }
            }

            $processed++;
            $this->reportImportProgress($onProgress, $processed, $total, $inserted, $skipped);
        }

        if ($total === 0) {
            $this->reportImportProgress($onProgress, 0, 0, 0, 0);
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * @param callable(array{phase: string, processed: int, total: int, inserted: int, skipped: int}): void|null $onProgress
     */
    private function reportImportProgress(?callable $onProgress, int $processed, int $total, int $inserted, int $skipped): void
    {
        if ($onProgress === null) {
            return;
        }

        if ($processed !== 1 && $processed !== $total && $processed % 10 !== 0) {
            return;
        }

        $onProgress([
            'phase' => 'importing',
            'processed' => $processed,
            'total' => $total,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Fill runtime when it was stored as 0, and replace a type-based library
     * label (Movies / TV Shows / …) with the real Jellyfin library name.
     *
     * @param array<string, mixed> $row
     */
    private function repairImportedRow(string $sessionKey, string $itemId, array $row): bool
    {
        $existing = $this->db->select('id, runtime_sec, watch_duration_sec, library, started_at')
            ->from('play_history')
            ->where('session_key = %s', $sessionKey)
            ->where('item_id = %s', $itemId)
            ->fetch();

        if (!$existing) {
            return false;
        }

        $data = [];
        if ($existing['watch_duration_sec'] === null && isset($row['watch_duration_sec'])) {
            $data['watch_duration_sec'] = max(0, (int) $row['watch_duration_sec']);
        }
        $incomingRuntime = max(0, (int) ($row['runtime_sec'] ?? 0));
        if ($incomingRuntime > 0 && (int) $existing['runtime_sec'] <= 0) {
            $watchedSec = max(0, (int) ($row['watched_sec'] ?? 0));
            $finished = self::isPlayFinished($watchedSec, $incomingRuntime);
            $endedAt = $this->endedAtFromStart((string) ($existing['started_at'] ?? ''), $watchedSec);
            $data['runtime_sec'] = $incomingRuntime;
            $data['watched_sec'] = $watchedSec;
            $data['is_finished'] = $finished ? 1 : 0;
            $data['updated_at'] = $endedAt ?? ($row['updated_at'] ?? null);
            $data['ended_at'] = $finished ? $endedAt : null;
            $data['updated_at_epoch'] = null;
            $data['ended_at_epoch'] = null;
        }

        $incomingLibrary = $this->nullableString($row['library'] ?? null);
        $storedLibrary = trim((string) ($existing['library'] ?? ''));
        if ($incomingLibrary !== null && $this->shouldReplaceLibrary($storedLibrary, $incomingLibrary)) {
            $data['library'] = $incomingLibrary;
        }

        if ($data === []) {
            return false;
        }

        $this->db->update('play_history', $data)
            ->where('id = %i', (int) $existing['id'])
            ->execute();

        return true;
    }

    private function shouldReplaceLibrary(string $stored, string $incoming): bool
    {
        if ($incoming === '' || strcasecmp($stored, $incoming) === 0) {
            return false;
        }

        if ($stored === '') {
            return true;
        }

        return in_array(mb_strtolower($stored), ['movies', 'tv shows', 'music', 'videos', 'live tv'], true);
    }

    private function endedAtFromStart(string $startedAt, int $watchedSec): ?string
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
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, list<int>>
     */
    private function livePlaysNearImport(array $rows): array
    {
        $times = [];
        foreach ($rows as $row) {
            $startedAt = (string) ($row['started_at'] ?? '');
            if ($startedAt === '') {
                continue;
            }
            try {
                $times[] = (new \DateTimeImmutable($startedAt))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
        }

        if ($times === []) {
            return [];
        }

        $min = (new \DateTimeImmutable('@' . (min($times) - self::LIVE_OVERLAP_SECONDS)))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
        $max = (new \DateTimeImmutable('@' . (max($times) + self::LIVE_OVERLAP_SECONDS)))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');

        $liveRows = $this->db->select('user_id, item_id, started_at')
            ->from('play_history')
            ->where('started_at >= %s', $min)
            ->where('started_at <= %s', $max)
            ->where('session_key NOT LIKE %s', PlaybackReportingParser::SESSION_PREFIX . '%')
            ->fetchAll();

        $plays = [];
        foreach ($liveRows as $live) {
            try {
                $startedTs = (new \DateTimeImmutable((string) $live['started_at']))->getTimestamp();
            } catch (\Exception) {
                continue;
            }

            $key = $this->livePlayKey(
                (string) ($live['user_id'] ?? ''),
                (string) ($live['item_id'] ?? ''),
            );
            if ($key === '') {
                continue;
            }

            $plays[$key][] = $startedTs;
        }

        return $plays;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, list<int>> $livePlays
     */
    private function overlapsLivePlay(array $row, array $livePlays): bool
    {
        $key = $this->livePlayKey((string) ($row['user_id'] ?? ''), (string) ($row['item_id'] ?? ''));
        if ($key === '' || !isset($livePlays[$key])) {
            return false;
        }

        try {
            $startedTs = (new \DateTimeImmutable((string) ($row['started_at'] ?? '')))->getTimestamp();
        } catch (\Exception) {
            return false;
        }

        foreach ($livePlays[$key] as $liveTs) {
            if (abs($liveTs - $startedTs) <= self::LIVE_OVERLAP_SECONDS) {
                return true;
            }
        }

        return false;
    }

    private function livePlayKey(string $userId, string $itemId): string
    {
        $userKey = $this->normalizedUserKey($userId);
        $itemKey = $this->normalizedUserKey($itemId);
        if ($userKey === '' || $itemKey === '') {
            return '';
        }

        return $userKey . "\0" . $itemKey;
    }

    private function normalizedUserKey(string $userId): string
    {
        return strtolower(str_replace('-', '', trim($userId)));
    }

    /**
     * @return array<int, string>
     */
    public function users(bool $includeExcluded = false): array
    {
        $pairs = $this->db->select($this->platform->isSqlite()
            ? 'DISTINCT user_name COLLATE BINARY AS user_name'
            : 'DISTINCT BINARY user_name AS user_name')
            ->from('play_history')
            ->where('user_name IS NOT NULL')
            ->orderBy('user_name');
        $this->excludeThemePlayback($pairs);
        $pairs = $pairs->fetchPairs(null, 'user_name');

        $users = array_values(array_map('strval', $pairs));

        return $includeExcluded
            ? $users
            : array_values(array_filter($users, fn (string $name): bool => !$this->exclusions()->excludes($name)));
    }

    /**
     * @return array<int, string>
     */
    public function libraries(): array
    {
        $pairs = $this->db->select($this->platform->isSqlite()
            ? 'DISTINCT library COLLATE BINARY AS library'
            : 'DISTINCT BINARY library AS library')
            ->from('play_history')
            ->where('library IS NOT NULL')
            ->where('library <> %s', '')
            ->orderBy('library');
        $this->excludeConfiguredUsers($pairs);
        $this->excludeThemePlayback($pairs);
        $values = $pairs->fetchPairs(null, 'library');

        return array_values(array_map('strval', $values));
    }

    /**
     * Client names use the same raw, case-sensitive grouping as Statistics.
     * Missing names and a literal "Unknown client" share one visible option.
     *
     * @return array<int, string>
     */
    public function clients(): array
    {
        $selection = $this->db->select($this->platform->isSqlite()
            ? 'DISTINCT client COLLATE BINARY AS client'
            : 'DISTINCT BINARY client AS client')
            ->from('play_history');
        $this->excludeConfiguredUsers($selection);
        $this->excludeThemePlayback($selection);

        $clients = [];
        foreach ($selection->fetchAll() as $row) {
            $name = (string) ($row['client'] ?? '');
            $clients[$name !== '' ? $name : 'Unknown client'] = true;
        }
        ksort($clients, SORT_STRING);

        return array_keys($clients);
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `play_history` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `session_key` varchar(128) NOT NULL,
                `user_id` varchar(64) DEFAULT NULL,
                `user_name` varchar(128) DEFAULT NULL,
                `item_id` varchar(64) NOT NULL,
                `item_type` varchar(16) NOT NULL,
                `series_name` varchar(255) DEFAULT NULL,
                `item_name` varchar(255) DEFAULT NULL,
                `season_ep` varchar(32) DEFAULT NULL,
                `library` varchar(64) DEFAULT NULL,
                `library_resolved_at` datetime DEFAULT NULL,
                `play_method` varchar(32) NOT NULL,
                `play_method_detail` varchar(64) DEFAULT NULL,
                `client` varchar(64) DEFAULT NULL,
                `device` varchar(64) DEFAULT NULL,
                `source_video_codec` varchar(64) DEFAULT NULL,
                `source_audio_codec` varchar(64) DEFAULT NULL,
                `source_container` varchar(64) DEFAULT NULL,
                `target_video_codec` varchar(64) DEFAULT NULL,
                `target_audio_codec` varchar(64) DEFAULT NULL,
                `target_container` varchar(64) DEFAULT NULL,
                `is_video_direct` tinyint(1) DEFAULT NULL,
                `is_audio_direct` tinyint(1) DEFAULT NULL,
                `transcode_reasons` text DEFAULT NULL,
                `watched_sec` int NOT NULL DEFAULT 0,
                `watch_duration_sec` int DEFAULT NULL,
                `last_sample_epoch` bigint DEFAULT NULL,
                `last_sample_position_sec` int DEFAULT NULL,
                `last_sample_paused` tinyint(1) DEFAULT NULL,
                `last_sample_rate` decimal(6,3) DEFAULT NULL,
                `runtime_sec` int NOT NULL DEFAULT 0,
                `started_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `ended_at` datetime DEFAULT NULL,
                `is_finished` tinyint(1) NOT NULL DEFAULT 0,
                `notified` tinyint(1) NOT NULL DEFAULT 0,
                `notification_attempts` tinyint NOT NULL DEFAULT 0,
                `notification_claim_token` varchar(64) DEFAULT NULL,
                `notification_claimed_at_epoch` bigint DEFAULT NULL,
                `notification_next_attempt_at_epoch` bigint DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_session_item` (`session_key`, `item_id`),
                KEY `idx_started_at` (`started_at`),
                KEY `idx_user_name` (`user_name`),
                KEY `idx_library` (`library`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `play_history` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `session_key` TEXT NOT NULL,
                `user_id` TEXT DEFAULT NULL,
                `user_name` TEXT DEFAULT NULL,
                `item_id` TEXT NOT NULL,
                `item_type` TEXT NOT NULL,
                `series_name` TEXT DEFAULT NULL,
                `item_name` TEXT DEFAULT NULL,
                `season_ep` TEXT DEFAULT NULL,
                `library` TEXT DEFAULT NULL,
                `library_resolved_at` TEXT DEFAULT NULL,
                `play_method` TEXT NOT NULL,
                `play_method_detail` TEXT DEFAULT NULL,
                `client` TEXT DEFAULT NULL,
                `device` TEXT DEFAULT NULL,
                `source_video_codec` TEXT DEFAULT NULL,
                `source_audio_codec` TEXT DEFAULT NULL,
                `source_container` TEXT DEFAULT NULL,
                `target_video_codec` TEXT DEFAULT NULL,
                `target_audio_codec` TEXT DEFAULT NULL,
                `target_container` TEXT DEFAULT NULL,
                `is_video_direct` INTEGER DEFAULT NULL,
                `is_audio_direct` INTEGER DEFAULT NULL,
                `transcode_reasons` TEXT DEFAULT NULL,
                `watched_sec` INTEGER NOT NULL DEFAULT 0,
                `watch_duration_sec` INTEGER DEFAULT NULL,
                `last_sample_epoch` INTEGER DEFAULT NULL,
                `last_sample_position_sec` INTEGER DEFAULT NULL,
                `last_sample_paused` INTEGER DEFAULT NULL,
                `last_sample_rate` REAL DEFAULT NULL,
                `runtime_sec` INTEGER NOT NULL DEFAULT 0,
                `started_at` TEXT NOT NULL,
                `updated_at` TEXT NOT NULL,
                `ended_at` TEXT DEFAULT NULL,
                `is_finished` INTEGER NOT NULL DEFAULT 0,
                `notified` INTEGER NOT NULL DEFAULT 0,
                `notification_attempts` INTEGER NOT NULL DEFAULT 0,
                `notification_claim_token` TEXT DEFAULT NULL,
                `notification_claimed_at_epoch` INTEGER DEFAULT NULL,
                `notification_next_attempt_at_epoch` INTEGER DEFAULT NULL,
                UNIQUE (`session_key`, `item_id`)
            )'
        );

        $this->platform->createSqliteIndex('idx_started_at', 'play_history', ['started_at']);
        $this->platform->createSqliteIndex('idx_user_name', 'play_history', ['user_name']);
        $this->platform->createSqliteIndex('idx_library', 'play_history', ['library']);

        $this->ensureColumn('library_resolved_at', '`library_resolved_at` datetime DEFAULT NULL AFTER `library`', '`library_resolved_at` TEXT DEFAULT NULL');
        $this->ensureColumn('play_method_detail', '`play_method_detail` varchar(64) DEFAULT NULL AFTER `play_method`', '`play_method_detail` TEXT DEFAULT NULL');
        $this->ensureColumn('source_video_codec', '`source_video_codec` varchar(64) DEFAULT NULL AFTER `device`', '`source_video_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('source_audio_codec', '`source_audio_codec` varchar(64) DEFAULT NULL AFTER `source_video_codec`', '`source_audio_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('source_container', '`source_container` varchar(64) DEFAULT NULL AFTER `source_audio_codec`', '`source_container` TEXT DEFAULT NULL');
        $this->ensureColumn('target_video_codec', '`target_video_codec` varchar(64) DEFAULT NULL AFTER `source_container`', '`target_video_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('target_audio_codec', '`target_audio_codec` varchar(64) DEFAULT NULL AFTER `target_video_codec`', '`target_audio_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('target_container', '`target_container` varchar(64) DEFAULT NULL AFTER `target_audio_codec`', '`target_container` TEXT DEFAULT NULL');
        $this->ensureColumn('is_video_direct', '`is_video_direct` tinyint(1) DEFAULT NULL AFTER `target_container`', '`is_video_direct` INTEGER DEFAULT NULL');
        $this->ensureColumn('is_audio_direct', '`is_audio_direct` tinyint(1) DEFAULT NULL AFTER `is_video_direct`', '`is_audio_direct` INTEGER DEFAULT NULL');
        $this->ensureColumn('transcode_reasons', '`transcode_reasons` text DEFAULT NULL AFTER `is_audio_direct`', '`transcode_reasons` TEXT DEFAULT NULL');
        $this->ensureColumn('watch_duration_sec', '`watch_duration_sec` int DEFAULT NULL', '`watch_duration_sec` INTEGER DEFAULT NULL');
        $this->ensureColumn('last_sample_epoch', '`last_sample_epoch` bigint DEFAULT NULL', '`last_sample_epoch` INTEGER DEFAULT NULL');
        $this->ensureColumn('last_sample_position_sec', '`last_sample_position_sec` int DEFAULT NULL', '`last_sample_position_sec` INTEGER DEFAULT NULL');
        $this->ensureColumn('last_sample_paused', '`last_sample_paused` tinyint(1) DEFAULT NULL', '`last_sample_paused` INTEGER DEFAULT NULL');
        $this->ensureColumn('last_sample_rate', '`last_sample_rate` decimal(6,3) DEFAULT NULL', '`last_sample_rate` REAL DEFAULT NULL');
        foreach (['started_at', 'updated_at', 'ended_at', 'library_resolved_at'] as $timestamp) {
            $column = $timestamp . '_epoch';
            $this->ensureColumn($column, '`' . $column . '` bigint DEFAULT NULL', '`' . $column . '` INTEGER DEFAULT NULL');
        }

        // Playback-notification flag. On an existing install, backfill every row
        // to "already notified" so adding the column never fires a burst of
        // alerts for historical plays.
        if (!$this->platform->columnExists('play_history', 'notified')) {
            $this->platform->addColumn(
                'play_history',
                '`notified` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_finished`',
                '`notified` INTEGER NOT NULL DEFAULT 0',
            );
            $this->db->query('UPDATE `play_history` SET `notified` = 1');
        }
        $this->ensureColumn('notification_attempts', '`notification_attempts` tinyint NOT NULL DEFAULT 0 AFTER `notified`', '`notification_attempts` INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('notification_claim_token', '`notification_claim_token` varchar(64) DEFAULT NULL AFTER `notification_attempts`', '`notification_claim_token` TEXT DEFAULT NULL');
        $this->ensureColumn('notification_claimed_at_epoch', '`notification_claimed_at_epoch` bigint DEFAULT NULL AFTER `notification_claim_token`', '`notification_claimed_at_epoch` INTEGER DEFAULT NULL');
        $this->ensureColumn('notification_next_attempt_at_epoch', '`notification_next_attempt_at_epoch` bigint DEFAULT NULL AFTER `notification_claimed_at_epoch`', '`notification_next_attempt_at_epoch` INTEGER DEFAULT NULL');

        self::$schemaConnections[$this->db] = true;
    }

    private function ensureColumn(string $column, string $mariaDbDefinition, string $sqliteDefinition): void
    {
        if (!$this->platform->columnExists('play_history', $column)) {
            try {
                $this->platform->addColumn('play_history', $mariaDbDefinition, $sqliteDefinition);
            } catch (\Dibi\Exception $e) {
                if (!$this->platform->columnExists('play_history', $column)) {
                    throw $e;
                }
            }
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function encodedReasons(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $reasons = array_values(array_filter(array_map('strval', $value)));

        return $reasons === [] ? null : json_encode($reasons, JSON_THROW_ON_ERROR);
    }

    private function filteredSelection(HistoryFilters $filters, ?\DateTimeImmutable $now, string $columns = '*'): \Dibi\Fluent
    {
        $selection = $this->db->select($columns)->from('play_history');
        $this->excludeConfiguredUsers($selection);
        $this->excludeThemePlayback($selection);

        if ($filters->hasExactPeriod() && $filters->start !== null && $filters->end !== null) {
            $selection->where('started_at >= %s', $filters->start->format('Y-m-d H:i:s'));
            $selection->where('started_at < %s', $filters->end->format('Y-m-d H:i:s'));
        } elseif (($rangeDays = $filters->rangeDays()) !== null) {
            $now ??= new \DateTimeImmutable('now');
            $since = $now->modify('-' . $rangeDays . ' days')->format('Y-m-d H:i:s');
            $selection->where('started_at >= %s', $since);
        }

        if ($filters->user !== '') {
            $selection->where($this->platform->isSqlite()
                ? 'user_name COLLATE BINARY = %s'
                : 'BINARY user_name = %s', $filters->user);
        }

        if ($filters->library !== '') {
            $selection->where($this->platform->isSqlite()
                ? 'library COLLATE BINARY = %s'
                : 'BINARY library = %s', $filters->library);
        }

        if ($filters->client !== '') {
            if ($filters->client === 'Unknown client') {
                $selection->where($this->platform->isSqlite()
                    ? '(client IS NULL OR client COLLATE BINARY = %s OR client COLLATE BINARY = %s)'
                    : '(client IS NULL OR BINARY client = %s OR BINARY client = %s)', '', 'Unknown client');
            } else {
                $selection->where($this->platform->isSqlite()
                    ? 'client COLLATE BINARY = %s'
                    : 'BINARY client = %s', $filters->client);
            }
        }

        if ($filters->method !== '') {
            $this->applyMethodFilter($selection, $filters->method);
        }

        if ($filters->search !== '') {
            $like = '%' . $filters->search . '%';
            $selection->where(
                '(series_name LIKE %s OR item_name LIKE %s OR user_name LIKE %s OR client LIKE %s OR device LIKE %s)',
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        return $selection;
    }

    private function applyMethodFilter(\Dibi\Fluent $selection, string $method): void
    {
        $exact = fn (string $value): string => $this->db->translate(
            $this->platform->isSqlite()
                ? 'play_method COLLATE BINARY = %s'
                : 'BINARY play_method = %s',
            $value,
        );
        $notExact = fn (string $value): string => $this->db->translate(
            $this->platform->isSqlite()
                ? 'play_method COLLATE BINARY <> %s'
                : 'BINARY play_method <> %s',
            $value,
        );

        if ($method === 'transcode') {
            $selection->where($exact('Transcode'));
        } elseif ($method === 'direct-stream') {
            $selection->where($exact('DirectStream'));
        } elseif ($method === 'direct') {
            $selection->where('(play_method IS NULL OR ' . $notExact('Transcode') . ')');
        } elseif ($method === 'direct-play') {
            $selection->where('(play_method IS NULL OR ('
                . $notExact('Transcode') . ' AND ' . $notExact('DirectStream') . '))');
        }
    }

    private function exclusions(): MonitoringExclusions
    {
        return $this->monitoringExclusions ??= new MonitoringExclusions();
    }

    /** @return list<string> */
    private function excludedStoredUserNames(): array
    {
        if ($this->exclusions()->names() === []) {
            return [];
        }
        $names = $this->db->select($this->platform->isSqlite()
            ? 'DISTINCT user_name COLLATE BINARY AS user_name'
            : 'DISTINCT BINARY user_name AS user_name')->from('play_history')
            ->where('user_name IS NOT NULL')->fetchPairs(null, 'user_name');

        return array_values(array_filter(
            array_map('strval', $names),
            fn (string $name): bool => $this->exclusions()->excludes($name),
        ));
    }

    private function excludeConfiguredUsers(\Dibi\Fluent $selection): void
    {
        foreach ($this->excludedStoredUserNames() as $name) {
            $selection->where($this->platform->isSqlite()
                ? '(user_name IS NULL OR user_name COLLATE BINARY <> %s)'
                : '(user_name IS NULL OR BINARY user_name <> %s)', $name);
        }
    }

    private function excludeThemePlayback(\Dibi\Fluent $selection, bool $alsoPending = false): void
    {
        $selection->where($this->visibleHistorySql('play_history', $alsoPending));
    }

    /** @param list<string> $names */
    private function exactUserExclusionSql(array $names): string
    {
        $parts = [];
        foreach ($names as $name) {
            $expression = $this->platform->isSqlite()
                ? '(user_name IS NULL OR user_name COLLATE BINARY <> %s)'
                : '(user_name IS NULL OR BINARY user_name <> %s)';
            $parts[] = $this->db->translate($expression, $name);
        }

        return implode(' AND ', $parts);
    }
}
