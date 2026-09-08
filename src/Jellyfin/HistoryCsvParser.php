<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Converts the documented Jellydash History CSV format into database rows. */
final class HistoryCsvParser
{
    /** @var array<string, int> */
    private const MAX_LENGTHS = [
        'session_key' => 128,
        'user_id' => 64,
        'user_name' => 128,
        'item_id' => 64,
        'item_type' => 16,
        'series_name' => 255,
        'item_name' => 255,
        'season_ep' => 32,
        'library' => 64,
        'play_method' => 32,
        'play_method_detail' => 64,
        'client' => 64,
        'device' => 64,
        'source_video_codec' => 64,
        'source_audio_codec' => 64,
        'source_container' => 64,
        'target_video_codec' => 64,
        'target_audio_codec' => 64,
        'target_container' => 64,
    ];

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateFile(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the Jellydash History CSV.');
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) {
                throw new \InvalidArgumentException('The CSV does not contain a header row.');
            }
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            }
            if ($header !== HistoryCsvExporter::COLUMNS && $header !== HistoryCsvExporter::V1_COLUMNS) {
                throw new \InvalidArgumentException('This is not a supported Jellydash History CSV.');
            }

            $line = 1;
            $seen = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($this->isEmptyRow($values)) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new \InvalidArgumentException('CSV row ' . $line . ' has the wrong number of columns.');
                }

                $record = array_combine($header, array_map(static fn (mixed $value): string => (string) $value, $values));
                $row = $this->mapRow($record, $line, $header === HistoryCsvExporter::V1_COLUMNS ? '1' : '2');
                $identity = (string) $row['session_key'] . "\0" . (string) $row['item_id'];
                if (isset($seen[$identity])) {
                    throw new \InvalidArgumentException('CSV row ' . $line . ' duplicates an earlier play.');
                }
                $seen[$identity] = true;

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<mixed> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $record
     * @return array<string, mixed>
     */
    private function mapRow(array $record, int $line, string $version): array
    {
        if (($record['jellydash_history_version'] ?? '') !== $version) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' uses an unsupported History format version.');
        }

        $timezoneName = trim($record['jellydash_timezone'] ?? '');
        try {
            $sourceTimezone = new \DateTimeZone($timezoneName);
        } catch (\Exception) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid timezone.');
        }
        $targetTimezone = new \DateTimeZone(date_default_timezone_get());

        $row = [];
        foreach (self::MAX_LENGTHS as $column => $maxLength) {
            $value = $this->restoreCell($record[$column] ?? '');
            if (mb_strlen($value) > $maxLength) {
                throw new \InvalidArgumentException('CSV row ' . $line . ' has an overlong ' . $column . ' value.');
            }
            $row[$column] = $value === '' && !in_array($column, ['session_key', 'item_id', 'item_type', 'play_method'], true)
                ? null
                : $value;
        }

        if ($row['session_key'] === '' || $row['item_id'] === '') {
            throw new \InvalidArgumentException('CSV row ' . $line . ' is missing its play identity.');
        }

        foreach (['started_at', 'updated_at', 'ended_at', 'library_resolved_at'] as $column) {
            $epoch = $this->epoch($record[$column . '_epoch'] ?? '', $line, $column);
            $row[$column] = $this->date($record[$column] ?? '', $sourceTimezone, $targetTimezone, $line, $column, !in_array($column, ['started_at', 'updated_at'], true), $epoch);
            $row[$column . '_epoch'] = $epoch;
        }

        foreach (['watched_sec', 'runtime_sec'] as $column) {
            $row[$column] = $this->unsignedInteger($record[$column] ?? '', $line, $column);
        }
        $duration = $record['watch_duration_sec'] ?? '';
        $row['watch_duration_sec'] = $duration === '' ? null : $this->unsignedInteger($duration, $line, 'watch_duration_sec');
        $row['is_video_direct'] = $this->nullableBoolean($record['is_video_direct'] ?? '', $line, 'is_video_direct');
        $row['is_audio_direct'] = $this->nullableBoolean($record['is_audio_direct'] ?? '', $line, 'is_audio_direct');
        $row['is_finished'] = $this->requiredBoolean($record['is_finished'] ?? '', $line, 'is_finished');
        $row['transcode_reasons'] = $this->reasons($record['transcode_reasons'] ?? '', $line);
        $row['notified'] = 1;

        return $row;
    }

    private function restoreCell(string $value): string
    {
        return str_starts_with($value, "'") ? substr($value, 1) : $value;
    }

    private function date(
        string $value,
        \DateTimeZone $source,
        \DateTimeZone $target,
        int $line,
        string $column,
        bool $nullable,
        ?int $epoch = null,
    ): ?string {
        $value = $this->restoreCell(trim($value));
        if ($value === '' && $nullable) {
            if ($epoch !== null) {
                throw new \InvalidArgumentException('CSV row ' . $line . ' has an epoch without its ' . $column . ' value.');
            }
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $source);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . ' value.');
        }

        if ($epoch !== null) {
            $date = (new \DateTimeImmutable('@' . $epoch))->setTimezone($source);
            if ($date->format('Y-m-d H:i:s') !== $value) {
                throw new \InvalidArgumentException('CSV row ' . $line . ' has an epoch that does not match ' . $column . '.');
            }
        } elseif ($source->getName() !== $target->getName() && $this->ambiguousLocalTime($value, $source)) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an ambiguous ' . $column . ' during a clock change. Restore using the source timezone; this older timestamp has no UTC offset.');
        }

        return $date->setTimezone($target)->format('Y-m-d H:i:s');
    }

    private function epoch(string $value, int $line, string $column): ?int
    {
        $value = $this->restoreCell(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/D', $value) !== 1 || (float) $value < -62135596800 || (float) $value > 253402300799) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . '_epoch value.');
        }
        return (int) $value;
    }

    private function ambiguousLocalTime(string $value, \DateTimeZone $zone): bool
    {
        $wall = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        $offsets = [];
        foreach ($zone->getTransitions($wall - 172800, $wall + 172800) ?: [] as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
        $matches = 0;
        foreach (array_keys($offsets) as $offset) {
            if ((new \DateTimeImmutable('@' . ($wall - $offset)))->setTimezone($zone)->format('Y-m-d H:i:s') === $value) {
                ++$matches;
            }
        }
        return $matches > 1;
    }

    private function unsignedInteger(string $value, int $line, string $column): int
    {
        $value = $this->restoreCell(trim($value));
        if (preg_match('/^\d+$/', $value) !== 1 || (float) $value > 2147483647) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . ' value.');
        }

        return (int) $value;
    }

    private function nullableBoolean(string $value, int $line, string $column): ?int
    {
        $value = $this->restoreCell(trim($value));
        if ($value === '') {
            return null;
        }

        return $this->requiredBoolean($value, $line, $column);
    }

    private function requiredBoolean(string $value, int $line, string $column): int
    {
        $value = $this->restoreCell(trim($value));
        if (!in_array($value, ['0', '1'], true)) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . ' value.');
        }

        return (int) $value;
    }

    private function reasons(string $value, int $line): ?string
    {
        $value = $this->restoreCell($value);
        if ($value === '') {
            return null;
        }

        try {
            $reasons = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has invalid transcode reasons.');
        }
        if (!is_array($reasons) || !array_is_list($reasons)) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has invalid transcode reasons.');
        }
        foreach ($reasons as $reason) {
            if (!is_string($reason)) {
                throw new \InvalidArgumentException('CSV row ' . $line . ' has invalid transcode reasons.');
            }
        }

        return json_encode($reasons, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
