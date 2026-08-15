<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Turns Playback Reporting plugin data (TSV backup, SQLite db, or custom-query
 * API rows) into play_history rows. The plugin stores one finished play per
 * line; Jellydash records live sessions, so imported rows get a synthetic
 * session key and are already-notified. PlayDuration is session length, not
 * media runtime: runtime_sec and is_finished are filled by the importer via
 * the Jellyfin API. Recorded PlayDuration is kept as-is, including short
 * sessions.
 */
final class PlaybackReportingParser
{
    public const SESSION_PREFIX = 'pr-';

    /**
     * @return list<array<string, mixed>>
     */
    public function parseTsv(string $contents): array
    {
        $contents = $this->stripBom($contents);
        $rows = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $mapped = $this->parseTsvLine($line);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateTsvFile(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the Playback Reporting file.');
        }

        try {
            $first = true;
            while (($line = fgets($handle)) !== false) {
                if ($first) {
                    $line = $this->stripBom($line);
                    $first = false;
                }

                $mapped = $this->parseTsvLine($line);
                if ($mapped !== null) {
                    yield $mapped;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateSqliteFile(string $path): \Generator
    {
        if (!class_exists(\SQLite3::class)) {
            throw new \RuntimeException('The PHP sqlite3 extension is required to import a Playback Reporting database.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Playback Reporting database file is not readable.');
        }

        $sqlite = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $sqlite->busyTimeout(3000);

        try {
            $table = $sqlite->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'PlaybackActivity'");
            if ($table !== 'PlaybackActivity') {
                throw new \RuntimeException('This SQLite file has no PlaybackActivity table.');
            }

            $result = $sqlite->query(
                'SELECT DateCreated, UserId, ItemId, ItemType, ItemName, PlaybackMethod, ClientName, DeviceName, PlayDuration FROM PlaybackActivity ORDER BY DateCreated'
            );
            if ($result === false) {
                throw new \RuntimeException('Could not read PlaybackActivity from the SQLite file.');
            }

            while ($row = $result->fetchArray(SQLITE3_NUM)) {
                $mapped = $this->mapTokens(array_map(static fn (mixed $v): string => (string) $v, $row));
                if ($mapped !== null) {
                    yield $mapped;
                }
            }
        } finally {
            $sqlite->close();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseSqliteFile(string $path): array
    {
        return iterator_to_array($this->iterateSqliteFile($path), false);
    }

    /**
     * @param array<int, mixed> $columns
     * @param array<int, mixed> $results
     * @return list<array<string, mixed>>
     */
    public function parseApiResults(array $columns, array $results): array
    {
        $index = [];
        foreach ($columns as $i => $name) {
            $index[strtolower((string) $name)] = (int) $i;
        }

        $order = ['datecreated', 'userid', 'itemid', 'itemtype', 'itemname', 'playbackmethod', 'clientname', 'devicename', 'playduration'];
        $rows = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $tokens = [];
            foreach ($order as $column) {
                if (isset($index[$column])) {
                    $tokens[] = (string) ($result[$index[$column]] ?? '');
                    continue;
                }

                $tokens[] = (string) ($result[count($tokens)] ?? '');
            }

            $mapped = $this->mapTokens($tokens);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * Fill user_name from a map of stripped-hex user id => display name.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $userNames
     * @return list<array<string, mixed>>
     */
    public function applyUserNames(array $rows, array $userNames): array
    {
        foreach ($rows as &$row) {
            $key = $this->strippedId((string) ($row['user_id'] ?? ''));
            if ($key !== '' && isset($userNames[$key])) {
                $row['user_name'] = $userNames[$key];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, string> $tokens
     * @return array<string, mixed>|null
     */
    private function mapTokens(array $tokens): ?array
    {
        if (count($tokens) !== 9) {
            return null;
        }

        $startedAt = $this->startedAt($tokens[0]);
        $itemId = $this->jellyfinGuid($tokens[2]);
        $watchedSec = $this->watchedSec($tokens[8]);

        if ($startedAt === null || $itemId === '' || $watchedSec === null) {
            return null;
        }

        $itemType = trim($tokens[3]);
        if ($itemType === '') {
            $itemType = 'Video';
        }

        $titles = $this->titles($itemType, $tokens[4]);
        $method = $this->playMethod($tokens[5]);
        $endedAt = $this->endedAt($startedAt, $watchedSec);
        $userId = $this->jellyfinGuid($tokens[1]);

        return [
            'session_key' => self::SESSION_PREFIX . sha1($tokens[0] . '|' . trim($tokens[1]) . '|' . trim($tokens[2])),
            'user_id' => $userId !== '' ? $userId : null,
            'user_name' => null,
            'item_id' => $itemId,
            'item_type' => mb_substr($itemType, 0, 16),
            'series_name' => $titles['series_name'],
            'item_name' => $titles['item_name'],
            'season_ep' => $titles['season_ep'],
            'library' => $this->library($itemType),
            'play_method' => $method['play_method'],
            'play_method_detail' => $method['play_method_detail'],
            'client' => $this->nullable(trim($tokens[6])),
            'device' => $this->nullable(trim($tokens[7])),
            'source_video_codec' => null,
            'source_audio_codec' => null,
            'source_container' => null,
            'target_video_codec' => $method['target_video_codec'],
            'target_audio_codec' => $method['target_audio_codec'],
            'target_container' => null,
            'is_video_direct' => $method['is_video_direct'],
            'is_audio_direct' => $method['is_audio_direct'],
            'transcode_reasons' => null,
            'watched_sec' => $watchedSec,
            'runtime_sec' => 0,
            'started_at' => $startedAt,
            'updated_at' => $endedAt,
            'ended_at' => $endedAt,
            'is_finished' => 0,
            'notified' => 1,
        ];
    }

    private function startedAt(string $raw): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', trim($raw), $match)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($match[1]);
        } catch (\Exception) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function endedAt(string $startedAt, int $watchedSec): string
    {
        return (new \DateTimeImmutable($startedAt))
            ->modify('+' . $watchedSec . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private function watchedSec(string $raw): ?int
    {
        if (!preg_match('/^-?\d+$/', trim($raw))) {
            return null;
        }

        $value = (int) $raw;
        if ($value < 0) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{series_name: ?string, item_name: string, season_ep: ?string}
     */
    private function titles(string $itemType, string $itemName): array
    {
        $itemName = trim($itemName);
        if ($itemName === '') {
            $itemName = 'Unknown title';
        }

        if (strcasecmp($itemType, 'Episode') === 0
            && preg_match('/^(.*?) - s(\d+)e(\d+) - (.*)$/i', $itemName, $match)
        ) {
            $series = trim($match[1]);
            $episode = trim($match[4]);

            return [
                'series_name' => $series !== '' ? mb_substr($series, 0, 255) : null,
                'item_name' => mb_substr($episode !== '' ? $episode : $itemName, 0, 255),
                'season_ep' => 'S' . (int) $match[2] . ' E' . (int) $match[3],
            ];
        }

        return [
            'series_name' => null,
            'item_name' => mb_substr($itemName, 0, 255),
            'season_ep' => null,
        ];
    }

    /**
     * @return array{
     *     play_method: string,
     *     play_method_detail: string,
     *     target_video_codec: ?string,
     *     target_audio_codec: ?string,
     *     is_video_direct: ?int,
     *     is_audio_direct: ?int
     * }
     */
    private function playMethod(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^Transcode(?: \(v:([^ ]+) a:([^)]+)\))?$/i', $raw, $match)) {
            $video = strtolower($match[1] ?? '');
            $audio = strtolower($match[2] ?? '');
            $videoDirect = $video === 'direct';
            $audioDirect = $audio === 'direct';

            $detail = 'Video Transcode';
            if ($video !== '' && $audio !== '') {
                if ($videoDirect && $audioDirect) {
                    $detail = 'Remux';
                } elseif ($videoDirect) {
                    $detail = 'Audio Transcode';
                }
            }

            return [
                'play_method' => 'Transcode',
                'play_method_detail' => $detail,
                'target_video_codec' => ($video !== '' && !$videoDirect) ? $this->codecLabel($video) : null,
                'target_audio_codec' => ($audio !== '' && !$audioDirect) ? $this->codecLabel($audio) : null,
                'is_video_direct' => $video === '' ? null : ($videoDirect ? 1 : 0),
                'is_audio_direct' => $audio === '' ? null : ($audioDirect ? 1 : 0),
            ];
        }

        if (preg_match('/^DirectStream/i', $raw)) {
            return [
                'play_method' => 'DirectStream',
                'play_method_detail' => 'Direct Stream',
                'target_video_codec' => null,
                'target_audio_codec' => null,
                'is_video_direct' => 1,
                'is_audio_direct' => 1,
            ];
        }

        return [
            'play_method' => 'DirectPlay',
            'play_method_detail' => 'Direct Play',
            'target_video_codec' => null,
            'target_audio_codec' => null,
            'is_video_direct' => 1,
            'is_audio_direct' => 1,
        ];
    }

    private function library(string $itemType): string
    {
        return match (strtolower($itemType)) {
            'episode' => 'TV Shows',
            'movie' => 'Movies',
            'audio', 'audiobook', 'musicalbum' => 'Music',
            default => 'Videos',
        };
    }

    private function codecLabel(string $codec): string
    {
        return match (strtolower(trim($codec))) {
            'hevc', 'h265', 'h.265' => 'HEVC',
            'h264', 'h.264', 'avc' => 'H.264',
            'mpeg4', 'mpeg-4' => 'MPEG-4',
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

    private function jellyfinGuid(string $id): string
    {
        $hex = $this->strippedId($id);
        if ($hex === '') {
            return '';
        }

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    public function strippedId(string $id): string
    {
        $hex = strtolower(str_replace('-', '', trim($id)));

        return preg_match('/^[0-9a-f]{32}$/', $hex) === 1 ? $hex : '';
    }

    private function parseTsvLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (trim($line) === '') {
            return null;
        }

        return $this->mapTokens(explode("\t", $line));
    }

    private function nullable(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 64);
    }

    private function stripBom(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }
}
