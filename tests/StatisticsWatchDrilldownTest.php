<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatisticsWatchDrilldownTest extends TestCase
{
    public function testBothWatchTimeFiguresOpenTheContributingPeriod(): void
    {
        $database = Database::sqlite(':memory:');
        $repository = new PlayHistoryRepository($database);
        $service = new PlaybackStatisticsService($repository);
        $now = new DateTimeImmutable('2026-10-08 12:00:00');
        $repository->logActiveStreams([[
            'id' => 'watch-link', 'itemId' => 'watch-film', 'itemType' => 'Movie',
            'itemName' => 'Example film', 'user' => 'Viewer', 'watchedSec' => 120,
        ]], $now);
        $database->getDibi()->update('play_history', ['watch_duration_sec' => null])->execute();

        foreach (['week', 'month', 'year', 'all'] as $range) {
            $stats = $service->data($range, $now);
            self::assertArrayHasKey('href', $stats['kpis'][0]);
            self::assertSame($stats['kpis'][1]['href'], $stats['kpis'][0]['href']);
            self::assertSame($stats['kpis'][0]['href'], $stats['totalWatchHref']);
            parse_str((string) parse_url($stats['totalWatchHref'], PHP_URL_QUERY), $query);
            $filters = HistoryFilters::fromQuery($query);
            self::assertSame(1, $repository->historyTotal($filters, $now));
            self::assertSame(120, $repository->historyAggregate($filters, $now)['watch_sec']);
            $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(TEMPLATES_DIR));
            $html = $twig->load('statistics/index.twig')->renderBlock('dashboard_content', ['stats' => $stats]);
            $name = $stats['kpis'][0]['label'] . ': ' . $stats['kpis'][0]['value'];
            self::assertStringContainsString('aria-label="' . $name . '. View plays in History"', $html);
            self::assertStringContainsString('aria-label="' . $name . '. View contributing plays in History"', $html);
        }
    }

    #[DataProvider('bucketPeriods')]
    public function testBarsOpenOnlyTheirCalendarBucket(string $range, string $clock, string $start, string $end): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        try {
            $database = Database::sqlite(':memory:');
            $repository = new PlayHistoryRepository($database);
            $begin = new DateTimeImmutable($start);
            $finish = new DateTimeImmutable($end);
            foreach ([$begin->modify('-1 second'), $begin, $finish->modify('-1 second'), $finish] as $index => $at) {
                $repository->logActiveStreams([[
                    'id' => 'bucket-' . $index, 'itemId' => 'film-' . $index,
                    'itemType' => 'Movie', 'itemName' => 'Film', 'user' => 'Viewer',
                    'watchedSec' => 120,
                ]], $at);
            }
            $database->getDibi()->update('play_history', ['watch_duration_sec' => 120])->execute();
            $now = new DateTimeImmutable($clock);
            $stats = (new PlaybackStatisticsService($repository))->data($range, $now);
            $matching = [];
            foreach ($stats['trend'] as $bar) {
                self::assertArrayHasKey('href', $bar);
                parse_str((string) parse_url($bar['href'], PHP_URL_QUERY), $query);
                if ($query['start'] === $start) {
                    $matching[] = $bar;
                    self::assertSame($end, $query['end']);
                    $filters = HistoryFilters::fromQuery($query);
                    self::assertSame(2, $repository->historyTotal($filters, $now));
                    self::assertSame(240, $repository->historyAggregate($filters, $now)['watch_sec']);
                    self::assertSame('4m', $bar['value']);
                    self::assertNotEmpty($bar['periodLabel']);
                }
            }
            self::assertCount(1, $matching);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function bucketPeriods(): iterable
    {
        yield 'DST day' => ['week', '2026-03-29 12:00:00', '2026-03-29', '2026-03-30'];
        yield 'leap day' => ['month', '2024-03-02 12:00:00', '2024-02-29', '2024-03-01'];
        yield 'completed month' => ['year', '2024-02-17 12:00:00', '2024-01-01', '2024-02-01'];
        yield 'current partial month' => ['year', '2024-02-17 12:00:00', '2024-02-01', '2024-02-18'];
        yield 'all-time year' => ['all', '2026-10-08 12:00:00', '2024-01-01', '2025-01-01'];
    }
}
