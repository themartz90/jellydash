<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Database;

/** Keeps observed playback samples until their History writes have completed. */
final class HistorySampleQueue
{
    private const DRAIN_LIMIT = 50;
    /** @var \WeakMap<\Dibi\Connection, string>|null */
    private static ?\WeakMap $memoryProfiles = null;
    private const FIELDS = [
        'id', 'itemId', 'userId', 'user', 'itemType', 'seriesName', 'itemName', 'seasonEp',
        'library', 'libraryResolved', 'playMethod', 'methodLabel', 'client', 'device',
        'sourceVideoCodec', 'sourceAudioCodec', 'sourceContainer', 'targetVideoCodec',
        'targetAudioCodec', 'targetContainer', 'isVideoDirect', 'isAudioDirect',
        'transcodeReasons', 'watchedSec', 'runtimeSec', 'isPaused', 'playbackRate', 'themeClassification', 'themeClassificationEpoch', 'themeClassificationVersion',
    ];

    public function __construct(private readonly string $directory)
    {
    }

    public static function forDatabase(Database $database): self
    {
        $db = $database->getDibi();
        $name = (string) $db->getConfig('database');
        if ($database->getPlatform()->isSqlite()) {
            if ($name === ':memory:') {
                self::$memoryProfiles ??= new \WeakMap();
                $name = self::$memoryProfiles[$db] ??= bin2hex(random_bytes(16));
            } else {
                $name = realpath($name) ?: $name;
            }
        }
        $profile = hash('sha256', serialize([
            $database->getPlatform()->isSqlite(), $db->getConfig('host'), $db->getConfig('port'), $name,
            ThemePlaybackExclusions::serverKey((string) Config::get('JELLYFIN_URL', '')),
        ]));
        // Both bundled database profiles already persist this runtime directory.
        $root = (string) Config::get('HISTORY_SAMPLE_QUEUE_DIR', ROOT_DIR . '/var/cache/history-samples');

        return new self(rtrim($root, '/\\') . '/' . $profile);
    }

    /**
     * @param list<array<string, mixed>> $streams
     * @param \Closure(list<array<string, mixed>>, \DateTimeImmutable): void $write
     */
    public function record(array $streams, \DateTimeImmutable $observedAt, \Closure $write): void
    {
        if ($streams === [] && !is_dir($this->directory)) {
            return;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create the pending History sample directory.');
        }
        if ($streams !== []) {
            $fields = array_fill_keys(self::FIELDS, true);
            $payload = json_encode([
                'observed_at' => $observedAt->format('Y-m-d\TH:i:s.uP'),
                'streams' => array_map(static fn (array $stream): array => array_intersect_key($stream, $fields), $streams),
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $name = $this->directory . '/' . sprintf('%020d', $observedAt->getTimestamp()) . '-' . bin2hex(random_bytes(12));
            $temporary = $name . '.tmp';
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) {
                throw new \RuntimeException('Could not retain a pending History sample.');
            }
            try {
                @chmod($temporary, 0600);
                $remaining = $payload;
                while ($remaining !== '') {
                    $written = fwrite($stream, $remaining);
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Could not retain a complete History sample.');
                    }
                    $remaining = substr($remaining, $written);
                }
                if (!fflush($stream) || !fsync($stream)) {
                    throw new \RuntimeException('Could not flush a pending History sample.');
                }
            } catch (\Throwable $e) {
                fclose($stream);
                @unlink($temporary);
                throw $e;
            }
            fclose($stream);
            if (!@rename($temporary, $name . '.json')) {
                @unlink($temporary);
                throw new \RuntimeException('Could not publish a pending History sample.');
            }
        }

        $lock = @fopen($this->directory . '/.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the pending History sample lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new HistoryWriteBusyException('Captured playback samples are waiting to be recorded.');
            }
            $files = glob($this->directory . '/*.json');
            if ($files === false) {
                throw new \RuntimeException('Could not list pending History samples.');
            }
            sort($files, SORT_STRING);
            foreach (array_slice($files, 0, self::DRAIN_LIMIT) as $file) {
                $json = file_get_contents($file);
                $sample = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($sample) || !is_string($sample['observed_at'] ?? null)
                    || !is_array($sample['streams'] ?? null) || $sample['observed_at'] === '') {
                    throw new \RuntimeException('A pending History sample could not be read.');
                }
                $write($sample['streams'], new \DateTimeImmutable($sample['observed_at']));
                if (!unlink($file)) {
                    throw new \RuntimeException('Could not acknowledge a recorded History sample.');
                }
            }
            if (count($files) > self::DRAIN_LIMIT) {
                throw new HistoryWriteBusyException('Captured playback samples are waiting to be recorded.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
