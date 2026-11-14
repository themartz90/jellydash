<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Log;

final class NowPlayingService
{
    public function __construct(
        private ?JellyfinClient $client = null,
        private ?JellyfinSessionMapper $mapper = null,
        private ?PlayHistoryRepository $history = null,
        private ?LiveLibraryResolver $libraryResolver = null,
        private ?MonitoringExclusions $exclusions = null,
        private ?\Closure $movieIdentityCapture = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $client = $this->client ?? new JellyfinClient();
        $mapper = $this->mapper ?? new JellyfinSessionMapper($client->baseUrl());
        $history = null;
        try {
            $history = $this->history ?? new PlayHistoryRepository();
        } catch (\Throwable $e) {
            // Theme metadata and its local cache are optional to live playback.
            // If either is unavailable, unknown sessions remain visible.
            Log::logException($e);
        }
        $observation = $this->observe($client, $mapper, $history, true);
        $sessions = $observation['sessions'];
        $this->captureMovieIdentities($sessions, $client);
        $mapped = $observation['mapped'];
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
            $history ??= $this->history ?? new PlayHistoryRepository();
            $cycle = $this->historyCycle(
                $streams,
                fn (array $activeStreams): array => $this->resolveLibraries($activeStreams, $client, $history),
                function () use ($history, $client, $observation): void {
                    if (!$observation['captured']) {
                        throw new HistoryWriteBusyException('This refresh could not capture playback History.');
                    }
                    $history->drainSnapshots(fn (array $pending): array => $this->enrichCaptured($pending, $client, $history));
                },
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
        $history = $this->history ?? new PlayHistoryRepository();
        $observation = $this->observe($client, $mapper, $history, false);
        $sessions = $observation['sessions'];
        $this->captureMovieIdentities($sessions, $client);
        /** @var array<int, array<string, mixed>> $streams */
        $streams = $observation['mapped']['streams'];
        $history->drainSnapshots(fn (array $pending): array => $this->enrichCaptured($pending, $client, $history));
        $history->themePlaybackExclusions()->classifyHistoryBatch($client);

        return count($streams);
    }

    /** @return array<string, mixed> */
    private function observe(JellyfinClient $client, JellyfinSessionMapper $mapper, ?PlayHistoryRepository $history, bool $displayFallback): array
    {
        $exclusions = $this->exclusions ?? new MonitoringExclusions();
        $context = new class () {
            /** @var list<array<string, mixed>>|null */
            public ?array $raw = null;
            /** @var list<array<string, mixed>>|null */
            public ?array $filteredDisplay = null;
            public ?\Throwable $fetchError = null;
        };
        try {
            if ($history === null) {
                throw new HistoryWriteBusyException('History storage is unavailable.');
            }
            return $history->captureSnapshot(ThemePlaybackExclusions::serverKey($client->baseUrl()), function () use ($client, $mapper, $history, $exclusions, $context): array {
                try {
                    $all = $client->sessions();
                } catch (\Throwable $error) {
                    $context->fetchError = $error;
                    throw $error;
                }
                $at = new \DateTimeImmutable('now');
                $counts = [];
                foreach ($all as $session) {
                    $id = trim((string) ($session['Id'] ?? ''));
                    if ($id !== '') {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
                $ambiguous = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
                $context->raw = $exclusions->filterSessions($all);
                $sessions = $history->themePlaybackExclusions()->filterSessions($context->raw, $client);
                $context->filteredDisplay = $sessions;
                $mapped = $mapper->map($sessions);
                $ordinary = [];
                foreach ($mapped['streams'] as $stream) {
                    if ($history->themePlaybackExclusions()->classification((string) $stream['itemId']) === ThemePlaybackClassifier::ORDINARY) {
                        $ordinary[] = (string) $stream['itemId'];
                    }
                }
                $snapshot = PlaybackSnapshot::create($context->raw, $mapped['streams'], $ordinary, $ambiguous);
                return ['sessions' => $sessions, 'mapped' => $mapped, 'streams' => [...$snapshot['streams'], ...$history->themePlaybackExclusions()->observedThemeSamples()],
                    'connections' => $snapshot['connections'], 'at' => $at, 'captured' => true];
            });
        } catch (\Throwable $error) {
            if (!$displayFallback || $context->fetchError !== null) {
                throw $error;
            }
            Log::logException($error);
            $context->raw ??= $exclusions->filterSessions($client->sessions());
            if ($context->filteredDisplay === null && $history !== null) {
                try {
                    $context->filteredDisplay = $history->themePlaybackExclusions()->filterSessions($context->raw, $client);
                } catch (\Throwable $filterError) {
                    Log::logException($filterError);
                }
            }
            $context->filteredDisplay ??= array_values(array_filter($context->raw, static fn (array $session): bool =>
                !is_array($session['NowPlayingItem'] ?? null) || (new ThemePlaybackClassifier())->classify($session['NowPlayingItem']) !== ThemePlaybackClassifier::THEME));
            return ['sessions' => $context->filteredDisplay, 'mapped' => $mapper->map($context->filteredDisplay), 'captured' => false];
        }
    }

    /** @param array<int, array<string, mixed>> $pending @return array<int, array<string, mixed>> */
    private function enrichCaptured(array $pending, JellyfinClient $client, PlayHistoryRepository $history): array
    {
        $themes = array_filter($pending, static fn (array $stream): bool => ($stream['themeClassification'] ?? null) === ThemePlaybackClassifier::THEME);
        $active = array_filter($pending, static fn (array $stream): bool => ($stream['themeClassification'] ?? null) !== ThemePlaybackClassifier::THEME);
        try {
            $active = $this->resolveLibraries($active, $client, $history);
        } catch (\Throwable $error) {
            Log::logException($error);
        }
        return [...$active, ...$themes];
    }

    /** @param array<int, array<string, mixed>> $sessions */
    private function captureMovieIdentities(array $sessions, JellyfinClient $client): void
    {
        if ($sessions === [] || ($this->movieIdentityCapture === null && trim((string) Config::get('JELLYFIN_URL', '')) === '')) {
            return;
        }
        try {
            if ($this->movieIdentityCapture !== null) {
                ($this->movieIdentityCapture)($sessions);
            } else {
                (new MovieRecoveryService(client: $client))->captureSessions($sessions);
            }
        } catch (\Throwable $error) {
            // Identity enrichment must not prevent recording a real play.
            Log::logException($error);
        }
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
