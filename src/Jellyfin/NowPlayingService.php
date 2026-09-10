<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Log;

final class NowPlayingService
{
    public function __construct(
        private ?JellyfinClient $client = null,
        private ?JellyfinSessionMapper $mapper = null,
        private ?PlayHistoryRepository $history = null,
        private ?LiveLibraryResolver $libraryResolver = null,
        private ?MonitoringExclusions $exclusions = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $client = $this->client ?? new JellyfinClient();
        $mapper = $this->mapper ?? new JellyfinSessionMapper($client->baseUrl());
        $mapped = $mapper->map(($this->exclusions ?? new MonitoringExclusions())->filterSessions($client->sessions()));
        /** @var array<int, array<string, mixed>> $streams */
        $streams = $mapped['streams'];
        $cycle = [
            'streams' => $streams,
            'metadata_available' => false,
            'recording_available' => false,
            'watch_today' => null,
            'watch_today_available' => false,
            'watch_today_estimated' => true,
            'watch_today_estimate_available' => false,
        ];

        try {
            $history = $this->history ?? new PlayHistoryRepository();
            $cycle = $this->historyCycle(
                $streams,
                fn (array $activeStreams): array => $this->resolveLibraries($activeStreams, $client, $history),
                $history->logActiveStreams(...),
                $history->watchTimeToday(...),
                $history->watchTimeTodayIsEstimated(...),
            );
        } catch (\Throwable $e) {
            Log::logException($e);
        }
        $streams = $cycle['streams'];

        return [
            'streams' => $streams,
            'hidden_count' => $mapped['hidden_count'],
            'hidden_sources' => $mapped['hidden_sources'],
            'stats' => $this->stats(
                $streams,
                $cycle['watch_today'],
                $cycle['watch_today_estimated'],
                $cycle['watch_today_available'],
                $cycle['watch_today_estimate_available'],
                $cycle['recording_available'],
                $cycle['metadata_available'],
            ),
            'refreshed_at' => gmdate('c'),
        ];
    }

    /**
     * Fetch the current sessions and persist any active plays, without building
     * the full display payload. Used by the background poller (bin/console.php
     * history:poll) so history is recorded even when no one has the dashboard
     * open. Returns the number of active streams seen.
     */
    public function recordActivePlays(): int
    {
        $client = $this->client ?? new JellyfinClient();
        $mapper = $this->mapper ?? new JellyfinSessionMapper($client->baseUrl());
        /** @var array<int, array<string, mixed>> $streams */
        $streams = $mapper->map(($this->exclusions ?? new MonitoringExclusions())->filterSessions($client->sessions()))['streams'];

        $history = $this->history ?? new PlayHistoryRepository();
        $streams = $this->resolveLibraries($streams, $client, $history);

        $history->logActiveStreams($streams);

        return count($streams);
    }

    /**
     * Resolve each new active session once, then use its confirmed History row
     * as the lightweight cache for later five-second dashboard refreshes.
     *
     * @param array<int, array<string, mixed>> $streams
     * @return array<int, array<string, mixed>>
     */
    private function resolveLibraries(
        array $streams,
        JellyfinClient $client,
        PlayHistoryRepository $history,
    ): array {
        if ($streams === []) {
            return [];
        }

        $known = $history->resolvedLibrariesForStreams($streams);
        $resolver = $this->libraryResolver ?? new LiveLibraryResolver(
            static fn (array $ids): array => $client->itemImportMeta($ids),
        );

        return $resolver->resolve($streams, $known);
    }

    /**
     * Keep optional metadata, recording, and summary reads independent so one
     * failure cannot turn a different operation into a false success value.
     *
     * @param array<int, array<string, mixed>> $streams
     * @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $resolve
     * @param callable(array<int, array<string, mixed>>): void $record
     * @param callable(): int $readWatchTime
     * @param callable(): bool $readEstimated
     * @return array{
     *     streams: array<int, array<string, mixed>>,
     *     metadata_available: bool,
     *     recording_available: bool,
     *     watch_today: int|null,
     *     watch_today_available: bool,
     *     watch_today_estimated: bool,
     *     watch_today_estimate_available: bool
     * }
     */
    private function historyCycle(
        array $streams,
        callable $resolve,
        callable $record,
        callable $readWatchTime,
        callable $readEstimated,
    ): array {
        $metadataAvailable = true;
        try {
            $streams = $resolve($streams);
        } catch (\Throwable $e) {
            $metadataAvailable = false;
            Log::logException($e);
        }

        $recordingAvailable = true;
        try {
            $record($streams);
        } catch (\Throwable $e) {
            $recordingAvailable = false;
            Log::logException($e);
        }

        $watchToday = null;
        $watchTodayAvailable = false;
        try {
            $watchToday = $readWatchTime();
            $watchTodayAvailable = true;
        } catch (\Throwable $e) {
            Log::logException($e);
        }

        $watchTodayEstimated = true;
        $watchTodayEstimateAvailable = false;
        if ($watchTodayAvailable) {
            try {
                $watchTodayEstimated = $readEstimated();
                $watchTodayEstimateAvailable = true;
            } catch (\Throwable $e) {
                Log::logException($e);
            }
        }

        return [
            'streams' => $streams,
            'metadata_available' => $metadataAvailable,
            'recording_available' => $recordingAvailable,
            'watch_today' => $watchToday,
            'watch_today_available' => $watchTodayAvailable,
            'watch_today_estimated' => $watchTodayEstimated,
            'watch_today_estimate_available' => $watchTodayEstimateAvailable,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $streams
     * @return array<string, mixed>
     */
    private function stats(
        array $streams,
        ?int $watchToday,
        bool $watchTodayEstimated,
        bool $watchTodayAvailable = true,
        bool $watchTodayEstimateAvailable = true,
        bool $recordingAvailable = true,
        bool $metadataAvailable = true,
    ): array {
        $users = [];
        $bitrate = 0;
        $transcodes = 0;

        foreach ($streams as $stream) {
            $user = (string) ($stream['user'] ?? '');
            if ($user !== '') {
                $users[$user] = true;
            }
            $bitrate += (int) ($stream['bitrate'] ?? 0);
            if (($stream['isTranscode'] ?? false) === true) {
                $transcodes++;
            }
        }

        return [
            'watch_today' => $watchTodayAvailable
                ? (($watchTodayEstimated || !$watchTodayEstimateAvailable) ? 'about ' : '')
                    . PlaybackStatisticsService::formatDuration((int) $watchToday)
                : 'Unavailable',
            'watch_today_available' => $watchTodayAvailable,
            'watch_today_estimated' => $watchTodayAvailable
                && ($watchTodayEstimated || !$watchTodayEstimateAvailable),
            'watch_today_estimate_available' => $watchTodayEstimateAvailable,
            'collection_status' => $recordingAvailable ? 'ok' : 'degraded',
            'recording_available' => $recordingAvailable,
            'metadata_available' => $metadataAvailable,
            'active_streams' => count($streams),
            'active_users' => count($users),
            'bandwidth_mbps' => number_format($bitrate / 1000000, 1, '.', ''),
            'transcodes' => $transcodes,
        ];
    }

}
