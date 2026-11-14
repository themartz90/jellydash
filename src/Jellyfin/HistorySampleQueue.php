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
        'playbackOwner', 'playbackServer',
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
            $this->append($streams, $observedAt);
        }
        $this->drain($write);
    }

    /** Serializes fetch, privacy filtering and publication, independently of SQL writes.
     * @param \Closure(): array<string, mixed> $prepare
     * @return array<string, mixed>
     */
    public function capture(string $source, \Closure $prepare, ?\Closure $storedOrder = null): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create the pending History sample directory.');
        }
        $lock = fopen($this->directory . '/.capture.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the playback capture lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new HistoryWriteBusyException('Another playback observation is being captured.');
            }
            $prepared = $prepare();
            $counterPath = $this->directory . '/.capture-order.json';
            $counter = is_file($counterPath) ? json_decode((string) @file_get_contents($counterPath), true) : null;
            if (!is_array($counter) || !is_string($counter['journal'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $counter['journal']) || !is_int($counter['order'] ?? null)) {
                $counter = null;
            }
            if ($counter === null) {
                // Recover the journal from published pending frames after counter loss.
                $pending = glob($this->directory . '/capture-*.json') ?: [];
                rsort($pending, SORT_STRING);
                foreach ($pending as $file) {
                    $json = @file_get_contents($file);
                    if ($json !== false) {
                        $counter = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['capture'] ?? null;
                        break;
                    }
                }
            }
            $counter ??= ['journal' => bin2hex(random_bytes(16)), 'order' => 0];
            if (!is_string($counter['journal'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $counter['journal']) || !is_int($counter['order'] ?? null)) {
                throw new \RuntimeException('Invalid playback capture counter.');
            }
            $pendingFiles = glob($this->directory . '/capture-*.json') ?: [];
            foreach ($pendingFiles as $file) {
                if (preg_match('/capture-([0-9]{20})-/', basename($file), $match)) {
                    $counter['order'] = max($counter['order'], (int) $match[1]);
                }
            }
            $receipt = null;
            if ($storedOrder !== null) {
                try {
                    $counter['order'] = max($counter['order'], $storedOrder($counter['journal']));
                } catch (\Throwable) {
                    $receipt = bin2hex(random_bytes(16));
                }
            }
            $capture = ['source' => $source, 'journal' => $counter['journal'], 'order' => $counter['order'] + 1, 'connections' => $prepared['connections'], 'receipt_id' => $receipt];
            $counterTemporary = $counterPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $counterFile = fopen($counterTemporary, 'xb');
            if ($counterFile === false) {
                throw new \RuntimeException('Could not retain the playback capture order.');
            }
            try {
                $json = json_encode(['journal' => $capture['journal'], 'order' => $capture['order']], JSON_THROW_ON_ERROR);
                if (fwrite($counterFile, $json) !== strlen($json) || !fflush($counterFile) || !fsync($counterFile)) {
                    throw new \RuntimeException('Could not retain the playback capture order.');
                }
                @chmod($counterTemporary, 0600);
            } catch (\Throwable $error) {
                fclose($counterFile);
                @unlink($counterTemporary);
                throw $error;
            }
            fclose($counterFile);
            if (!rename($counterTemporary, $counterPath)) {
                @unlink($counterTemporary);
                throw new \RuntimeException('Could not publish the playback capture order.');
            }
            $this->append($prepared['streams'], $prepared['at'], $capture);
            return $prepared + ['capture' => $capture];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<array<string, mixed>> $streams @param array<string, mixed>|null $capture */
    private function append(array $streams, \DateTimeImmutable $observedAt, ?array $capture = null): void
    {
        $fields = array_fill_keys(self::FIELDS, true);
        $payload = json_encode([
            'observed_at' => $observedAt->format('Y-m-d\TH:i:s.uP'),
            'capture' => $capture,
            'streams' => array_map(static fn (array $stream): array => array_replace(
                array_intersect_key($stream, $fields),
                ArtworkObservation::streamFields($stream),
            ), $streams),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $name = $this->directory . '/' . ($capture === null ? sprintf('%020d', $observedAt->getTimestamp()) : 'capture-' . sprintf('%020d', $capture['order'])) . '-' . bin2hex(random_bytes(12));
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

    /** @param \Closure $write Receives streams, observation time and optional capture metadata. */
    public function drain(\Closure $write): void
    {
        if (!is_dir($this->directory)) {
            return;
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
                $write($sample['streams'], new \DateTimeImmutable($sample['observed_at']), $sample['capture'] ?? null);
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
