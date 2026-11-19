<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads\Providers;

use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloaderHttpClient;
use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\HistoryRefresh;
use Mk\Framework\Downloads\Provider;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Integrations\Connection;

final class SabnzbdProvider implements Provider
{
    private const HISTORY_PAGE_SIZE = 20;
    private const HISTORY_MAX_ROWS = 100;
    private const HISTORY_TARGET = 20;
    private const CURRENT_HISTORY_LIMIT = 1001;
    private const CURRENT_HISTORY_STATUSES = 'Queued,QuickCheck,Verifying,Repairing,Fetching,Extracting,Moving,Running';
    private const MAX_BATCH_ROWS = 1000;
    private const ACTIVITY_MAX_AGE = 45;

    public function __construct(private readonly DownloaderTransport $transport = new DownloaderHttpClient())
    {
    }

    public function testConnection(Connection $connection, array $credentials): array
    {
        $requests = [
            $this->request($connection, $credentials, ['mode' => 'queue', 'start' => 0, 'limit' => 1]),
            $this->request($connection, $credentials, ['mode' => 'get_cats']),
        ];
        [$queueResponse, $categoriesResponse] = $this->transport->requestMany($requests);
        $queue = $this->json($queueResponse);
        $categories = $this->json($categoriesResponse);
        $queueData = $queue['queue'] ?? null;
        if (!is_array($queueData) || !is_string($queueData['version'] ?? null)) {
            throw new ProviderException('invalid_response');
        }

        $categoryRows = $categories['categories'] ?? null;
        if (!is_array($categoryRows)) {
            throw new ProviderException('invalid_response');
        }
        $values = [];
        foreach ($categoryRows as $category) {
            if (is_string($category)) {
                $values[] = $category === '*' ? '' : $this->identity($category);
            }
        }
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);
        $values = array_slice($values, 0, 250);

