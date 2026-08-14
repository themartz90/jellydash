<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class JellyfinSessionMapper
{
    private const ALLOWED_TYPES = ['Episode', 'Movie', 'Audio', 'Video', 'TvChannel'];

    private const AVATAR_GRADIENTS = [
        'linear-gradient(135deg,#7c5cff,#b06bff)',
        'linear-gradient(135deg,#f0913a,#f7b955)',
        'linear-gradient(135deg,#1fb6a6,#34d8a6)',
        'linear-gradient(135deg,#3b9eff,#6f7bff)',
    ];

    private const BACKDROP_GRADIENTS = [
        'radial-gradient(130% 120% at 78% 12%, #7a4a1e 0%, #3a2410 50%, #160d07 100%)',
        'radial-gradient(130% 120% at 22% 8%, #1f4a5c 0%, #122733 52%, #0a141c 100%)',
        'radial-gradient(130% 120% at 70% 24%, #233d5d 0%, #121d33 48%, #090d18 100%)',
        'radial-gradient(130% 120% at 24% 16%, #69411f 0%, #2d1c16 54%, #100b0c 100%)',
    ];

    public function __construct(private string $baseUrl = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @return array{streams: array<int, array<string, mixed>>, hidden_count: int, hidden_sources: string}
     */
    public function map(array $sessions): array
    {
        $streams = [];
        $hiddenNames = [];

        foreach ($sessions as $session) {
            if (!$this->isActivePlayback($session)) {
                $hiddenNames[] = $this->sessionSource($session);
                continue;
            }

            $streams[] = $this->mapSession($session);
        }

        $hiddenNames = array_values(array_unique(array_filter($hiddenNames)));

        return [
            'streams' => $streams,
            'hidden_count' => count($sessions) - count($streams),
            'hidden_sources' => implode(' - ', array_slice($hiddenNames, 0, 3)),
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    public function isActivePlayback(array $session): bool
    {
        $item = $session['NowPlayingItem'] ?? null;
        $playState = $session['PlayState'] ?? null;

        if (!is_array($item) || !is_array($playState)) {
            return false;
        }

        if (!array_key_exists('PositionTicks', $playState)) {
            return false;
        }

        $type = (string) ($item['Type'] ?? '');

        return in_array($type, self::ALLOWED_TYPES, true);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function mapSession(array $session): array
    {
        /** @var array<string, mixed> $item */
        $item = $session['NowPlayingItem'];
        /** @var array<string, mixed> $playState */
        $playState = $session['PlayState'];

        $type = (string) ($item['Type'] ?? '');
        $itemName = (string) ($item['Name'] ?? 'Unknown title');
        $seriesName = (string) ($item['SeriesName'] ?? '');
        $user = (string) ($session['UserName'] ?? 'Unknown user');
        $positionTicks = $this->intValue($playState['PositionTicks'] ?? 0);
        $runtimeTicks = $this->intValue($item['RunTimeTicks'] ?? 0);
        $playMethod = (string) ($playState['PlayMethod'] ?? '');
        $isTranscode = $playMethod === 'Transcode' || isset($session['TranscodingInfo']);
        $isPaused = filter_var($playState['IsPaused'] ?? false, FILTER_VALIDATE_BOOL);
        $itemId = (string) ($item['Id'] ?? '');
        $video = $this->videoStream($item);
        $audio = $this->audioStream($item);
        $transcoding = $session['TranscodingInfo'] ?? [];
        $isLive = $type === 'TvChannel';

        $stream = [
            'id' => (string) ($session['Id'] ?? $itemId),
            'itemId' => $itemId,
            'itemType' => $type,
            'itemName' => $itemName,
            'seriesName' => $seriesName !== '' ? $seriesName : null,
            'seasonEp' => $type === 'Episode' ? $this->seasonEpisodeLabel($item) : null,
            'library' => $this->libraryLabel($type),
            'userId' => (string) ($session['UserId'] ?? ''),
            'client' => (string) ($session['Client'] ?? ''),
            'device' => (string) ($session['DeviceName'] ?? ''),
            'playMethod' => $playMethod !== '' ? $playMethod : ($isTranscode ? 'Transcode' : 'DirectPlay'),
            'watchedSec' => $this->ticksToSeconds($positionTicks),
            'runtimeSec' => $this->ticksToSeconds($runtimeTicks),
            'kindLabel' => $this->kindLabel($type),
            'title' => $this->title($item, $type),
            'subtitle' => $this->subtitle($item, $type),
            'user' => $user,
            'initials' => $this->initials($user),
            'avatarUrl' => $this->avatarUrl(
                (string) ($session['UserId'] ?? ''),
                (string) ($session['UserPrimaryImageTag'] ?? ''),
            ),
            'deviceLine' => trim((string) ($session['DeviceName'] ?? 'Unknown device') . ' - ' . (string) ($session['Client'] ?? 'Unknown client')),
            'quality' => $this->quality($item, $session, $isTranscode),
            'isTranscode' => $isTranscode,
            'isDirect' => in_array($playMethod, ['DirectPlay', 'DirectStream'], true) || !$isTranscode,
            'methodLabel' => $this->methodLabel($playMethod, $session, $isTranscode),
            'isPaused' => $isPaused,
            'statusLabel' => $isPaused ? 'Paused' : 'Now Playing',
            'progressPct' => $this->progressPct($positionTicks, $runtimeTicks),
            'timeLabel' => $this->formatTicks($positionTicks) . ' / ' . $this->formatTicks($runtimeTicks),
            'remaining' => $this->remainingLabel($positionTicks, $runtimeTicks),
            'avatarBg' => $this->pick(self::AVATAR_GRADIENTS, (string) ($session['UserId'] ?? $user)),
            'backdrop' => $this->backdrop($item, $type),
            'bitrate' => $this->bitrate($item, $session),
            'sourceVideoCodec' => $this->codecLabel((string) ($video['Codec'] ?? '')),
            'sourceAudioCodec' => $this->codecLabel((string) ($audio['Codec'] ?? '')),
            'sourceContainer' => $this->sourceContainer($item),
            'targetVideoCodec' => is_array($transcoding) ? $this->codecLabel((string) ($transcoding['VideoCodec'] ?? '')) : '',
            'targetAudioCodec' => is_array($transcoding) ? $this->codecLabel((string) ($transcoding['AudioCodec'] ?? '')) : '',
            'targetContainer' => is_array($transcoding) ? strtoupper((string) ($transcoding['Container'] ?? '')) : '',
            'isVideoDirect' => is_array($transcoding) ? filter_var($transcoding['IsVideoDirect'] ?? !$isTranscode, FILTER_VALIDATE_BOOL) : !$isTranscode,
            'isAudioDirect' => is_array($transcoding) ? filter_var($transcoding['IsAudioDirect'] ?? true, FILTER_VALIDATE_BOOL) : true,
            'transcodeReasons' => is_array($transcoding) ? $this->transcodeReasons($transcoding) : [],
            'isLive' => $isLive,
        ];

        return $isLive ? array_merge($stream, $this->liveOverrides($item, $positionTicks)) : $stream;
    }

    /**
     * Live TV (Tunarr et al.): the NowPlayingItem is the channel and carries the
     * current program under CurrentProgram. PositionTicks counts time since
     * tune-in, so progress and remaining come from the program's wall-clock
     * start/end instead. runtimeSec stays 0 so history never marks a live
     * viewing "finished" against a meaningless runtime.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function liveOverrides(array $item, int $positionTicks): array
    {
        $channelName = (string) ($item['Name'] ?? 'Live TV');
        $program = is_array($item['CurrentProgram'] ?? null) ? $item['CurrentProgram'] : [];
        $programName = trim((string) ($program['Name'] ?? ''));
        $episodeTitle = trim((string) ($program['EpisodeTitle'] ?? ''));
        $seasonEp = $this->seasonEpisodeLabel($program);

        $start = $this->utcTimestamp((string) ($program['StartDate'] ?? ''));
        $end = $this->utcTimestamp((string) ($program['EndDate'] ?? ''));
        $now = time();

        $progressPct = '0%';
        $timeLabel = 'Live';
        $remaining = 'Live';
        if ($start > 0 && $end > $start) {
            $progressPct = min(100, max(0, (int) round((($now - $start) / ($end - $start)) * 100))) . '%';
            $timeLabel = date('H:i', $start) . ' - ' . date('H:i', $end);
            $remaining = max(0, (int) ceil(($end - $now) / 60)) . ' min left';
        }

        $subtitleParts = array_values(array_filter([
            $channelName,
            $seasonEp !== '' ? $seasonEp : null,
            $episodeTitle !== '' ? $episodeTitle : null,
        ]));

        return [
            'kindLabel' => 'Live TV',
            'library' => 'Live TV',
            'title' => $programName !== '' ? $programName : $channelName,
            'subtitle' => implode(' - ', $subtitleParts),
            'seasonEp' => null,
            'statusLabel' => 'Live',
            'progressPct' => $progressPct,
            'timeLabel' => $timeLabel,
            'remaining' => $remaining,
            'runtimeSec' => 0,
            'watchedSec' => $this->ticksToSeconds($positionTicks),
            'backdrop' => $this->liveBackdrop($item, $program),
        ];
    }

    /**
     * Program artwork first (an episode still or poster), then the channel logo,
     * over the usual gradient fallback.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $program
     */
    private function liveBackdrop(array $item, array $program): string
    {
        $fallback = $this->pick(self::BACKDROP_GRADIENTS, (string) ($item['Id'] ?? '') . (string) ($item['Name'] ?? ''));

        $imageId = '';
        $programTags = $program['ImageTags'] ?? null;
        if (is_array($programTags) && ($programTags['Primary'] ?? '') !== '') {
            $imageId = (string) ($program['Id'] ?? '');
        }
        if ($imageId === '') {
            $channelTags = $item['ImageTags'] ?? null;
            if (is_array($channelTags) && ($channelTags['Primary'] ?? '') !== '') {
                $imageId = (string) ($item['Id'] ?? '');
            }
        }

        if ($imageId === '') {
            return $fallback;
        }

        return 'url("/api/image.php?item=' . rawurlencode($imageId) . '&type=Primary&maxWidth=1280"), ' . $fallback;
    }

    private function utcTimestamp(string $isoDate): int
    {
        if ($isoDate === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($isoDate))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    private function kindLabel(string $type): string
    {
        return match ($type) {
            'Episode' => 'TV - Episode',
            'Audio' => 'Music',
            'Video' => 'Video',
            default => 'Movie',
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function title(array $item, string $type): string
    {
        if ($type === 'Episode') {
            return (string) ($item['SeriesName'] ?? $item['Name'] ?? 'Unknown episode');
        }

        return (string) ($item['Name'] ?? 'Unknown title');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function subtitle(array $item, string $type): string
    {
        if ($type === 'Episode') {
            $season = $this->episodeNumber('S', $item['ParentIndexNumber'] ?? null);
            $episode = $this->episodeNumber('E', $item['IndexNumber'] ?? null);
            return trim($season . ' - ' . $episode . ' - ' . (string) ($item['Name'] ?? ''), ' -');
        }

        $parts = [];
        if (isset($item['ProductionYear'])) {
            $parts[] = (string) $item['ProductionYear'];
        }

        $genres = $item['Genres'] ?? [];
        if (is_array($genres) && isset($genres[0])) {
            $parts[] = (string) $genres[0];
        }

        $runtime = $this->durationLabel($this->intValue($item['RunTimeTicks'] ?? 0));
        if ($runtime !== '0m') {
            $parts[] = $runtime;
        }

        return $parts === [] ? (string) ($item['Type'] ?? 'Media') : implode(' - ', $parts);
    }

    private function episodeNumber(string $prefix, mixed $value): string
    {
        $number = $this->intValue($value);
        return $number > 0 ? $prefix . $number : '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function seasonEpisodeLabel(array $item): string
    {
        return trim(
            $this->episodeNumber('S', $item['ParentIndexNumber'] ?? null)
            . ' '
            . $this->episodeNumber('E', $item['IndexNumber'] ?? null)
        );
    }

    private function libraryLabel(string $type): string
    {
        return match ($type) {
            'Episode' => 'TV Shows',
            'Movie' => 'Movies',
            'Audio' => 'Music',
            default => 'Videos',
        };
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $session
     */
    private function quality(array $item, array $session, bool $isTranscode): string
    {
        $video = $this->videoStream($item);
        $source = $this->sourceVideoLabel($video);

        if ($isTranscode) {
            $transcoding = $session['TranscodingInfo'] ?? [];
            if (!is_array($transcoding)) {
                return $source . ' | Transcoding';
            }

            $isVideoDirect = filter_var($transcoding['IsVideoDirect'] ?? false, FILTER_VALIDATE_BOOL);
            $isAudioDirect = filter_var($transcoding['IsAudioDirect'] ?? true, FILTER_VALIDATE_BOOL);

            if ($isVideoDirect) {
                return $source;
            }

            $target = $this->targetVideoLabel($transcoding);
            return $target !== '' && $target !== $source ? $source . ' -> ' . $target : $source . ' | Video transcode';
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function methodLabel(string $playMethod, array $session, bool $isTranscode): string
    {
        if (!$isTranscode) {
            return $playMethod === 'DirectStream' ? 'Direct Stream' : 'Direct Play';
        }

        $transcoding = $session['TranscodingInfo'] ?? [];
        if (!is_array($transcoding)) {
            return 'Transcoding';
        }

        $isVideoDirect = filter_var($transcoding['IsVideoDirect'] ?? false, FILTER_VALIDATE_BOOL);
        $isAudioDirect = filter_var($transcoding['IsAudioDirect'] ?? true, FILTER_VALIDATE_BOOL);

        if ($isVideoDirect && !$isAudioDirect) {
            return 'Audio Transcode';
        }

        if ($isVideoDirect) {
            return 'Remux';
        }

        return 'Video Transcode';
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function videoStream(array $item): array
    {
        $streams = $item['MediaStreams'] ?? [];
        if (!is_array($streams)) {
            return [];
        }

        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['Type'] ?? '') === 'Video') {
                return $stream;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function audioStream(array $item): array
    {
        $streams = $item['MediaStreams'] ?? [];
        if (!is_array($streams)) {
            return [];
        }

        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['Type'] ?? '') === 'Audio') {
                return $stream;
            }
        }

        return [];
    }

    private function heightLabel(int $height): string
    {
        if ($height >= 2160) {
            return '4K';
        }

        if ($height > 0) {
            return $height . 'p';
        }

        return 'Unknown';
    }

    /**
     * @param array<string, mixed> $video
     */
    private function sourceVideoLabel(array $video): string
    {
        $displayTitle = trim((string) ($video['DisplayTitle'] ?? ''));
        if ($displayTitle !== '') {
            return $displayTitle;
        }

        $height = $this->heightLabel($this->intValue($video['Height'] ?? 0));
        $codec = strtoupper((string) ($video['Codec'] ?? ''));

        return trim($height . ($codec !== '' ? ' ' . $codec : ''));
    }

    /**
     * @param array<string, mixed> $transcoding
     */
    private function targetVideoLabel(array $transcoding): string
    {
        $height = $this->heightLabel($this->intValue($transcoding['Height'] ?? 0));
        $codec = strtoupper((string) ($transcoding['VideoCodec'] ?? ''));

        if ($height === 'Unknown' && $codec === '') {
            return '';
        }

        return trim(($height !== 'Unknown' ? $height : '') . ($codec !== '' ? ' ' . $codec : ''));
    }

    private function codecLabel(string $codec): string
    {
        $codec = strtolower(trim($codec));

        return match ($codec) {
            'hevc', 'h265', 'h.265' => 'HEVC',
            'h264', 'h.264', 'avc' => 'H.264',
            'mpeg4', 'mpeg-4', 'msmpeg4' => 'MPEG-4',
            'aac' => 'AAC',
            'ac3' => 'AC3',
            'eac3' => 'EAC3',
            'dts' => 'DTS',
            'truehd' => 'TrueHD',
            'opus' => 'Opus',
            'mp3' => 'MP3',
            '' => '',
            default => strtoupper($codec),
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function sourceContainer(array $item): string
    {
        $mediaSources = $item['MediaSources'] ?? [];
        if (is_array($mediaSources) && isset($mediaSources[0]) && is_array($mediaSources[0])) {
            return strtoupper((string) ($mediaSources[0]['Container'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $transcoding
     * @return array<int, string>
     */
    private function transcodeReasons(array $transcoding): array
    {
        $rawReasons = $transcoding['TranscodeReasons'] ?? [];
        $reasons = [];

        if (is_array($rawReasons)) {
            foreach ($rawReasons as $reason) {
                $label = $this->reasonLabel((string) $reason);
                if ($label !== '') {
                    $reasons[] = $label;
                }
            }
        }

        $isVideoDirect = filter_var($transcoding['IsVideoDirect'] ?? true, FILTER_VALIDATE_BOOL);
        $isAudioDirect = filter_var($transcoding['IsAudioDirect'] ?? true, FILTER_VALIDATE_BOOL);

        if (!$isAudioDirect) {
            $reasons[] = 'Audio codec not supported';
        }

        if (!$isVideoDirect) {
            $reasons[] = 'Video codec not supported';
        }

        return array_values(array_unique($reasons));
    }

    private function reasonLabel(string $reason): string
    {
        $reason = trim($reason);

        return match ($reason) {
            'AudioCodecNotSupported' => 'Audio codec not supported',
            'VideoCodecNotSupported' => 'Video codec not supported',
            'ContainerNotSupported' => 'Container not supported',
            'BitrateTooHigh' => 'Bitrate too high',
            'SubtitleCodecNotSupported',
            'SubtitleIsExternal',
            'SubtitleIsImageBased' => 'Subtitle burn-in',
            '' => '',
            default => trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $reason)),
        };
    }

    private function progressPct(int $positionTicks, int $runtimeTicks): string
    {
        if ($runtimeTicks <= 0) {
            return '0%';
        }

        return min(100, max(0, (int) round(($positionTicks / $runtimeTicks) * 100))) . '%';
    }

    private function remainingLabel(int $positionTicks, int $runtimeTicks): string
    {
        $remainingSeconds = max(0, (int) floor(($runtimeTicks - $positionTicks) / 10000000));
        $minutes = (int) ceil($remainingSeconds / 60);

        return $minutes . ' min left';
    }

    private function formatTicks(int $ticks): string
    {
        $seconds = $this->ticksToSeconds($ticks);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function durationLabel(int $ticks): string
    {
        $minutes = (int) round($this->ticksToSeconds($ticks) / 60);
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $remainingMinutes . 'm';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function backdrop(array $item, string $type): string
    {
        $itemId = $this->backdropItemId($item, $type);
        $fallback = $this->pick(self::BACKDROP_GRADIENTS, $itemId . (string) ($item['Name'] ?? ''));

        if ($itemId === '') {
            return $fallback;
        }

        return 'url("/api/image.php?item=' . rawurlencode($itemId) . '&type=Backdrop&maxWidth=1280"), ' . $fallback;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function backdropItemId(array $item, string $type): string
    {
        if ($type === 'Episode') {
            foreach (['ParentBackdropItemId', 'SeriesId', 'ParentId'] as $key) {
                $id = (string) ($item[$key] ?? '');
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return (string) ($item['Id'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $session
     */
    private function bitrate(array $item, array $session): int
    {
        // Transcode: the actual output bitrate.
        $transcoding = $session['TranscodingInfo'] ?? [];
        if (is_array($transcoding) && ($transcoding['Bitrate'] ?? 0)) {
            return $this->intValue($transcoding['Bitrate']);
        }

        // Direct play: the source bitrate. /Sessions items usually omit
        // MediaSources but always include MediaStreams, so fall back to summing
        // the video + audio stream bitrates.
        $mediaSources = $item['MediaSources'] ?? [];
        if (is_array($mediaSources) && is_array($mediaSources[0] ?? null) && ($mediaSources[0]['Bitrate'] ?? 0)) {
            return $this->intValue($mediaSources[0]['Bitrate']);
        }

        return $this->intValue($this->videoStream($item)['BitRate'] ?? 0)
            + $this->intValue($this->audioStream($item)['BitRate'] ?? 0);
    }

    /**
     * @param array<int, string> $values
     */
    private function pick(array $values, string $seed): string
    {
        $index = abs(crc32($seed)) % count($values);
        return $values[$index];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function sessionSource(array $session): string
    {
        $client = (string) ($session['Client'] ?? '');
        $device = (string) ($session['DeviceName'] ?? '');

        return trim($client . ($client !== '' && $device !== '' ? ' - ' : '') . $device) ?: 'Unknown session';
    }

    /**
     * Sessions include UserPrimaryImageTag when the account has a photo.
     * Skip the proxy otherwise so the poller does not 404 every 5 seconds.
     */
    private function avatarUrl(string $userId, string $imageTag): string
    {
        if (trim($imageTag) === '') {
            return '';
        }

        return JellyfinUserAvatars::proxyUrl($userId, $imageTag) ?? '';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $letters .= strtoupper(substr($part, 0, 1));
            }
        }

        return substr($letters !== '' ? $letters : 'U', 0, 2);
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function ticksToSeconds(int $ticks): int
    {
        return (int) floor($ticks / 10000000);
    }
}
