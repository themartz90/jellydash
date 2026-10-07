<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class ServerActivityFilters
{
    private function __construct(
        public readonly string $range,
        public readonly int $anchor,
        public readonly int $since,
        public readonly int $until,
        public readonly int $page,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $actor,
        public readonly string $timezone,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, ?int $now = null, ?string $timezone = null): self
    {
        $now ??= time();
        $timezone ??= date_default_timezone_get();
        $values = ['range' => self::value($query, 'range'), 'anchor' => self::value($query, 'anchor'),
            'page' => self::value($query, 'page'), 'type' => self::value($query, 'type'),
            'severity' => self::value($query, 'severity'), 'actor' => self::value($query, 'actor'),
            'start' => self::value($query, 'start'), 'end' => self::value($query, 'end')];
        $range = $values['range'] !== '' ? $values['range'] : 'day';
        $page = $values['page'] !== '' ? $values['page'] : '1';
        $anchor = $values['anchor'] !== '' ? $values['anchor'] : (string) (intdiv($now, 30) * 30);
        if (!in_array($range, ['day', 'week', 'month', 'all', 'custom'], true)
            || !ctype_digit($page) || (int) $page < 1 || (int) $page > 40
            || !ctype_digit($anchor) || (int) $anchor < 1 || (int) $anchor > $now
            || ((int) $page > 1 && $values['anchor'] === '')
            || !in_array($values['severity'], ['', 'Trace', 'Debug', 'Information', 'Warning', 'Error', 'Critical', 'None'], true)
            || !in_array($values['actor'], ['', 'user', 'system'], true)
            || ($values['type'] !== '' && !preg_match('/^[A-Za-z0-9_.:-]+$/D', $values['type']))) {
            throw new \InvalidArgumentException('Invalid activity filters.');
        }
        $since = match ($range) {
            'day' => (int) $anchor - 86400,
            'week' => (int) $anchor - 604800,
            'month' => (int) $anchor - 2592000,
            default => 0,
        };
        $until = (int) $anchor + 1;
        if ($range === 'custom') {
            $zone = new \DateTimeZone($timezone);
            $dates = [];
            foreach (['start', 'end'] as $key) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $values[$key], $zone);
                if ($date === false || $date->format('Y-m-d') !== $values[$key]) {
                    throw new \InvalidArgumentException('Choose valid start and end dates.');
                }
                $dates[$key] = $date;
            }
            if ($dates['start'] > $dates['end']) {
                throw new \InvalidArgumentException('The end date must be on or after the start date.');
            }
            $since = $dates['start']->getTimestamp();
            $until = min($until, $dates['end']->modify('+1 day')->getTimestamp());
        }
        return new self($range, (int) $anchor, $since, $until, (int) $page, $values['type'], $values['severity'], $values['actor'], $timezone);
    }

    /** @return array<string, int|string> */
    public function parameters(): array
    {
        $parameters = ['StartIndex' => 0, 'Limit' => 200];
        if ($this->since > 0) {
            $parameters['MinDate'] = gmdate('Y-m-d\TH:i:s\Z', $this->since);
        }
        if ($this->actor !== '') {
            $parameters['HasUserId'] = $this->actor === 'user' ? 'true' : 'false';
        }
        return $parameters;
    }

    /** @param array<string, mixed> $query */
    private static function value(array $query, string $key): string
    {
        $value = $query[$key] ?? '';
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Invalid activity filters.');
        }
        $value = trim((string) $value);
        if (strlen($value) > 128) {
            throw new \InvalidArgumentException('An activity filter is too long.');
        }
        return $value;
    }
}
