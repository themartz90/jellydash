<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final readonly class HistoryFilters
{
    public function __construct(
        public string $search = '',
        public string $user = '',
        public string $library = '',
        public string $client = '',
        public string $method = '',
        public string $range = '30',
        public int $limit = 100,
        public int $offset = 0,
        public ?\DateTimeImmutable $start = null,
        public ?\DateTimeImmutable $end = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $range = self::queryString($query, 'range', '30');
        if (!in_array($range, ['7', '30', 'all', 'custom'], true)) {
            $range = '30';
        }

        $method = self::queryString($query, 'method');
        if (!in_array($method, ['', 'direct', 'direct-play', 'direct-stream', 'transcode'], true)) {
            $method = '';
        }

        $start = null;
        $end = null;
        if ($range === 'custom') {
            $candidateStart = self::queryDate($query, 'start');
            $candidateEnd = self::queryDate($query, 'end');
            if ($candidateStart !== null && $candidateEnd !== null && $candidateStart < $candidateEnd) {
                $start = $candidateStart;
                $end = $candidateEnd;
            } else {
                $range = '30';
            }
        }

        return new self(
            search: trim(self::queryString($query, 'search')),
            user: trim(self::queryString($query, 'user')),
            library: trim(self::queryString($query, 'library')),
            client: self::queryString($query, 'client'),
            method: $method,
            range: $range,
            start: $start,
            end: $end,
        );
    }

    public function hasExactPeriod(): bool
    {
        return $this->range === 'custom'
            && $this->start !== null
            && $this->end !== null
            && $this->start < $this->end;
    }

    public function rangeDays(): ?int
    {
        return match ($this->range) {
            '7' => 7,
            '30' => 30,
            default => null,
        };
    }

    /** @return array<string, string> */
    public function queryParameters(): array
    {
        if ($this->hasExactPeriod()) {
            return array_filter([
                'search' => $this->search,
                'user' => $this->user,
                'library' => $this->library,
                'client' => $this->client,
                'method' => $this->method,
                'range' => 'custom',
                'start' => $this->start?->format('Y-m-d') ?? '',
                'end' => $this->end?->format('Y-m-d') ?? '',
            ], static fn (string $value): bool => $value !== '');
        }

        return array_filter([
            'search' => $this->search,
            'user' => $this->user,
            'library' => $this->library,
            'client' => $this->client,
            'method' => $this->method,
            'range' => $this->range !== '30' ? $this->range : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query, string $key, string $default = ''): string
    {
        $value = $query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function queryDate(array $query, string $key): ?\DateTimeImmutable
    {
        $value = trim(self::queryString($query, $key));
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }
}