        return ['version' => $this->text($queueData['version'], 64), 'categories' => $values, 'tags' => []];
    }

    public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
    {
        $queue = $this->json($this->transport->request($this->request(
            $connection, $credentials, ['mode' => 'queue', 'start' => 0, 'limit' => self::MAX_BATCH_ROWS],
        )))['queue'] ?? null;
        if (!is_array($queue)) {
            throw new ProviderException('invalid_response');
        }

        $now = time();
        $speed = $this->number($queue['kbpersec'] ?? null, 0.0);
        $speed = $speed === null ? null : $speed * 1024;
        $activity = $cursor['sab_activity'] ?? null;
        $previous = is_array($activity) && is_int($activity['at'] ?? null)
            && $activity['at'] < $now && $activity['at'] >= $now - self::ACTIVITY_MAX_AGE
            && is_array($activity['jobs'] ?? null) ? $activity['jobs'] : [];
        $samples = [];
        $paused = ($queue['paused'] ?? false) === true || ($queue['status'] ?? null) === 'Paused';
        $items = [];
        $seenItems = [];
        $queueRows = $this->rows($queue['slots'] ?? null);
        foreach (array_slice($queueRows, 0, self::MAX_BATCH_ROWS) as $row) {
            $item = $this->queueItem($row);
            if ($item !== null && !isset($seenItems[$item['source_id']])) {
                $seenItems[$item['source_id']] = true;
                if ($item['state'] === 'downloading') {
                    if ($paused && ($row['priority'] ?? null) !== 'Force') {
                        $item['state'] = 'paused';
                    } else {
                        $key = hash('sha256', $item['source_id']);
                        $size = $item['size'];
                        $downloaded = $item['downloaded'];
                        if (is_int($size) && $size > 0 && is_int($downloaded)) {
                            $samples[$key] = [$size, $downloaded];
                        }
                        $item['state'] = $this->observedState($samples[$key] ?? null, $previous[$key] ?? null, $speed);
                    }
                }
                if ($item['state'] !== 'downloading') {
                    $item['eta'] = null;
                }
                $items[] = $item;
            }
        }

        $completions = [];
        $deadline = microtime(true) + 5.0;
        $currentHistoryComplete = false;
        $currentHistoryError = null;
        try {
            $currentRows = $this->currentHistory($connection, $credentials, $deadline);
            $currentHistoryComplete = count($currentRows) <= self::MAX_BATCH_ROWS;
            foreach (array_slice($currentRows, 0, self::MAX_BATCH_ROWS) as $row) {
                $item = $this->historyItem($row);
                if ($item !== null && !in_array($item['state'], ['completed', 'failed'], true)
                    && !isset($seenItems[$item['source_id']])) {
                    $seenItems[$item['source_id']] = true;
                    $items[] = $item;
                }
            }
        } catch (ProviderException $exception) {
            $currentHistoryComplete = false;
            $currentHistoryError = $exception->reason;
        }
        if ($currentHistoryError !== null) {
            $cursor = ['history' => HistoryRefresh::failure($cursor, $now, $currentHistoryError)];
        } else {
            if (HistoryRefresh::due($cursor, $now)) {
                try {
                    [$items, $completions, $historyError] = $this->collectHistory($connection, $credentials, $items, $seenItems, $deadline);
                    if ($historyError !== null) {
                        $cursor = ['history' => HistoryRefresh::failure($cursor, $now, $historyError)];
                    } else {
                        $cursor = ['history' => HistoryRefresh::success($cursor, $now)];
                    }
                } catch (ProviderException $exception) {
                    $cursor = ['history' => HistoryRefresh::failure($cursor, $now, $exception->reason)];
                }
            } else {
                $cursor = ['history' => $cursor['history']];
            }
        }

        $cursor['sab_activity'] = ['at' => $now, 'jobs' => $samples];
        $queueTotal = $this->integer($queue['noofslots'] ?? null, 0);
        $queueComplete = $queueTotal !== null && $queueTotal <= count($queueRows)
            && count($queueRows) <= self::MAX_BATCH_ROWS;
        $itemsTruncated = count($items) > self::MAX_BATCH_ROWS;
        $completions = array_slice($completions, 0, self::MAX_BATCH_ROWS);
        $items = array_slice($items, 0, self::MAX_BATCH_ROWS);

        return new CollectionBatch(
            $items,
            $completions,
            $speed,
            $cursor,
            $queueComplete && $currentHistoryComplete && !$itemsTruncated,
            history: $cursor['history'],
        );
    }

    /** @param array{int,int}|null $sample */
    private function observedState(?array $sample, mixed $previous, ?float $speed): string
    {
        // SAB labels eligible queue entries Downloading even while they wait.
        // Byte changes show recent progress, but cannot supply a per-job rate.
        if ($speed === 0.0) {
            return 'queued';
        }
        if ($sample === null || !is_array($previous) || count($previous) !== 2
            || ($previous[0] ?? null) !== $sample[0] || !is_int($previous[1] ?? null)
            || $previous[1] < 0 || $previous[1] > $sample[1]) {
            return 'unknown';
        }
        return $sample[1] > $previous[1] ? 'downloading' : 'queued';
    }

    /** @param list<array<string,mixed>> $items @param array<string,bool> $seenItems
     * @return array{list<array<string,mixed>>,list<array<string,mixed>>,?string}
     */
    private function collectHistory(Connection $connection, array $credentials, array $items, array $seenItems, float $deadline): array
    {
        $completions = [];
        $seenCompletions = [];
        $terminalFailures = [];
        $error = null;
        try {
            foreach ([0, 1] as $archive) {
                $matched = 0;
                for ($offset = 0; $offset < self::HISTORY_MAX_ROWS && $matched < self::HISTORY_TARGET; $offset += self::HISTORY_PAGE_SIZE) {
                    $rows = $this->historyPage($connection, $credentials, $archive, $offset, $deadline);
                    foreach ($rows as $row) {
                        $item = $this->historyItem($row);
                        if ($item === null) {
                            continue;
                        }
                        if ($item['state'] === 'completed' || $item['state'] === 'failed') {
                            if (!$connection->matches($item)) {
                                continue;
                            }
                            if ($item['state'] === 'failed') {
                                $terminalFailures[$item['source_id']] = true;
                                foreach ($items as $queued) {
                                    if ($queued['source_id'] === $item['source_id'] && $queued['state'] !== 'error') {
                                        continue 2;
                                    }
                                }
                            }
                            if (!isset($seenCompletions[$item['source_id']])) {
                                $seenCompletions[$item['source_id']] = true;
                                $completions[] = $item;
                                ++$matched;
                            }
                        } elseif (!isset($seenItems[$item['source_id']])) {
                            $seenItems[$item['source_id']] = true;
                            $items[] = $item;
                        }
                    }
                    if (count($rows) < self::HISTORY_PAGE_SIZE) {
                        break;
                    }
                }
            }
        } catch (ProviderException $exception) {
            $error = $exception->reason;
        }
        $items = array_values(array_filter($items, static fn (array $item): bool =>
            $item['state'] !== 'error' || !isset($terminalFailures[$item['source_id']])));
        return [$items, $completions, $error];
    }

    /** @param array{username:string,secret:string} $credentials @return list<array<string,mixed>> */
    private function currentHistory(Connection $connection, array $credentials, float $deadline): array
    {
        $remainingMs = $this->remainingHistoryMs($deadline);
        $history = $this->json($this->transport->request($this->request($connection, $credentials, [
            'mode' => 'history', 'archive' => 0, 'start' => 0,
            'limit' => self::CURRENT_HISTORY_LIMIT, 'status' => self::CURRENT_HISTORY_STATUSES,
        ], $remainingMs)))['history'] ?? null;
        if (microtime(true) >= $deadline) {
            throw new ProviderException('timeout');
        }
        if (!is_array($history)) {
            throw new ProviderException('invalid_response');
        }
        return array_slice($this->rows($history['slots'] ?? []), 0, self::CURRENT_HISTORY_LIMIT);
    }

    /** @param array{username:string,secret:string} $credentials @return list<array<string,mixed>> */
    private function historyPage(Connection $connection, array $credentials, int $archive, int $offset, float $deadline): array
    {
        $remainingMs = $this->remainingHistoryMs($deadline);
        $history = $this->json($this->transport->request($this->historyRequest(
            $connection, $credentials, $archive, $offset, $remainingMs,
        )))['history'] ?? null;
        if (microtime(true) >= $deadline) {
            throw new ProviderException('timeout');
        }
        if (!is_array($history)) {
            throw new ProviderException('invalid_response');
        }
        return array_slice($this->rows($history['slots'] ?? []), 0, self::HISTORY_PAGE_SIZE);
    }

    private function remainingHistoryMs(float $deadline): int
    {
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            throw new ProviderException('timeout');
        }
        return min(5000, $remainingMs);
    }

    /** @param array{username:string,secret:string} $credentials @param array<string,int|string> $query */
    private function request(Connection $connection, array $credentials, array $query, int $timeoutMs = 5000): HttpRequest
    {
        $base = $this->baseUrl($connection->url);
        $query = ['output' => 'json', 'apikey' => $credentials['secret']] + $query;
        return new HttpRequest('GET', $base . '/api?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            verifyTls: $connection->verifyTls, timeoutMs: $timeoutMs);
    }

    /** @param array{username:string,secret:string} $credentials */
    private function historyRequest(Connection $connection, array $credentials, int $archive, int $start, int $timeoutMs): HttpRequest
    {
        $query = ['mode' => 'history', 'archive' => $archive, 'start' => $start, 'limit' => self::HISTORY_PAGE_SIZE];
        return $this->request($connection, $credentials, $query, $timeoutMs);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        if (in_array($response->status, [401, 403], true)) {
            throw new ProviderException('authentication_failed');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ProviderException();
        }
        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('invalid_response');
        }
        if (!is_array($data)) {
            throw new ProviderException('invalid_response');
        }
        $error = $data['error'] ?? null;
        if (is_string($error)) {
            if (stripos($error, 'api key') !== false) {
                throw new ProviderException('authentication_failed');
            }
            throw new ProviderException('invalid_response');
        }
        return $data;
    }

    private function baseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new ProviderException('invalid_response');
        }
        return $url;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new ProviderException('invalid_response');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ProviderException('invalid_response');
            }
            $result[] = $row;
        }
        return $result;
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function queueItem(array $row): ?array
    {
        $id = $this->id($row['nzo_id'] ?? null);
        if ($id === null) {
            return null;
        }
        $size = $this->megabytes($row['mb'] ?? null);
        $left = $this->megabytes($row['mbleft'] ?? null);
        $downloaded = $size !== null && $left !== null && $left <= $size ? $size - $left : null;
        $state = $this->sabState($row['status'] ?? null);
        return $this->item(
            $id,
            $row['filename'] ?? $row['name'] ?? '',
            $row['cat'] ?? $row['category'] ?? '',
            $state === 'failed' ? 'error' : $state,
            $this->number($row['percentage'] ?? null, 0.0, 100.0),
            $size,
            $downloaded,
            null,
            $this->duration($row['timeleft'] ?? null),
            $this->integer($row['index'] ?? null, 0),
            null,
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function historyItem(array $row): ?array
    {
        $id = $this->id($row['nzo_id'] ?? null);
        if ($id === null) {
            return null;
        }
        $state = $this->sabState($row['status'] ?? null);
        if ($state === 'downloading') {
            $state = 'unknown';
        }
        $completedAt = in_array($state, ['completed', 'failed'], true)
            ? $this->integer($row['completed'] ?? null, 1) : null;
        $downloaded = $this->integer($row['downloaded'] ?? null, 0);
        return $this->item(
            $id,
            $row['name'] ?? $row['nzb_name'] ?? '',
            $row['category'] ?? $row['cat'] ?? '',
            $state,
            $state === 'completed' ? 100.0 : null,
            $state === 'completed' ? $downloaded : null,
            $downloaded,
            null,
            null,
            null,
            $completedAt,
        );
    }

    /** @return array<string,mixed> */
    private function item(string $id, mixed $title, mixed $category, string $state, ?float $progress, ?int $size, ?int $downloaded, ?float $speed, ?int $eta, ?int $position, ?int $completedAt): array
    {
        return [
            'source_id' => $id,
            'title' => $this->text(is_string($title) ? $title : '', 1024),
            'category' => $this->identity(is_string($category) && $category !== '*' ? $category : ''),
            'tags' => [], 'state' => $state, 'progress' => $progress,
            'size' => $size, 'downloaded' => $downloaded, 'speed' => $speed,
            'eta' => $eta, 'position' => $position, 'completed_at' => $completedAt,
        ];
    }

    private function sabState(mixed $state): string
    {
        $state = strtolower(is_string($state) ? $state : '');
        return match ($state) {
            'downloading', 'fetching' => 'downloading',
            'paused' => 'paused',
            'queued', 'propagating' => 'queued',
            'quickcheck', 'verifying' => 'checking',
            'repairing', 'extracting', 'moving', 'running' => 'processing',
            'failed' => 'failed',
            'completed' => 'completed',
            default => 'error',
        };
    }

    private function id(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return $this->text(trim($value), 256);
    }

    private function text(string $value, int $limit): string
    {
        if (preg_match('//u', $value) !== 1) {
            return '';
        }
        if (strlen($value) <= $limit) {
            return $value;
        }
        $value = substr($value, 0, $limit);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function identity(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 256
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProviderException('invalid_response');
        }

        return $value;
    }

    private function number(mixed $value, ?float $minimum = null, ?float $maximum = null): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number) || ($minimum !== null && $number < $minimum) || ($maximum !== null && $number > $maximum)) {
            return null;
        }
        return $number;
    }

    private function integer(mixed $value, ?int $minimum = null): ?int
    {
        $number = $this->number($value, $minimum === null ? null : (float) $minimum, (float) PHP_INT_MAX);
        return $number === null ? null : (int) $number;
    }

    private function megabytes(mixed $value): ?int
    {
        $number = $this->number($value, 0.0, PHP_INT_MAX / 1048576);
        return $number === null ? null : (int) round($number * 1048576);
    }

    private function duration(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^(\d+):(\d{2}):(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }
        if ((int) $parts[2] > 59 || (int) $parts[3] > 59) {
            return null;
        }
        $seconds = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) $parts[3];
        return $seconds <= 31536000 ? $seconds : null;
    }
}
