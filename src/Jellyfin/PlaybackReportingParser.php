<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Turns Playback Reporting plugin data (TSV backup, SQLite db, or custom-query
 * API rows) into play_history rows. The plugin stores one finished play per
 * line; Jellydash records live sessions, so imported rows get a synthetic
 * session key and are already-notified. PlayDuration is elapsed session time,
 * not media runtime: runtime_sec and is_finished are filled by the importer
 * via the server API. When PauseDuration is available, watched time excludes
 * it while the recorded session end still uses the full elapsed duration.
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
            throw new ImportValidationException('Could not read the Playback Reporting file.');
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
            throw new ImportValidationException('The PHP sqlite3 extension is required to import a Playback Reporting database.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new ImportValidationException('Playback Reporting database file is not readable.');
        }

        $sqlite = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $sqlite->busyTimeout(3000);

        try {
            $table = $sqlite->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'PlaybackActivity'");
            if ($table !== 'PlaybackActivity') {
                throw new ImportValidationException('This SQLite file has no PlaybackActivity table.');
            }

            $hasPauseDuration = false;
            $schema = $sqlite->query('PRAGMA table_info(PlaybackActivity)');
            if ($schema !== false) {
                while ($column = $schema->fetchArray(SQLITE3_ASSOC)) {
                    if (strcasecmp((string) ($column['name'] ?? ''), 'PauseDuration') === 0) {
                        $hasPauseDuration = true;
                        break;
                    }
                }
            }

            $columns = 'DateCreated, UserId, ItemId, ItemType, ItemName, PlaybackMethod, ClientName, DeviceName, PlayDuration';
            if ($hasPauseDuration) {
                $columns .= ', PauseDuration';
            }

            $result = $sqlite->query('SELECT ' . $columns . ' FROM PlaybackActivity ORDER BY DateCreated');
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
        if (isset($index['pauseduration'])) {
            $order[] = 'pauseduration';
        }
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
     * Fill user_name from a map of normalized user id => display name.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $userNames
     * @return list<array<string, mixed>>
     */
    public function applyUserNames(array $rows, array $userNames): array
    {
        foreach ($rows as &$row) {
            $key = $this->idKey((string) ($row['user_id'] ?? ''));
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
        if (count($tokens) < 9) {
            return null;
        }

        $timestamp = $this->sourceTimestamp($tokens[0]);
        $itemId = $this->playbackId($tokens[2]);
        $playDuration = $this->watchedSec($tokens[8]);
        $pauseDuration = 0;
        if (array_key_exists(9, $tokens) && trim($tokens[9]) !== '') {
            $pauseDuration = $this->watchedSec($tokens[9]);
        }

        if ($timestamp === null || $itemId === '' || $playDuration === null || $pauseDuration === null) {
            return null;
        }
        $startedAt = $timestamp['started_at'];

        $watchedSec = max(0, $playDuration - $pauseDuration);

        $itemType = trim($tokens[3]);
        if ($itemType === '') {
            $itemType = 'Video';
        }

        $titles = $this->titles($itemType, $tokens[4]);
        $method = $this->playMethod($tokens[5]);
        $endedAt = $this->endedAt($startedAt, $playDuration);
        $userId = $this->playbackId($tokens[1]);
        $canonicalUser = $userId !== '' ? $userId : trim($tokens[1]);
        $identity = json_encode([$timestamp['canonical'], $canonicalUser, $itemId], JSON_THROW_ON_ERROR);

        return [
            'session_key' => self::SESSION_PREFIX . 'v2-' . sha1($identity),
            'legacy_session_key' => self::SESSION_PREFIX . sha1($tokens[0] . '|' . trim($tokens[1]) . '|' . trim($tokens[2])),
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
            'client' => $this->metadata(trim($tokens[6]), 'client'),
            'device' => $this->metadata(trim($tokens[7]), 'device'),
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
            'watch_duration_sec' => $watchedSec,
            'runtime_sec' => 0,
            'started_at' => $startedAt,
            'updated_at' => $endedAt,
            'ended_at' => $endedAt,
            'is_finished' => 0,
            'notified' => 1,
        ];
    }

    /** @return array{started_at: string, canonical: string}|null */
    private function sourceTimestamp(string $raw): ?array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', trim($raw), $match) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1]);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $match[1]) {
            return null;
        }

        $fraction = rtrim($match[2] ?? '', '0');

        return [
            'started_at' => $date->format('Y-m-d H:i:s'),
            'canonical' => $match[1] . ($fraction !== '' ? '.' . $fraction : ''),
        ];
    }

    private function endedAt(string $startedAt, int $watchedSec): string
    {
        return (new \DateTimeImmutable($startedAt))
            ->modify('+' . $watchedSec . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private function watchedSec(string $raw): ?int
    {
        $raw = trim($raw);
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $digits = ltrim($raw, '0');
        if ($digits === '') {
            return 0;
        }
        $maximum = '2147483647';
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }

        return (int) $digits;
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
                'series_name' => $this->metadata($series, 'series title'),
                'item_name' => $this->metadata($episode !== '' ? $episode : $itemName, 'item title'),
                'season_ep' => 'S' . (int) $match[2] . ' E' . (int) $match[3],
            ];
        }

        return [
            'series_name' => null,
            'item_name' => $this->metadata($itemName, 'item title'),
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

    private function playbackId(string $id): string
    {
        $raw = trim($id);
        if (preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $raw) === 1) {
            $hex = strtolower(str_replace('-', '', $raw));
            return substr($hex, 0, 8) . '-'
                . substr($hex, 8, 4) . '-'
                . substr($hex, 12, 4) . '-'
                . substr($hex, 16, 4) . '-'
                . substr($hex, 20, 12);
        }

        return preg_match('/^[0-9]{1,64}$/', $raw) === 1 ? $raw : '';
    }

    public function idKey(string $id): string
    {
        $normalized = $this->playbackId($id);

        return strtolower(str_replace('-', '', $normalized));
    }

    private function parseTsvLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (trim($line) === '') {
            return null;
        }

        return $this->mapTokens(explode("\t", $line));
    }

    private function metadata(string $value, string $field): ?string
    {
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value, 'UTF-8') > PlayHistoryRepository::MAX_METADATA_CHARACTERS) {
            throw new ImportValidationException('A Playback Reporting ' . $field . ' exceeds the History limit of '
                . PlayHistoryRepository::MAX_METADATA_CHARACTERS . ' characters.');
        }

        return $value;
    }

    private function stripBom(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }
}
