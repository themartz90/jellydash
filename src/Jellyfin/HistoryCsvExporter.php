<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class HistoryCsvExporter
{
    public const FORMAT_VERSION = '2';

    /** @var array<int, string> */
    public const V1_COLUMNS = [
        'jellydash_history_version',
        'jellydash_timezone',
        'session_key',
        'started_at',
        'updated_at',
        'ended_at',
        'user_id',
        'user_name',
        'item_id',
        'item_type',
        'series_name',
        'item_name',
        'season_ep',
        'library',
        'library_resolved_at',
        'play_method',
        'play_method_detail',
        'client',
        'device',
        'source_video_codec',
        'source_audio_codec',
        'source_container',
        'target_video_codec',
        'target_audio_codec',
        'target_container',
        'is_video_direct',
        'is_audio_direct',
        'transcode_reasons',
        'watched_sec',
        'runtime_sec',
        'is_finished',
    ];

    public const COLUMNS = [
        ...self::V1_COLUMNS,
        'watch_duration_sec',
        'started_at_epoch',
        'updated_at_epoch',
        'ended_at_epoch',
        'library_resolved_at_epoch',
    ];

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private ?PlayHistoryRepository $history = null,
        private ?\Closure $clock = null,
    ) {
    }

    /**
     * @param resource $stream
     */
    public function write(HistoryFilters $filters, $stream, ?\DateTimeImmutable $now = null): int
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('History CSV output must be a writable stream.');
        }

        $now ??= $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now');

        $this->writeBytes($stream, "\xEF\xBB\xBF");
        $this->writeRow($stream, self::COLUMNS);

        $count = 0;
        foreach (($this->history ?? new PlayHistoryRepository())->historyExportRows($filters, $now) as $row) {
            $this->writeRow($stream, $this->exportRow($row));
            $count++;
        }

        return $count;
    }

    /** @return array<int, string> */
    private function exportRow(\Dibi\Row $row): array
    {
        $values = [self::FORMAT_VERSION, date_default_timezone_get()];

        foreach (array_slice(self::COLUMNS, 2) as $column) {
            $value = $row[$column] ?? '';
            if (in_array($column, ['started_at', 'updated_at', 'ended_at', 'library_resolved_at'], true)) {
                $epoch = $row[$column . '_epoch'] ?? null;
                $value = $epoch !== null
                    ? (new \DateTimeImmutable('@' . (int) $epoch))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')
                    : $this->dateValue($value);
            }
            $values[] = $this->safeCell($value);
        }

        return $values;
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return trim((string) ($value ?? ''));
    }

    private function safeCell(mixed $value): string
    {
        $cell = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        // Spreadsheet apps treat these prefixes as formulas. Prefixing both
        // dangerous values and literal apostrophes makes this reversible for
        // the native importer without changing what spreadsheet users see.
        if (preg_match('/^[\'=+\-@\t\r]/u', $cell) === 1) {
            return "'" . $cell;
        }

        return $cell;
    }

    /**
     * @param resource $stream
     * @param array<int, string> $row
     */
    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '') === false) {
            throw new \RuntimeException('Could not write the History CSV file.');
        }
    }

    /** @param resource $stream */
    private function writeBytes($stream, string $bytes): void
    {
        if (fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Could not write the History CSV file.');
        }
    }
}
