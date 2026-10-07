<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\ServerActivityFilters;
use PHPUnit\Framework\TestCase;

final class ServerActivityFiltersTest extends TestCase
{
    public function testRelativeWindowIsAnchoredAndPaginationRetainsIt(): void
    {
        $filters = ServerActivityFilters::fromQuery(['range' => 'day'], 1791374437, 'Europe/Prague');
        self::assertSame(1791374430, $filters->anchor);
        self::assertSame(1791288030, $filters->since);
        $next = ServerActivityFilters::fromQuery(['range' => 'day', 'anchor' => (string) $filters->anchor, 'page' => '2'], 1791374600, 'Europe/Prague');
        self::assertSame($filters->anchor, $next->anchor);
        self::assertSame(2, $next->page);
    }

    public function testCustomDatesIncludeWholeLocalDstDay(): void
    {
        $filters = ServerActivityFilters::fromQuery(['range' => 'custom', 'start' => '2026-03-29', 'end' => '2026-03-29'], 1791374430, 'Europe/Prague');
        self::assertSame('2026-03-28T23:00:00+00:00', gmdate('c', $filters->since));
        self::assertSame('2026-03-29T22:00:00+00:00', gmdate('c', $filters->until));
    }

    public function testInvalidScalarAndCalendarInputsAreRejected(): void
    {
        foreach ([['page' => []], ['range' => 'custom', 'start' => '2026-02-30', 'end' => '2026-03-01'], ['severity' => 'Oops'], ['actor' => 'admin'], ['page' => '2']] as $query) {
            try {
                ServerActivityFilters::fromQuery($query, 1791374430, 'Europe/Prague');
                self::fail('Invalid filters were accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
