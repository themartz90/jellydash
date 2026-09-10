<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\StatisticsPeriod;
use PHPUnit\Framework\TestCase;

final class PlaybackStatisticsServiceTest extends TestCase
{
    public function testStatisticsRangesNormalizeToAValidFallback(): void
    {
        $this->assertTrue(StatisticsPeriod::isValidRange('month'));
        $this->assertFalse(StatisticsPeriod::isValidRange('decade'));
        $this->assertSame('month', StatisticsPeriod::normalizeRange('month'));
        $this->assertSame('year', StatisticsPeriod::normalizeRange(null, 'year'));
        $this->assertSame('week', StatisticsPeriod::normalizeRange('decade', 'invalid'));
    }

    public function testYearTrendAlwaysBuildsTwelveCalendarMonthsAtMonthEnd(): void
    {
        $service = new PlaybackStatisticsService();
        $method = new ReflectionMethod($service, 'monthTrend');
        $trend = $method->invoke($service, [], new DateTimeImmutable('2026-03-31 12:00:00'));

        $this->assertIsArray($trend);
        $this->assertCount(12, $trend);
        $this->assertSame(
            ['Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar'],
            array_column($trend, 'label'),
        );
    }

    public function testTrendCaptionsDescribeRollingRanges(): void
    {
        $service = new PlaybackStatisticsService();
        $method = new ReflectionMethod($service, 'trendUnit');

        $this->assertSame('by day - last 7 days', $method->invoke($service, 'week'));
        $this->assertSame('by day - last 30 days', $method->invoke($service, 'month'));
        $this->assertSame('by month - last 12 months', $method->invoke($service, 'year'));
    }

