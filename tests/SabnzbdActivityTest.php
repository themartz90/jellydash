<?php

declare(strict_types=1);

use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\Providers\SabnzbdProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\TestCase;

final class SabnzbdActivityTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $queue;
    private bool $historyFails = false;
    private bool $processingTooLarge = false;
    /** @var list<array<string,mixed>>|null */
    private ?array $processingRows = null;
    /** @var list<array<string,mixed>> */
    private array $historyRows = [];
    private SabnzbdProvider $provider;

    protected function setUp(): void
    {
        $this->queue = ['kbpersec' => '55296', 'paused' => false, 'slots' => [
            $this->job('first', '16'), $this->job('second', '87'),
        ]];
        $respond = function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return new HttpResponse(200, json_encode(['queue' => $this->queue + [
                    'noofslots' => count($this->queue['slots']),
                ]], JSON_THROW_ON_ERROR));
            }
            if (isset($query['status']) && $this->processingTooLarge) {
                throw new \Mk\Framework\Downloads\ProviderException('response_too_large');
            }
            $rows = isset($query['status']) ? ($this->processingRows ?? $this->historyRows) : $this->historyRows;
            return $this->historyFails ? new HttpResponse(503, '{}')
                : new HttpResponse(200, json_encode(['history' => [
                    'slots' => $rows, 'noofslots' => count($rows),
                ]], JSON_THROW_ON_ERROR));
        };
        $transport = new class ($respond) implements DownloaderTransport {
            public function __construct(private readonly Closure $respond)
            {
            }

            public function request(HttpRequest $request): HttpResponse
            {
                return ($this->respond)($request);
            }

            public function requestMany(array $requests): array
            {
                return array_map($this->request(...), $requests);
            }
        };
        $this->provider = new SabnzbdProvider($transport);
    }

    public function testFirstReadingDoesNotClaimThatEveryEligibleJobIsTransferring(): void
    {
        $batch = $this->collect();

        self::assertSame(['unknown', 'unknown'], array_column($batch->items, 'state'));
        self::assertSame([null, null], array_column($batch->items, 'speed'));
        self::assertSame(56623104.0, $batch->speed);
    }

    public function testOnlyTheJobWithObservedProgressIsDownloading(): void
    {
        $first = $this->collect();
        $this->queue['slots'][0]['mbleft'] = '10';

        $next = $this->collect($first->cursor);

        self::assertSame(['downloading', 'queued'], array_column($next->items, 'state'));
        self::assertSame([null, null], array_column($next->items, 'speed'));
        self::assertSame(13 * 1048576, $next->items[1]['downloaded']);
        self::assertSame(20, $next->items[0]['eta']);
        self::assertNull($next->items[1]['eta']);
        self::assertSame($first->cursor['history'], $next->cursor['history']);
    }

    public function testReorderingDoesNotHideConcurrentProgressOrInventActivityForANewJob(): void
    {
        $first = $this->collect();
        $this->queue['slots'] = [
            $this->job('second', '80'), $this->job('new', '99'), $this->job('first', '10'),
        ];

        $next = $this->collect($first->cursor);

        self::assertSame(['downloading', 'unknown', 'downloading'], array_column($next->items, 'state'));
    }

    public function testResetOrChangedSizeNeedsANewBaseline(): void
    {
        $first = $this->collect();
        $this->queue['slots'][0]['mbleft'] = '90';
        $this->queue['slots'][1]['mb'] = '110';
        $reset = $this->collect($first->cursor);
        self::assertSame(['unknown', 'unknown'], array_column($reset->items, 'state'));

        $this->queue['slots'][0]['mbleft'] = '80';
        $this->queue['slots'][1]['mbleft'] = '80';
        $next = $this->collect($reset->cursor);
        self::assertSame(['downloading', 'downloading'], array_column($next->items, 'state'));
    }

    public function testOldFutureAndMalformedSamplesDoNotClaimCurrentActivity(): void
    {
        $first = $this->collect();
        $this->queue['slots'][0]['mbleft'] = '10';
        foreach ([100, -100] as $age) {
            $next = $this->collect($first->cursor, $age);
            self::assertSame(['unknown', 'unknown'], array_column($next->items, 'state'));
        }
        $cursor = $first->cursor;
        $cursor['sab_activity'] = ['at' => 'invalid', 'jobs' => 'invalid'];
        self::assertSame(['unknown', 'unknown'], array_column($this->collect($cursor, null)->items, 'state'));
    }

    public function testMissingProgressAndZeroClientRateDoNotInventTransfers(): void
    {
        $first = $this->collect();
        unset($this->queue['slots'][0]['mbleft']);
        self::assertSame(['unknown', 'queued'], array_column($this->collect($first->cursor)->items, 'state'));

        $this->queue['kbpersec'] = '0';
        $this->queue['slots'][0]['mbleft'] = '10';
        self::assertSame(['queued', 'queued'], array_column($this->collect($first->cursor)->items, 'state'));
    }

    public function testPauseWinsOverEarlierProgressAndResumeNeedsANewSample(): void
    {
        $first = $this->collect();
        $this->queue['slots'][0]['mbleft'] = '10';
        $this->queue['slots'][0]['status'] = 'Paused';
        $paused = $this->collect($first->cursor);
        self::assertSame(['paused', 'queued'], array_column($paused->items, 'state'));

        $this->queue['slots'][0]['status'] = 'Downloading';
        self::assertSame(['unknown', 'queued'], array_column($this->collect($paused->cursor)->items, 'state'));
    }

    public function testGlobalPauseStillAllowsObservedForcePriorityTransfers(): void
    {
        $first = $this->collect();
        $this->queue['paused'] = true;
        $this->queue['slots'][0]['mbleft'] = '10';
        $this->queue['slots'][1]['mbleft'] = '80';
        $this->queue['slots'][1]['priority'] = 'Force';

        $next = $this->collect($first->cursor);

        self::assertSame(['paused', 'downloading'], array_column($next->items, 'state'));
        self::assertNull($next->items[0]['eta']);
    }

    public function testHistoryFailuresDoNotEraseQueueActivitySamples(): void
    {
        $first = $this->collect();
        $this->historyFails = true;
        $this->queue['slots'][0]['mbleft'] = '10';
        $next = $this->collect($first->cursor);
        self::assertSame(['downloading', 'queued'], array_column($next->items, 'state'));
        self::assertNotNull($next->history['error']);

        $this->historyFails = false;
        $this->queue['slots'][0]['mbleft'] = '5';
        $recovered = $this->collect($next->cursor);
        self::assertSame(['downloading', 'queued'], array_column($recovered->items, 'state'));
    }

    public function testHistoryFetchingWithoutQueueProgressDoesNotClaimAnActiveTransfer(): void
    {
        $this->queue['slots'] = [];
        $this->historyRows = [['nzo_id' => 'par2', 'name' => 'Repair data', 'status' => 'Fetching']];
        self::assertSame(['unknown'], array_column($this->collect()->items, 'state'));
    }

    public function testProcessingRowCapDoesNotBlockRecentHistoryButTransportOverflowIsReported(): void
    {
        $this->queue['slots'] = [];
        $this->historyRows = [['nzo_id' => 'recent', 'name' => 'Recent', 'status' => 'Completed', 'completed' => time() - 2]];
        $this->processingRows = array_map(static fn (int $id): array => [
            'nzo_id' => 'processing-' . $id, 'name' => 'Processing', 'status' => 'Repairing',
        ], range(1, 1000));
        $full = $this->collect();
        self::assertTrue($full->complete);
        self::assertCount(1000, $full->items);
        self::assertSame(['recent'], array_column($full->completions, 'source_id'));

        $this->processingRows[] = ['nzo_id' => 'extra', 'name' => 'Extra processing', 'status' => 'Repairing'];
        $this->queue['slots'] = [$this->job('queued-download', '90')];
        $partial = $this->collect();
        self::assertFalse($partial->complete);
        self::assertNull($partial->history['error']);
        self::assertSame('queued-download', $partial->items[0]['source_id']);
        self::assertSame(['recent'], array_column($partial->completions, 'source_id'));

        $this->processingTooLarge = true;
        $overflow = $this->collect();
        self::assertFalse($overflow->complete);
        self::assertSame('response_too_large', $overflow->history['error']);
        self::assertSame(['queued-download'], array_column($overflow->items, 'source_id'));
    }

    public function testRemovedJobsAreDroppedAndQueueSamplesFitTheStorageBound(): void
    {
        $this->queue['slots'] = [];
        for ($index = 0; $index < 1001; ++$index) {
            $this->queue['slots'][] = $this->job(sprintf('%04d', $index) . str_repeat('x', 252), '10');
        }
        $large = $this->collect();
        self::assertCount(1000, $large->items);
        self::assertFalse($large->complete);
        self::assertCount(1000, $large->cursor['sab_activity']['jobs']);
        self::assertLessThan(131072, strlen(json_encode($large->cursor, JSON_THROW_ON_ERROR)));

        $this->queue['slots'] = [$this->job('replacement', '90')];
        $next = $this->collect($large->cursor);
        self::assertSame([hash('sha256', 'replacement')], array_keys($next->cursor['sab_activity']['jobs']));
        self::assertSame('unknown', $next->items[0]['state']);
    }

    /** @return array<string,mixed> */
    private function job(string $id, string $left): array
    {
        return ['nzo_id' => $id, 'filename' => 'Sample download', 'status' => 'Downloading',
            'mb' => '100', 'mbleft' => $left, 'priority' => 'Normal', 'timeleft' => '0:00:20'];
    }

    /** @param array<string,mixed> $cursor */
    private function collect(array $cursor = [], ?int $age = 15): CollectionBatch
    {
        if ($cursor !== [] && $age !== null) {
            $cursor['sab_activity']['at'] = time() - $age;
        }
        return $this->provider->collect(
            new Connection('activity', 'sabnzbd', 'SABnzbd', 'https://sab.example.test'),
            ['secret' => 'fixture-key'],
            $cursor,
        );
    }
}
