<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\HistorySampleQueue;
use Mk\Framework\Jellyfin\HistoryWriteBusyException;
use PHPUnit\Framework\TestCase;

final class HistorySampleQueueTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jellydash-sample-queue-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @unlink($this->directory . '/.lock');
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testNewInstanceReplaysSamplesInObservedOrderAndOnlyAcknowledgesSuccessfulWrites(): void
    {
        $queue = new HistorySampleQueue($this->directory);
        foreach ([30, 0, 60] as $second) {
            try {
                $queue->record(
                    [['id' => 'sample-' . $second]],
                    new DateTimeImmutable('@' . (1000 + $second)),
                    static function (): void {
                        throw new HistoryWriteBusyException();
                    }
                );
            } catch (HistoryWriteBusyException) {
            }
        }
        $recorded = [];
        try {
            (new HistorySampleQueue($this->directory))->record(
                [],
                new DateTimeImmutable('@2000'),
                static function (array $streams, DateTimeImmutable $observed) use (&$recorded): void {
                    if ($observed->getTimestamp() === 1030) {
                        throw new RuntimeException('Synthetic write failure.');
                    }
                    $recorded[] = [$streams[0]['id'], $observed->getTimestamp()];
                }
            );
            $this->fail('Expected the write failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic write failure.', $e->getMessage());
        }
        $this->assertSame([['sample-0', 1000]], $recorded);
        $this->assertCount(2, glob($this->directory . '/*.json'));
        (new HistorySampleQueue($this->directory))->record(
            [],
            new DateTimeImmutable('@2000'),
            static function (array $streams, DateTimeImmutable $observed) use (&$recorded): void {
                $recorded[] = [$streams[0]['id'], $observed->getTimestamp()];
            }
        );
        $this->assertSame([['sample-0', 1000], ['sample-30', 1030], ['sample-60', 1060]], $recorded);
        $this->assertSame([], glob($this->directory . '/*.json'));
    }

    public function testPendingStorageKeepsOnlyHistoryFields(): void
    {
        try {
            (new HistorySampleQueue($this->directory))->record([[
                'id' => 'sample', 'itemId' => 'movie', 'user' => 'Synthetic Viewer',
                'posterUrl' => 'https://private.invalid/image?api_key=synthetic-secret',
                'apiToken' => 'synthetic-secret',
            ]], new DateTimeImmutable('@1000'), static function (): void {
                throw new HistoryWriteBusyException();
            });
        } catch (HistoryWriteBusyException) {
        }
        $files = glob($this->directory . '/*.json');
        $this->assertCount(1, $files);
        $json = file_get_contents($files[0]);
        $this->assertStringNotContainsString('synthetic-secret', $json);
        $this->assertStringContainsString('Synthetic Viewer', $json);
        $this->assertSame([], glob($this->directory . '/*.tmp'));
    }

    public function testLargeBacklogIsDrainedInBoundedPasses(): void
    {
        $queue = new HistorySampleQueue($this->directory);
        for ($i = 0; $i < 51; $i++) {
            try {
                $queue->record(
                    [['id' => 'sample-' . $i]],
                    new DateTimeImmutable('@' . (1000 + $i)),
                    static function (): void {
                        throw new HistoryWriteBusyException();
                    }
                );
            } catch (HistoryWriteBusyException) {
            }
        }
        $recorded = 0;
        $write = static function () use (&$recorded): void {
            $recorded++;
        };
        try {
            $queue->record([], new DateTimeImmutable('@2000'), $write);
            $this->fail('A remaining backlog must stay visible to the caller.');
        } catch (HistoryWriteBusyException) {
            $this->assertSame(50, $recorded);
        }
        $queue->record([], new DateTimeImmutable('@2000'), $write);
        $this->assertSame(51, $recorded);
        $this->assertSame([], glob($this->directory . '/*.json'));
    }

    public function testSeparateInMemoryDatabasesCannotConsumeEachOthersSamples(): void
    {
        $first = \Mk\Framework\Database::sqlite(':memory:');
        $second = \Mk\Framework\Database::sqlite(':memory:');
        try {
            HistorySampleQueue::forDatabase($first)->record(
                [['id' => 'first-database']],
                new DateTimeImmutable('@1000'),
                static function (): void {
                    throw new HistoryWriteBusyException();
                }
            );
        } catch (HistoryWriteBusyException) {
        }
        $seen = [];
        $write = static function (array $streams) use (&$seen): void {
            $seen[] = $streams[0]['id'];
        };
        HistorySampleQueue::forDatabase($second)->record([], new DateTimeImmutable('@2000'), $write);
        $this->assertSame([], $seen);
        HistorySampleQueue::forDatabase($first)->record([], new DateTimeImmutable('@2000'), $write);
        $this->assertSame(['first-database'], $seen);
    }
}