    public function testPeriodBoundariesUseWholeCalendarBuckets(): void
    {
        $marchEnd = new DateTimeImmutable('2026-03-31 17:45:00');
        $this->assertSame('2026-03-25 00:00:00', StatisticsPeriod::currentStart('week', $marchEnd)?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02 00:00:00', StatisticsPeriod::currentStart('month', $marchEnd)?->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-01 00:00:00', StatisticsPeriod::currentStart('year', $marchEnd)?->format('Y-m-d H:i:s'));

        $leapDay = new DateTimeImmutable('2028-02-29 23:59:59');
        $this->assertSame('2028-01-31 00:00:00', StatisticsPeriod::currentStart('month', $leapDay)?->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-01 00:00:00', StatisticsPeriod::currentStart('year', $leapDay)?->format('Y-m-d H:i:s'));

        $previous = StatisticsPeriod::previous('year', $marchEnd);
        $this->assertNotNull($previous);
        $this->assertSame('2024-04-01 00:00:00', $previous['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-01 00:00:00', $previous['end']->format('Y-m-d H:i:s'));
    }

    public function testHistoryDrilldownsUseExactSelectedPeriodBounds(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $service = new PlaybackStatisticsService($repository);
        $now = new DateTimeImmutable('2026-03-31 17:45:00');

        $repository->logActiveStreams([[
            'id' => 'drilldown-row',
            'itemId' => 'drilldown-channel',
            'itemType' => 'TvChannel',
            'itemName' => 'Drilldown channel',
            'user' => 'Maya & Co',
            'client' => 'Client',
            'playMethod' => 'DirectPlay',
            'watchedSec' => 120,
            'runtimeSec' => 0,
        ], [
            'id' => 'drilldown-title-row',
            'itemId' => 'drilldown-title',
            'itemType' => 'Movie',
            'itemName' => 'Tosh.0 & Friends',
            'user' => 'Maya & Co',
            'client' => 'Client',
            'playMethod' => 'DirectPlay',
            'watchedSec' => 120,
            'runtimeSec' => 0,
        ]], $now);

        $stats = $service->data('month', $now);

        $this->assertSame('/history?range=custom&start=2026-03-02&end=2026-04-01', $stats['kpis'][1]['href']);
        $this->assertSame(
            '/history?user=Maya%20%26%20Co&range=custom&start=2026-03-02&end=2026-04-01',
            $stats['topUsers'][0]['href'],
        );
        $this->assertSame($stats['topUsers'][0]['href'], $stats['usersTable'][0]['href']);
        $this->assertSame(
            '/history?search=Tosh.0%20%26%20Friends&range=custom&start=2026-03-02&end=2026-04-01',
            $stats['trending'][0]['href'],
        );
        $this->assertSame(
            '/history?search=Tosh.0%20%26%20Friends&range=all',
            $stats['mostWatched']['movies'][0]['href'],
        );

        $all = $service->data('all', $now);
        $this->assertSame('/history?range=all', $all['kpis'][1]['href']);
        $this->assertSame('/history?user=Maya%20%26%20Co&range=all', $all['usersTable'][0]['href']);
        $this->assertSame(
            '/history?search=Tosh.0%20%26%20Friends&range=all',
            $all['trending'][0]['href'],
        );
    }

    public function testUnknownUserDoesNotLinkToAHistoryFilterThatCannotMatchIt(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $now = new DateTimeImmutable('2026-03-31 17:45:00');

        $repository->logActiveStreams([[
            'id' => 'unknown-user-row',
            'itemId' => 'unknown-user-channel',
            'itemType' => 'TvChannel',
            'itemName' => 'Unknown user channel',
            'user' => '',
            'client' => 'Client',
            'playMethod' => 'DirectPlay',
            'watchedSec' => 120,
            'runtimeSec' => 0,
        ]], $now);

        $stats = (new PlaybackStatisticsService($repository))->data('week', $now);

        $this->assertSame('Unknown user', $stats['usersTable'][0]['user']);
        $this->assertArrayNotHasKey('href', $stats['topUsers'][0]);
        $this->assertArrayNotHasKey('href', $stats['usersTable'][0]);
    }

    public function testRealUserNamedUnknownUserKeepsItsDrilldownLink(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $now = new DateTimeImmutable('2026-03-31 17:45:00');

        $repository->logActiveStreams([[
            'id' => 'named-unknown-user-row',
            'itemId' => 'named-unknown-user-channel',
            'itemType' => 'TvChannel',
            'itemName' => 'Named Unknown user channel',
            'user' => 'Unknown user',
            'client' => 'Client',
            'playMethod' => 'DirectPlay',
            'watchedSec' => 120,
            'runtimeSec' => 0,
        ]], $now);

        $stats = (new PlaybackStatisticsService($repository))->data('week', $now);

        $this->assertSame(
            '/history?user=Unknown%20user&range=custom&start=2026-03-25&end=2026-04-01',
            $stats['usersTable'][0]['href'],
        );
    }

    public function testAnonymousActivityAndNamedUnknownUserRemainSeparateGroups(): void
    {
        $service = new PlaybackStatisticsService();
        $users = new ReflectionMethod($service, 'users');
        $rows = [
            $this->statisticsRow('', 'Client', 60),
            $this->statisticsRow('Unknown user', 'Client', 180),
        ];

        $result = $users->invoke($service, $rows);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(['Unknown user', 'Unknown user'], array_column($result, 'user'));
        $this->assertSame(['75%', '25%'], array_column($result, 'share'));
        $this->assertTrue($result[0]['filterable']);
        $this->assertFalse($result[1]['filterable']);
    }

    public function testClientAndPlaybackMethodDrilldownsCarryTypedFiltersAndPeriod(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $now = new DateTimeImmutable('2026-03-31 17:45:00');

        foreach ([
            ['direct-play', 'DirectPlay'],
            ['direct-stream', 'DirectStream'],
            ['transcode', 'Transcode'],
            ['legacy-direct', 'LegacyMethod'],
        ] as [$id, $method]) {
            $repository->logActiveStreams([[
                'id' => 'client-method-' . $id,
                'itemId' => 'client-method-item-' . $id,
                'itemType' => 'Movie',
                'itemName' => 'Client method ' . $id,
                'user' => 'Client viewer',
                'client' => 'Client & One',
                'playMethod' => $method,
                'watchedSec' => 120,
                'runtimeSec' => 0,
            ]], $now);
        }

        $stats = (new PlaybackStatisticsService($repository))->data('month', $now);
        $period = '&range=custom&start=2026-03-02&end=2026-04-01';
        $client = '/history?client=Client%20%26%20One' . $period;

        $this->assertSame('/history?method=transcode' . $period, $stats['kpis'][3]['href']);
        $this->assertSame('/history?method=direct-play' . $period, $stats['directnessLegend'][0]['href']);
        $this->assertSame('/history?method=direct-stream' . $period, $stats['directnessLegend'][1]['href']);
        $this->assertSame('/history?method=transcode' . $period, $stats['directnessLegend'][2]['href']);
        $this->assertSame($client, $stats['clientBreakdown'][0]['href']);
        $this->assertSame($client, $stats['clientsRanked'][0]['href']);
        $this->assertSame($client, $stats['clientsUsage'][0]['href']);
        $this->assertSame($client, $stats['clientsTranscode'][0]['href']);
        $this->assertSame(
            '/history?client=Client%20%26%20One&method=direct' . $period,
            $stats['clientsTranscode'][0]['directHref'],
        );
        $this->assertSame(
            '/history?client=Client%20%26%20One&method=transcode' . $period,
            $stats['clientsTranscode'][0]['transcodeHref'],
        );
    }

    public function testUserAndClientWatchTimeKeepsExactSecondsUntilFormatting(): void
    {
        $service = new PlaybackStatisticsService();
        $users = new ReflectionMethod($service, 'users');
        $clients = new ReflectionMethod($service, 'clients');
        $rows = [
            $this->statisticsRow('Alice', 'Client A', 119),
            $this->statisticsRow('Alice', 'Client A', 119),
            $this->statisticsRow('Bob', 'Client B', 60),
        ];

        $userRows = $users->invoke($service, $rows);
        $this->assertIsArray($userRows);
        $this->assertSame('3m', $userRows[0]['watch']);
        $this->assertSame('80%', $userRows[0]['share']);
        $this->assertSame('1m', $userRows[1]['watch']);
        $this->assertSame('20%', $userRows[1]['share']);

        $clientRows = $clients->invoke($service, $rows);
        $this->assertIsArray($clientRows);
        $this->assertSame('3m', $clientRows['usage'][0]['watch']);
        $this->assertSame('1m', $clientRows['usage'][1]['watch']);
    }

    public function testUserAverageUsesExactTotalSeconds(): void
    {
        $service = new PlaybackStatisticsService();
        $users = new ReflectionMethod($service, 'users');
        $rows = [
            $this->statisticsRow('Alice', 'Client A', 59),
            $this->statisticsRow('Alice', 'Client A', 59),
        ];

        $userRows = $users->invoke($service, $rows);
        $this->assertIsArray($userRows);
        $this->assertSame('1m', $userRows[0]['watch']);
        $this->assertSame('1m', $userRows[0]['avg']);
    }

    public function testDirectnessAndClientPercentagesAlwaysReconcileToOneHundred(): void
    {
        $service = new PlaybackStatisticsService();
        $directness = new ReflectionMethod($service, 'directness');
        $clients = new ReflectionMethod($service, 'clients');
        $rows = [
            ...array_fill(0, 11, $this->statisticsRow('Viewer', 'Direct Play', 60, 'DirectPlay')),
            ...array_fill(0, 102, $this->statisticsRow('Viewer', 'Direct Stream', 60, 'DirectStream')),
            ...array_fill(0, 15, $this->statisticsRow('Viewer', 'Transcode', 60, 'Transcode')),
        ];

        $mix = $directness->invoke($service, $rows);
        $this->assertIsArray($mix);
        $this->assertSame(['8%', '80%', '12%'], array_column($mix['legend'], 'pct'));
        $this->assertSame(12, $mix['transcode_pct']);

        $clientMix = $clients->invoke($service, [
            $this->statisticsRow('Viewer', 'A', 60),
            $this->statisticsRow('Viewer', 'B', 60),
            $this->statisticsRow('Viewer', 'C', 60),
        ]);
        $this->assertIsArray($clientMix);
        $this->assertSame(100, array_sum(array_map(
            static fn (array $row): int => (int) rtrim($row['pct'], '%'),
            $clientMix['breakdown'],
        )));
        $this->assertStringContainsString('100.00%', $clientMix['conic']);

        $users = new ReflectionMethod($service, 'users');
        $userMix = $users->invoke($service, [
            $this->statisticsRow('A', 'Client', 60),
            $this->statisticsRow('B', 'Client', 60),
            $this->statisticsRow('C', 'Client', 60),
        ]);
        $this->assertIsArray($userMix);
        $this->assertSame(100, array_sum(array_map(
            static fn (array $row): int => (int) rtrim($row['share'], '%'),
            $userMix,
        )));
    }

    public function testStandaloneTranscodeRateUsesNormalRoundingWhileChartTotalsStayAtOneHundred(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $now = new DateTimeImmutable('2026-08-22 12:00:00');

        foreach ([...array_fill(0, 7, 'DirectPlay'), 'Transcode'] as $index => $method) {
            $repository->logActiveStreams([[
                'id' => 'rounding-' . $index,
                'itemId' => 'rounding-item-' . $index,
                'itemType' => 'TvChannel',
                'itemName' => 'Rounding fixture ' . $index,
                'user' => 'Viewer',
                'client' => 'Client',
                'playMethod' => $method,
                'watchedSec' => 60,
                'runtimeSec' => 0,
            ]], $now);
        }

        $stats = (new PlaybackStatisticsService($repository))->data('week', $now);

        $this->assertSame('13%', $stats['kpis'][3]['value']);
        $this->assertSame(['88%', '0%', '12%'], array_column($stats['directnessLegend'], 'pct'));
    }

    public function testTrendBarsRenderZeroHonestlyAndExposeTheirValues(): void
    {
        $service = new PlaybackStatisticsService();
        $trendBars = new ReflectionMethod($service, 'trendBars');
        $bars = $trendBars->invoke($service, [
            'empty' => ['label' => 'Empty', 'sec' => 0],
            'tiny' => ['label' => 'Tiny', 'sec' => 42],
            'small' => ['label' => 'Small', 'sec' => 60],
            'largest' => ['label' => 'Largest', 'sec' => 600],
        ]);

        $this->assertIsArray($bars);
        $this->assertSame('0%', $bars[0]['h']);
        $this->assertSame('0m', $bars[0]['value']);
        $this->assertSame('7%', $bars[1]['h']);
        $this->assertSame('<1m', $bars[1]['value']);
        $this->assertSame('10%', $bars[2]['h']);
        $this->assertSame('1m', $bars[2]['value']);
        $this->assertSame('100%', $bars[3]['h']);
        $this->assertSame('10m', $bars[3]['value']);
    }

    public function testEmptyPeriodUsesNeutralCopyAndZeroTrendBars(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $service = new PlaybackStatisticsService($repository);
        $stats = $service->data('week', new DateTimeImmutable('2026-08-22 12:00:00'));

        $this->assertSame('no activity this period', $stats['kpis'][0]['delta']);
        $this->assertSame('no activity this period', $stats['kpis'][1]['delta']);
        $this->assertSame(['0%', '0%', '0%', '0%', '0%', '0%', '0%'], array_column($stats['trend'], 'h'));
        $this->assertSame('conic-gradient(rgba(255,255,255,.08) 0% 100%)', $stats['directnessConic']);
        $this->assertSame('no session data', $stats['codecCoverage']);
        $this->assertSame('no transcodes', $stats['reasonCoverage']);
    }

    public function testLongDurationsKeepTheirRemainingMinutes(): void
    {
        $service = new PlaybackStatisticsService();
        $duration = new ReflectionMethod($service, 'duration');

        $this->assertSame('245h 34m', $duration->invoke($service, (245 * 3600) + (34 * 60) + 8));
    }

    public function testLongCategoryListsEndWithAnExplicitOtherBucket(): void
    {
        $service = new PlaybackStatisticsService();
        $bars = new ReflectionMethod($service, 'bars');
        $result = $bars->invoke($service, [
            'A' => 9,
            'B' => 8,
            'C' => 7,
            'D' => 6,
            'E' => 5,
            'F' => 4,
            'G' => 3,
            'H' => 2,
            'I' => 1,
        ], 'Other reasons');

        $this->assertIsArray($result);
        $this->assertCount(7, $result);
        $this->assertSame('Other reasons', $result[6]['name']);
        $this->assertSame(6, $result[6]['count']);
        $this->assertSame(100, array_sum(array_map(
            static fn (array $row): int => (int) rtrim($row['pct'], '%'),
            $result,
        )));
    }

    public function testStatisticsReportsMetadataCoverage(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $now = new DateTimeImmutable('2026-08-22 12:00:00');
        $repository->logActiveStreams([
            [
                'id' => 'covered',
                'itemId' => 'channel-covered',
                'itemType' => 'TvChannel',
                'itemName' => 'Covered channel',
                'user' => 'Viewer',
                'client' => 'Client',
                'playMethod' => 'Transcode',
                'watchedSec' => 120,
                'runtimeSec' => 0,
                'sourceVideoCodec' => 'H.264',
                'transcodeReasons' => ['Video codec not supported'],
            ],
            [
                'id' => 'missing',
                'itemId' => 'channel-missing',
                'itemType' => 'TvChannel',
                'itemName' => 'Missing channel',
                'user' => 'Viewer',
                'client' => 'Client',
                'playMethod' => 'Transcode',
                'watchedSec' => 120,
                'runtimeSec' => 0,
            ],
        ], $now);

        $stats = (new PlaybackStatisticsService($repository))->data('week', $now);

        $this->assertSame('1 of 2 sessions with codec data', $stats['codecCoverage']);
        $this->assertSame('1 of 2 transcodes with reason data', $stats['reasonCoverage']);
    }

    public function testConfirmedLibrariesAreFilteredWithoutJellyfinLookups(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryRows');
        $locationCalls = 0;
        $pathCalls = 0;

        $rows = $filter->invoke(
            $service,
            [
                $this->titleRow('2026-08-20 10:00:00', 'blocked', 'WRESTLING (CURRENT)', '2026-08-20 10:00:00'),
                $this->titleRow('2026-08-21 10:00:00', 'included', 'Movies', '2026-08-21 10:00:00'),
            ],
            ['wrestling (current)'],
            static function (array $ids) use (&$locationCalls): array {
                $locationCalls++;

                return [];
            },
        );

        $this->assertIsArray($rows);
        $this->assertSame(['included'], array_column($rows, 'item_id'));
        $this->assertSame(0, $locationCalls);
        $this->assertSame(0, $pathCalls);
    }

    public function testExcludedLatestRowCannotReplaceIncludedRepresentative(): void
    {
        $service = new PlaybackStatisticsService();
        $groupTitles = new ReflectionMethod($service, 'groupTitles');
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryRows');
        $rows = [
            $this->titleRow('2026-08-20 10:00:00', 'shared-item', 'Movies', '2026-08-20 10:00:00'),
            $this->titleRow('2026-08-21 10:00:00', 'shared-item', 'Blocked', '2026-08-21 10:00:00'),
        ];
        $filtered = $filter->invoke(
            $service,
            $rows,
            ['blocked'],
            static fn (array $ids): never => throw new RuntimeException('Confirmed rows must not load metadata.'),
        );
        $this->assertIsArray($filtered);
        $groups = $groupTitles->invoke($service, $filtered);
        $this->assertIsArray($groups);
        $this->assertSame(1, array_values($groups)[0]['plays']);
        $this->assertSame('Movies', array_values($groups)[0]['library']);
    }

    public function testMoviesWithTheSameTitleKeepDistinctItemIdentities(): void
    {
        $service = new PlaybackStatisticsService();
        $groupTitles = new ReflectionMethod($service, 'groupTitles');
        $groups = $groupTitles->invoke($service, [
            $this->titleRow('2026-08-20 10:00:00', 'crash-1996', 'Movies', '2026-08-20 10:00:00', 'Movie', '', 'Crash'),
            $this->titleRow('2026-08-21 10:00:00', 'crash-2004', 'Movies', '2026-08-21 10:00:00', 'Movie', '', 'Crash'),
        ]);

        $this->assertIsArray($groups);
        $this->assertCount(2, $groups);
        $this->assertEqualsCanonicalizing(['crash-1996', 'crash-2004'], array_column($groups, 'itemId'));
    }

    public function testSameNamedSeriesInConfirmedLibrariesStayDistinct(): void
    {
        $service = new PlaybackStatisticsService();
        $groupTitles = new ReflectionMethod($service, 'groupTitles');
        $groups = $groupTitles->invoke($service, [
            $this->titleRow('2026-08-20 10:00:00', 'episode-a', 'TV', '2026-08-20 10:00:00', 'Episode', 'Shared Show', 'Episode 1'),
            $this->titleRow('2026-08-21 10:00:00', 'episode-b', 'Kids TV', '2026-08-21 10:00:00', 'Episode', 'Shared Show', 'Episode 2'),
        ]);

        $this->assertIsArray($groups);
        $this->assertCount(2, $groups);
    }

    public function testExclusionsRemoveRowsBeforeSameTitleGroupsAreBuilt(): void
    {
        $service = new PlaybackStatisticsService();
        $filterRows = new ReflectionMethod($service, 'withoutExcludedLibraryRows');
        $groupTitles = new ReflectionMethod($service, 'groupTitles');
        $rows = [
            $this->titleRow('2026-08-20 10:00:00', 'included', 'Movies', '2026-08-20 10:00:00', 'Movie', '', 'Crash'),
            $this->titleRow('2026-08-21 10:00:00', 'included', 'Blocked', '2026-08-21 10:00:00', 'Movie', '', 'Crash'),
            $this->titleRow('2026-08-22 10:00:00', 'unmatched', '', '', 'Movie', '', 'Crash'),
        ];
        $pathCalls = [];
        $eligible = $filterRows->invoke(
            $service,
            $rows,
            ['blocked'],
            static function (array $itemIds) use (&$pathCalls): array {
                $pathCalls = $itemIds;

                return ['unmatched' => ['runtime_sec' => 3600, 'library' => 'Movies']];
            },
        );

        $this->assertIsArray($eligible);
        $this->assertSame(['included', 'unmatched'], array_column($eligible, 'item_id'));
        $this->assertSame(['unmatched'], $pathCalls);
        $groups = $groupTitles->invoke($service, $eligible);
        $this->assertIsArray($groups);
        $this->assertCount(2, $groups);
        $this->assertSame([1, 1], array_values(array_column($groups, 'plays')));
    }

    public function testUnresolvedLibrariesRetainPathFallbackAndDeletedItemHandling(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryRows');
        $locationCalls = 0;
        $pathCalls = [];
        $paths = [
            'excluded' => '/media/wrestling/show.mkv',
            'deleted' => '',
            'included' => '/media/movies/film.mkv',
        ];

        $rows = $filter->invoke(
            $service,
            [
                $this->titleRow('2026-08-20 10:00:00', 'excluded', '', ''),
                $this->titleRow('2026-08-20 10:01:00', 'deleted', '', ''),
                $this->titleRow('2026-08-20 10:02:00', 'included', '', ''),
            ],
            ['wrestling'],
            static function (array $itemIds) use (&$locationCalls, &$pathCalls, $paths): array {
                $locationCalls++;
                $pathCalls = $itemIds;

                return [
                    'excluded' => ['runtime_sec' => 3600, 'library' => 'wrestling'],
                    'included' => ['runtime_sec' => 3600, 'library' => 'movies'],
                ];
            },
        );

        $this->assertIsArray($rows);
        $this->assertSame(['included'], array_column($rows, 'item_id'));
        $this->assertSame(1, $locationCalls);
        $this->assertSame(['excluded', 'deleted', 'included'], $pathCalls);
    }

    public function testUnresolvedLibraryMetadataMatchesNormalizedGuidAndNumericKeys(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryRows');
        $rawGuid = 'AABBCCDD-1122-3344-5566-778899AABBCC';
        $undashedGuid = 'ffeeddccbbaa99887766554433221100';
        $numericId = '12345';
        $requested = [];

        $rows = $filter->invoke(
            $service,
            [
                $this->titleRow('2026-08-20 10:00:00', $rawGuid, '', ''),
                $this->titleRow('2026-08-20 10:01:00', $undashedGuid, '', ''),
                $this->titleRow('2026-08-20 10:02:00', $numericId, '', ''),
                $this->titleRow('2026-08-20 10:03:00', 'deleted-item', '', ''),
            ],
            ['blocked'],
            static function (array $ids) use (&$requested): array {
                $requested = $ids;

                return [
                    'aabbccdd112233445566778899aabbcc' => ['runtime_sec' => 3600, 'library' => 'Movies'],
                    'FFEEDDCC-BBAA-9988-7766-554433221100' => ['runtime_sec' => 3600, 'library' => 'Blocked'],
                    '12345' => ['runtime_sec' => 3600, 'library' => 'Movies'],
                ];
            },
        );

        $this->assertIsArray($rows);
        $this->assertSame([$rawGuid, $numericId], array_column($rows, 'item_id'));
        $this->assertSame([$rawGuid, $undashedGuid, $numericId, 'deleted-item'], $requested);
    }

    public function testUnresolvedLibraryLookupStillFailsOpen(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryRows');
        $pathCalls = 0;

        $rows = $filter->invoke(
            $service,
            [
                $this->titleRow('2026-08-20 10:00:00', 'first', '', ''),
                $this->titleRow('2026-08-20 10:01:00', 'second', '', ''),
            ],
            ['wrestling'],
            static fn (array $ids): never => throw new RuntimeException('Jellyfin unavailable'),
        );

        $this->assertIsArray($rows);
        $this->assertSame(['first', 'second'], array_column($rows, 'item_id'));
        $this->assertSame(0, $pathCalls);
    }

    private function titleRow(
        string $startedAt,
        string $itemId,
        string $library,
        string $resolvedAt,
        string $type = 'Movie',
        string $series = '',
        string $name = 'Shared title',
    ): \Dibi\Row {
        return new \Dibi\Row([
            'item_type' => $type,
            'series_name' => $series,
            'item_name' => $name,
            'watched_sec' => 60,
            'user_name' => 'Viewer',
            'started_at' => $startedAt,
            'item_id' => $itemId,
            'library' => $library,
            'library_resolved_at' => $resolvedAt,
        ]);
    }

    private function statisticsRow(
        string $user,
        string $client,
        int $watchedSec,
        string $playMethod = 'DirectPlay',
    ): \Dibi\Row {
        return new \Dibi\Row([
            'user_name' => $user,
            'user_id' => strtolower(str_replace(' ', '-', $user)),
            'client' => $client,
            'watched_sec' => $watchedSec,
            'play_method' => $playMethod,
        ]);
    }
}
