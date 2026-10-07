<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class ServerActivityService
{
    private ServerActivityClient $client;
    private ServerActivityCache $cache;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(?ServerActivityClient $client = null, ?ServerActivityCache $cache = null, ?callable $clock = null)
    {
        $this->client = $client ?? new ServerActivityClient();
        $this->cache = $cache ?? new ServerActivityCache(CACHE_DIR . '/server-activity');
        $this->clock = $clock !== null ? $clock(...) : static fn (): int => time();
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $source = $this->client->sourceKey();
        $server = $this->cache->remember($source . ':server', 10, function (): array {
            $raw = $this->client->get('/System/Info');
            $name = $this->text($raw['ServerName'] ?? null, 160);
            $version = $this->text($raw['Version'] ?? null, 80);
            if ($name === '' || $version === '') {
                throw new ServerActivityException();
            }
            return ['name' => $name, 'version' => $version, 'package' => $this->text($raw['PackageName'] ?? null, 80),
                'pending_restart' => is_bool($raw['HasPendingRestart'] ?? null) ? $raw['HasPendingRestart'] : null,
                'url' => $this->client->dashboardUrl()];
        });
        if ($server['state'] === 'forbidden') {
            $server = $this->cache->remember('public-' . $source . ':identity', 30, function (): array {
                $raw = $this->client->get('/System/Info/Public');
                $name = $this->text($raw['ServerName'] ?? null, 160);
                $version = $this->text($raw['Version'] ?? null, 80);
                if ($name === '' || $version === '') {
                    throw new ServerActivityException();
                }
                return ['name' => $name, 'version' => $version, 'package' => '', 'pending_restart' => null, 'url' => $this->client->dashboardUrl()];
            });
        }
        $tasks = $this->cache->remember($source . ':tasks', 10, fn (): array => $this->tasks($this->client->get('/ScheduledTasks')));
        if ($tasks['stale'] && is_array($tasks['data'])) {
            foreach ($tasks['data']['running'] as &$task) {
                $task['progress'] = null;
            }
            unset($task);
        }
        return ['server' => $server, 'tasks' => $tasks, 'timezone' => date_default_timezone_get()];
    }

    /** @return array<string, mixed> */
    public function activity(ServerActivityFilters $filters): array
    {
        $key = $this->client->sourceKey() . ':activity:' . $filters->since . ':' . $filters->actor . ':' . $filters->anchor;
        $snapshot = $this->cache->remember($key, 300, fn (): array => $this->events($filters), $filters->page > 1);
        if (!is_array($snapshot['data'])) {
            return $snapshot;
        }
        $data = $snapshot['data'];
        $types = [];
        $matches = [];
        foreach ($data['items'] as $item) {
            if ($item['timestamp'] < $filters->since || $item['timestamp'] >= $filters->until) {
                continue;
            }
            if ($item['type'] !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/D', $item['type'])) {
                $types[$item['type']] = true;
            }
            if (($filters->type === '' || $item['type'] === $filters->type)
                && ($filters->severity === '' || $item['severity'] === $filters->severity)
                && ($filters->actor === '' || $item['has_user'] === ($filters->actor === 'user'))) {
                $matches[] = $item;
            }
        }
        $types = array_keys($types);
        sort($types);
        $snapshot['data'] = ['items' => array_slice($matches, ($filters->page - 1) * 25, 25),
            'total' => count($matches), 'page' => $filters->page, 'pages' => max(1, (int) ceil(count($matches) / 25)),
            'anchor' => $filters->anchor, 'types' => $types, 'read_count' => count($data['items']),
            'source_total' => $data['source_total'], 'limited' => $data['limited'], 'timezone' => $filters->timezone];
        return $snapshot;
    }

    /** @param array<mixed> $raw @return array<string, mixed> */
    private function tasks(array $raw): array
    {
        if (!array_is_list($raw) || count($raw) > 200) {
            throw new ServerActivityException();
        }
        $running = $recent = [];
        $now = ($this->clock)();
        foreach ($raw as $row) {
            if (!is_array($row) || !is_string($row['Id'] ?? null) || !is_string($row['Name'] ?? null) || !is_string($row['State'] ?? null)) {
                throw new ServerActivityException();
            }
            $task = ['id' => $this->text($row['Id'], 128), 'name' => $this->text($row['Name'], 180), 'state' => $row['State'], 'progress' => null];
            if (in_array($row['State'], ['Running', 'Cancelling'], true)) {
                $progress = $row['CurrentProgressPercentage'] ?? null;
                $task['progress'] = (is_int($progress) || is_float($progress)) && is_finite((float) $progress) && $progress >= 0 && $progress <= 100 ? round((float) $progress, 1) : null;
                $running[] = $task;
            }
            $result = $row['LastExecutionResult'] ?? null;
            if (!is_array($result) || !is_string($result['Status'] ?? null)) {
                continue;
            }
            $end = $this->timestamp($result['EndTimeUtc'] ?? null);
            $start = $this->timestamp($result['StartTimeUtc'] ?? null);
            $success = $result['Status'] === 'Completed';
            if ($end === null || $end > $now || $end < $now - ($success ? 3600 : 86400)) {
                continue;
            }
            $recent[] = ['id' => $task['id'], 'name' => $task['name'], 'status' => in_array($result['Status'], ['Completed', 'Failed', 'Cancelled', 'Aborted'], true) ? $result['Status'] : 'Unknown',
                'ended_at' => $end, 'duration' => $start !== null && $start <= $end ? $end - $start : null];
        }
        usort($recent, static fn (array $a, array $b): int => $b['ended_at'] <=> $a['ended_at']);
        return ['running' => $running, 'recent' => $recent, 'total_tasks' => count($raw)];
    }

    /** @return array<string, mixed> */
    private function events(ServerActivityFilters $filters): array
    {
        $items = [];
        $total = 0;
        $deadline = microtime(true) + 5;
        $parameters = $filters->parameters();
        for ($page = 0; $page < 5; ++$page) {
            $parameters['StartIndex'] = $page * 200;
            $raw = $this->client->get('/System/ActivityLog/Entries?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), $deadline - microtime(true));
            if (!is_array($raw['Items'] ?? null) || !array_is_list($raw['Items']) || count($raw['Items']) > 200
                || !is_int($raw['TotalRecordCount'] ?? null) || $raw['TotalRecordCount'] < 0) {
                throw new ServerActivityException();
            }
            $total = $raw['TotalRecordCount'];
            foreach ($raw['Items'] as $row) {
                if (!is_array($row) || (!is_int($row['Id'] ?? null) && !is_string($row['Id'] ?? null))
                    || !preg_match('/^[0-9]{1,20}$/D', (string) $row['Id']) || !is_string($row['Name'] ?? null)) {
                    throw new ServerActivityException();
                }
                $timestamp = $this->timestamp($row['Date'] ?? null);
                if ($timestamp === null) {
                    throw new ServerActivityException();
                }
                $severity = $row['Severity'] ?? null;
                if (is_int($severity)) {
                    $severity = ['Trace', 'Debug', 'Information', 'Warning', 'Error', 'Critical', 'None'][$severity] ?? 'Unknown';
                }
                $items[(string) $row['Id']] = ['id' => (string) $row['Id'], 'name' => $this->text($row['Name'], 300),
                    'timestamp' => $timestamp, 'type' => $this->text($row['Type'] ?? null, 128),
                    'severity' => in_array($severity, ['Trace', 'Debug', 'Information', 'Warning', 'Error', 'Critical', 'None'], true) ? $severity : 'Unknown',
                    'has_user' => is_string($row['UserId'] ?? null) && preg_match('/[1-9a-f]/i', $row['UserId']) === 1];
            }
            if (count($raw['Items']) < 200 || ($page + 1) * 200 >= $total || microtime(true) >= $deadline) {
                break;
            }
        }
        $items = array_values($items);
        usort($items, static fn (array $a, array $b): int => ($b['timestamp'] <=> $a['timestamp']) ?: strnatcmp($b['id'], $a['id']));
        return ['items' => $items, 'source_total' => $total, 'limited' => count($items) < $total];
    }

    private function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $limit);
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }
            return $date->getTimestamp() > 0 ? $date->getTimestamp() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
