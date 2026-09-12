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

    public function testCompleteItemAndSeriesScopesAreTypedAndPreserved(): void
    {
        $item = HistoryFilters::fromQuery([
            'media_type' => 'item',
            'media_id' => 'crash-1996',
            'media_item_type' => 'Movie',
            'media_title' => 'Crash',
            'media_library' => 'Ignored for items',
            'range' => 'all',
        ]);
        $this->assertTrue($item->hasMediaScope());
        $this->assertSame('item', $item->mediaType);
        $this->assertSame('', $item->mediaLibrary);
        $this->assertSame([
            'media_type' => 'item',
            'media_id' => 'crash-1996',
            'media_item_type' => 'Movie',
            'media_title' => 'Crash',
            'range' => 'all',
        ], $item->queryParameters());

        $series = HistoryFilters::fromQuery([
            'media_type' => 'series',
            'media_id' => 'ignored-for-series',
            'media_item_type' => 'Movie',
            'media_title' => 'Shared Show',
            'media_library' => 'Kids TV',
        ]);
        $this->assertTrue($series->hasMediaScope());
        $this->assertSame('', $series->mediaId);
        $this->assertSame('', $series->mediaItemType);
        $this->assertSame([
            'media_type' => 'series',
            'media_title' => 'Shared Show',
            'media_library' => 'Kids TV',
        ], $series->queryParameters());
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidMediaScopeProvider')]
    public function testPartialOrUnsupportedMediaScopeIsDiscarded(array $query): void
    {
        $filters = HistoryFilters::fromQuery($query + ['search' => 'Crash', 'range' => 'all']);

        $this->assertFalse($filters->hasMediaScope());
        $this->assertSame('', $filters->mediaType);
        $this->assertSame('', $filters->mediaId);
        $this->assertSame('', $filters->mediaItemType);
        $this->assertSame('', $filters->mediaTitle);
        $this->assertSame('', $filters->mediaLibrary);
        $this->assertSame(['search' => 'Crash', 'range' => 'all'], $filters->queryParameters());
    }

    public function testManuallyConstructedPartialMediaScopeIsNotSerialized(): void
    {
        $filters = new HistoryFilters(
            range: 'all',
            mediaType: 'series',
            mediaTitle: 'Shared Show',
        );

        $this->assertFalse($filters->hasMediaScope());
        $this->assertSame(['range' => 'all'], $filters->queryParameters());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidMediaScopeProvider(): iterable
    {
        yield 'item without id' => [[
            'media_type' => 'item',
            'media_item_type' => 'Movie',
            'media_title' => 'Crash',
        ]];
        yield 'item without type' => [[
            'media_type' => 'item',
            'media_id' => 'crash-1996',
            'media_title' => 'Crash',
        ]];
        yield 'item without display title' => [[
            'media_type' => 'item',
            'media_id' => 'crash-1996',
            'media_item_type' => 'Movie',
        ]];
        yield 'series without confirmed library' => [[
            'media_type' => 'series',
            'media_title' => 'Shared Show',
        ]];
        yield 'unsupported type' => [[
            'media_type' => 'movie',
            'media_id' => 'crash-1996',
            'media_item_type' => 'Movie',
            'media_title' => 'Crash',
        ]];
        yield 'array input' => [[
            'media_type' => 'item',
            'media_id' => ['crash-1996'],
            'media_item_type' => 'Movie',
            'media_title' => 'Crash',
        ]];
    }
}
