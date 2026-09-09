<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\HistoryFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HistoryFiltersTest extends TestCase
{
    public function testCustomPeriodUsesTypedInclusiveStartAndExclusiveEnd(): void
    {
        $filters = HistoryFilters::fromQuery([
            'search' => ' Arrival ',
            'user' => 'Martin',
            'client' => 'Jellyfin Web',
            'method' => 'direct-stream',
            'range' => 'custom',
            'start' => '2026-03-02',
            'end' => '2026-04-01',
        ]);

        $this->assertTrue($filters->hasExactPeriod());
        $this->assertSame('custom', $filters->range);
        $this->assertSame('2026-03-02 00:00:00', $filters->start?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-01 00:00:00', $filters->end?->format('Y-m-d H:i:s'));
        $this->assertSame([
            'search' => 'Arrival',
            'user' => 'Martin',
            'client' => 'Jellyfin Web',
            'method' => 'direct-stream',
            'range' => 'custom',
            'start' => '2026-03-02',
            'end' => '2026-04-01',
        ], $filters->queryParameters());
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidCustomPeriodProvider')]
    public function testInvalidCustomPeriodFallsBackToTheExistingDefault(array $query): void
    {
        $filters = HistoryFilters::fromQuery($query);

        $this->assertFalse($filters->hasExactPeriod());
        $this->assertSame('30', $filters->range);
        $this->assertNull($filters->start);
        $this->assertNull($filters->end);
        $this->assertSame([], $filters->queryParameters());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCustomPeriodProvider(): iterable
    {
        yield 'missing end' => [['range' => 'custom', 'start' => '2026-03-02']];
        yield 'invalid calendar date' => [[
            'range' => 'custom',
            'start' => '2026-02-30',
            'end' => '2026-03-02',
        ]];
        yield 'equal bounds' => [[
            'range' => 'custom',
            'start' => '2026-03-02',
            'end' => '2026-03-02',
        ]];
        yield 'reversed bounds' => [[
            'range' => 'custom',
            'start' => '2026-04-01',
            'end' => '2026-03-02',
        ]];
        yield 'array input' => [[
            'range' => 'custom',
            'start' => ['2026-03-02'],
            'end' => '2026-04-01',
        ]];
    }

    public function testLegacyRangeIgnoresAndDoesNotPreserveCustomBounds(): void
    {
        $filters = HistoryFilters::fromQuery([
            'range' => '7',
            'start' => '2026-03-02',
            'end' => '2026-04-01',
        ]);

        $this->assertFalse($filters->hasExactPeriod());
        $this->assertNull($filters->start);
        $this->assertNull($filters->end);
        $this->assertSame(['range' => '7'], $filters->queryParameters());
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('methodProvider')]
    public function testClientAndSupportedMethodArePreserved(array $query, string $expectedMethod): void
    {
        $query['client'] = 'Jellyfin Web';
        $filters = HistoryFilters::fromQuery($query);

        $this->assertSame('Jellyfin Web', $filters->client);
        $this->assertSame($expectedMethod, $filters->method);
        $this->assertSame(array_filter([
            'client' => 'Jellyfin Web',
            'method' => $expectedMethod,
        ]), $filters->queryParameters());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function methodProvider(): iterable
    {
        yield 'all methods' => [[], ''];
        yield 'direct playback' => [['method' => 'direct'], 'direct'];
        yield 'direct play' => [['method' => 'direct-play'], 'direct-play'];
        yield 'direct stream' => [['method' => 'direct-stream'], 'direct-stream'];
        yield 'transcode' => [['method' => 'transcode'], 'transcode'];
        yield 'unsupported scalar' => [['method' => 'DirectPlay'], ''];
        yield 'array' => [['method' => ['transcode']], ''];
    }

    public function testClientMustBeScalarAndKeepsItsExactRawCase(): void
    {
        $this->assertSame('', HistoryFilters::fromQuery(['client' => ['Web']])->client);
        $this->assertSame(' web ', HistoryFilters::fromQuery(['client' => ' web '])->client);
    }
}
