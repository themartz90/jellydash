<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\MonthlyRecapService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackClassifier;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class MonthlyRecapServiceTest extends TestCase
{
    private string $previousTimezone;
    private string|false $previousExcludedLibraries;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        $this->previousExcludedLibraries = getenv('TRENDING_EXCLUDE_LIBRARIES');
        date_default_timezone_set('Europe/Prague');
        putenv('TRENDING_EXCLUDE_LIBRARIES=');
        $_ENV['TRENDING_EXCLUDE_LIBRARIES'] = '';
        $_SERVER['TRENDING_EXCLUDE_LIBRARIES'] = '';
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        if ($this->previousExcludedLibraries === false) {
            putenv('TRENDING_EXCLUDE_LIBRARIES');
            unset($_ENV['TRENDING_EXCLUDE_LIBRARIES'], $_SERVER['TRENDING_EXCLUDE_LIBRARIES']);
        } else {
            putenv('TRENDING_EXCLUDE_LIBRARIES=' . $this->previousExcludedLibraries);
            $_ENV['TRENDING_EXCLUDE_LIBRARIES'] = $this->previousExcludedLibraries;
            $_SERVER['TRENDING_EXCLUDE_LIBRARIES'] = $this->previousExcludedLibraries;
        }
    }

    public function testCompletedLeapMonthUsesMeasuredTimeAndHonestFallbackEstimates(): void
    {
        [$database, $repository] = $this->repository();
        $this->insert($database, '2024-02-03 12:00:00', 'Alex', 'Movie', 'Dune', '', 'movie-1', 1800, 1800, 'Movies', true);
        $this->insert($database, '2024-02-03 20:00:00', 'all', 'Movie', 'Dune', '', 'movie-2', 3600, null, 'Movies', true);
        $this->insert($database, '2024-02-29 18:00:00', 'Alex', 'Episode', 'Pilot', 'Example Show', 'episode-1', 1200, 1200, 'TV', true);
        $this->insert($database, '2024-02-29 19:00:00', 'Alex', 'Episode', 'Second', 'Example Show', 'episode-2', 1800, 1800, 'TV', true);
        $this->insert($database, '2024-02-12 09:00:00', null, 'Audio', 'A song', '', 'audio-1', 600, 600, 'Music', true);
        $this->insert($database, '2024-03-01 00:00:00', 'Alex', 'Movie', 'March', '', 'march-1', 7200, 7200, 'Movies', true);

        $data = (new MonthlyRecapService($repository))->data(null, null, new DateTimeImmutable('2024-03-31 23:30:00'));
        $recap = $data['recap'];

        self::assertSame('2024-02', $recap['month']);
        self::assertCount(29, $recap['days']);
        self::assertSame(9000, $recap['totalSeconds']);
        self::assertSame('2h 30m', $recap['watch']);
        self::assertSame('1h 0m', $recap['estimated']);
        self::assertTrue($recap['hasEstimates']);
        self::assertSame(5, $recap['plays']);
        self::assertSame(3, $recap['activeDays']);
        self::assertSame(3, $recap['titleCount']);
        self::assertSame(600, $recap['otherSeconds']);
        self::assertSame('10m', $recap['otherWatch']);
        self::assertTrue($recap['hasOtherMedia']);
        self::assertSame(['Dune', 'Example Show', 'Dune'], array_column($recap['featured'], 'title'));
        self::assertSame('/api/image.php?item=movie-2&type=Primary&maxWidth=320&retained=1', $recap['featured'][0]['poster']);
        self::assertSame('/api/image.php?item=episode-2&type=Primary&maxWidth=320&retained=1&kind=series', $recap['series'][0]['poster']);
        self::assertSame('3 February', $recap['bestDay']);
        self::assertStringContainsString('Days without entries mean no recorded viewing', $recap['coverageNote']);
        self::assertStringContainsString('music and other media', $recap['rankingNote']);
        self::assertFalse($recap['days'][0]['known']);
        self::assertSame('No recorded viewing', $recap['days'][0]['duration']);
        self::assertSame('Europe/Prague', $recap['timezone']);
        self::assertSame('', $data['viewer']);
        self::assertSame('All viewers', $data['viewerOptions']['']);
        self::assertArrayHasKey('all', $data['viewerOptions']);
    }

    public function testViewerNamedAllRemainsSelectableAndUnknownNamesStayHonest(): void
    {
        [$database, $repository] = $this->repository();
        $this->insert($database, '2026-08-02 10:00:00', 'all', 'Movie', 'Arrival', '', 'arrival', 600, 600, 'Movies', true);
        $this->insert($database, '2026-08-03 10:00:00', 'Other', 'Movie', 'Moon', '', 'moon', 1200, 1200, 'Movies', true);
        $this->insert($database, '2026-08-04 10:00:00', null, 'Audio', 'Unknown song', '', 'song', 300, 300, 'Music', true);
        $this->insert($database, '2026-08-05 10:00:00', 'Unknown viewer', 'Audio', 'Named song', '', 'named-song', 300, 300, 'Music', true);
        $this->insert($database, '2026-07-05 10:00:00', 'Elsewhere', 'Movie', 'Earlier film', '', 'earlier-film', 300, 300, 'Movies', true);

        $allAccount = (new MonthlyRecapService($repository))->data('2026-08', 'all', new DateTimeImmutable('2026-09-14 10:00:00'));
        self::assertSame('all', $allAccount['viewer']);
        self::assertSame('all', $allAccount['recap']['scopeLabel']);
        self::assertSame(1, $allAccount['recap']['plays']);
        self::assertSame(600, $allAccount['recap']['totalSeconds']);

        $everyone = (new MonthlyRecapService($repository))->data('2026-08', '', new DateTimeImmutable('2026-09-14 10:00:00'));
        self::assertSame(2, count(array_filter(
            $everyone['recap']['viewers'],
            static fn (array $row): bool => $row['name'] === 'Unknown viewer',
        )));
        self::assertSame('Unknown viewer', $everyone['viewerOptions']['Unknown viewer']);

        $empty = (new MonthlyRecapService($repository))->data('2026-08', 'Elsewhere', new DateTimeImmutable('2026-09-14 10:00:00'));
        self::assertSame('Elsewhere', $empty['viewer']);
        self::assertTrue($empty['recap']['empty']);
    }

    public function testMalformedAndFutureMonthsFallBackWhileAnOldValidMonthRemainsAccessible(): void
    {
        [$database, $repository] = $this->repository();
        $this->insert($database, '2000-01-15 10:00:00', 'Archivist', 'Movie', 'Old film', '', 'old-film', 900, 900, 'Movies', true);
        $service = new MonthlyRecapService($repository);
        $now = new DateTimeImmutable('2026-09-14 10:00:00');

        self::assertSame('2026-08', $service->data('banana', null, $now)['recap']['month']);
        self::assertSame('2026-08', $service->data('2026-09', null, $now)['recap']['month']);
        self::assertSame('2026-08', $service->data('2026-13', null, $now)['recap']['month']);

        $old = $service->data('2000-01', 'Archivist', $now);
        self::assertSame('2000-01', $old['recap']['month']);
        self::assertSame('January 2000', $old['months']['2000-01']);
        self::assertLessThanOrEqual(241, count($old['months']));
    }

    public function testAppTimezoneControlsCompletedMonthAcrossDstBoundary(): void
    {
        [$database, $repository] = $this->repository();
        $this->insert($database, '2024-03-31 01:30:00', 'Viewer', 'Movie', 'Before jump', '', 'before-jump', 600, 600, 'Movies', true);
        $this->insert($database, '2024-03-31 03:30:00', 'Viewer', 'Movie', 'After jump', '', 'after-jump', 600, 600, 'Movies', true);
        $this->insert($database, '2024-04-01 00:00:00', 'Viewer', 'Movie', 'April', '', 'april', 600, 600, 'Movies', true);

        $recap = (new MonthlyRecapService($repository))->data(
            null,
            '',
            new DateTimeImmutable('2024-03-31T22:30:00+00:00'),
        )['recap'];

        self::assertSame('2024-03', $recap['month']);
        self::assertCount(31, $recap['days']);
        self::assertSame(2, $recap['plays']);
        self::assertSame(1200, $recap['totalSeconds']);
        self::assertTrue($recap['days'][30]['known']);
    }

    public function testCanonicalJellyfinMovieIdsGroupDashedAndUndashedForms(): void
    {
        [$database, $repository] = $this->repository();
        $this->insert($database, '2026-08-02 10:00:00', 'Viewer', 'Movie', 'Same film', '', 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA', 600, 600, 'Movies', true);
        $this->insert($database, '2026-08-03 10:00:00', 'Viewer', 'Movie', 'Same film', '', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 900, 900, 'Movies', true);
        $this->insert($database, '2026-08-04 10:00:00', 'Viewer', 'Movie', 'Hyphen', '', 'a-b', 300, 300, 'Movies', true);
        $this->insert($database, '2026-08-05 10:00:00', 'Viewer', 'Movie', 'Plain', '', 'ab', 300, 300, 'Movies', true);

        $recap = (new MonthlyRecapService($repository))->data('2026-08', '', new DateTimeImmutable('2026-09-14 10:00:00'))['recap'];

        self::assertSame(3, $recap['titleCount']);
        self::assertSame(2, $recap['movies'][0]['plays']);
        self::assertSame('Same film', $recap['movies'][0]['title']);
    }

    public function testMonitoringAndThemeExclusionsApplyBeforeViewerChoicesAndTotals(): void
    {
        $database = Database::sqlite(':memory:');
        $themes = new ThemePlaybackExclusions($database, ThemePlaybackExclusions::serverKey('http://recap.test'));
        $repository = new PlayHistoryRepository($database, new MonitoringExclusions(['Blocked']), null, $themes);
        $this->insert($database, '2026-08-02 10:00:00', 'Allowed', 'Movie', 'Visible', '', 'visible', 600, 600, 'Movies', true);
        $this->insert($database, '2026-08-03 10:00:00', 'Blocked', 'Movie', 'Private', '', 'private', 3600, 3600, 'Movies', true);
        $this->insert($database, '2026-08-04 10:00:00', 'Theme listener', 'Audio', 'Theme', '', 'theme', 1800, 1800, 'Music', true);
        $themes->remember('theme', 'Audio', ThemePlaybackClassifier::THEME);

        $data = (new MonthlyRecapService($repository))->data('2026-08', '', new DateTimeImmutable('2026-09-14 10:00:00'));

        self::assertSame(1, $data['recap']['plays']);
        self::assertSame(600, $data['recap']['totalSeconds']);
        self::assertSame(['' => 'All viewers', 'Allowed' => 'Allowed'], $data['viewerOptions']);
    }

    public function testConfiguredDatabaseProfileExecutesRecapPeriodQuery(): void
    {
        $database = Container::db();
        $repository = new PlayHistoryRepository($database);
        $db = $database->getDibi();
        $session = 'monthly-recap-profile-' . bin2hex(random_bytes(6));
        try {
            $db->insert('play_history', [
                'session_key' => $session,
                'user_name' => 'Monthly profile viewer',
                'item_id' => 'monthly-profile-film',
                'item_type' => 'Movie',
                'item_name' => 'Profile film',
                'library' => 'Movies',
                'library_resolved_at' => '2098-02-14 20:00:00',
                'play_method' => 'DirectPlay',
                'watched_sec' => 900,
                'watch_duration_sec' => 900,
                'runtime_sec' => 900,
                'started_at' => '2098-02-14 20:00:00',
                'updated_at' => '2098-02-14 20:15:00',
                'is_finished' => 1,
                'notified' => 1,
            ])->execute();

            $data = (new MonthlyRecapService($repository))->data(
                '2098-02',
                'Monthly profile viewer',
                new DateTimeImmutable('2098-03-20 10:00:00'),
            );
            self::assertSame(1, $data['recap']['plays']);
            self::assertSame(900, $data['recap']['totalSeconds']);
            self::assertSame('Profile film', $data['recap']['movies'][0]['title']);
        } finally {
            $db->delete('play_history')->where('session_key = %s', $session)->execute();
        }
    }

    public function testStatisticsLibraryExclusionsOnlyRemoveMovieAndSeriesRankings(): void
    {
        putenv('TRENDING_EXCLUDE_LIBRARIES=Blocked');
        $_ENV['TRENDING_EXCLUDE_LIBRARIES'] = 'Blocked';
        $_SERVER['TRENDING_EXCLUDE_LIBRARIES'] = 'Blocked';
        [$database, $repository] = $this->repository();
        $this->insert($database, '2026-08-02 10:00:00', 'Viewer', 'Movie', 'Hidden ranking', '', 'hidden', 3600, 3600, 'Blocked', true);
        $this->insert($database, '2026-08-03 10:00:00', 'Viewer', 'Audio', 'Song', '', 'song', 600, 600, 'Music', true);

        $recap = (new MonthlyRecapService($repository))->data('2026-08', '', new DateTimeImmutable('2026-09-14 10:00:00'))['recap'];

        self::assertSame(4200, $recap['totalSeconds']);
        self::assertSame(2, $recap['plays']);
        self::assertSame(0, $recap['titleCount']);
        self::assertSame([], $recap['featured']);
        self::assertStringContainsString('libraries excluded in Statistics', $recap['rankingNote']);
    }

    public function testSeriesWithMixedLibraryResolutionStayTogether(): void
    {
        [$database, $repository] = $this->repository();
        $this->insert($database, '2026-08-02 10:00:00', 'Viewer', 'Episode', 'Pilot', 'Example Show', 'episode-1', 600, 600, 'TV', false);
        $this->insert($database, '2026-08-03 10:00:00', 'Viewer', 'Episode', 'Second', 'Example Show', 'episode-2', 900, 900, 'TV', true);
        $recap = (new MonthlyRecapService($repository))->data('2026-08', '', new DateTimeImmutable('2026-09-14'))['recap'];
        self::assertCount(1, $recap['series']);
        self::assertSame(2, $recap['series'][0]['plays']);
        self::assertSame('25m', $recap['series'][0]['duration']);
        self::assertSame(1, $recap['titleCount']);
    }

    public function testSameNamedSeriesInDifferentLibrariesRemainSeparate(): void
    {
        [$database, $repository] = $this->repository();
        foreach ([['episode-a', 'TV', true], ['episode-b', 'Other TV', true], ['episode-a', '', false], ['episode-c', '', false]] as $i => [$id, $library, $confirmed]) {
            $this->insert($database, '2026-08-0' . ($i + 1) . ' 10:00:00', 'Viewer', 'Episode', 'Episode', 'Same name', $id, 600, 600, $library, $confirmed);
        }
        $recap = (new MonthlyRecapService($repository))->data('2026-08', '', new DateTimeImmutable('2026-09-14'))['recap'];
        self::assertCount(3, $recap['series']);
        self::assertSame([2, 1, 1], array_column($recap['series'], 'plays'));
        self::assertStringContainsString('item=episode-a&', $recap['series'][0]['poster']);
        self::assertSame('40m', $recap['watch']);
    }

    public function testNewHistoryHasOnlyOneEmptyCompletedMonthChoice(): void
    {
        [$database, $repository] = $this->repository();
        $service = new MonthlyRecapService($repository);
        $now = new DateTimeImmutable('2026-09-14');
        self::assertSame(['2026-08' => 'August 2026'], $service->data(null, null, $now)['months']);
        $this->insert($database, '2026-09-02 10:00:00', 'Viewer', 'Movie', 'New film', '', 'new-film', 600, 600, 'Movies', true);
        self::assertSame(['2026-08' => 'August 2026'], $service->data(null, null, $now)['months']);
        self::assertSame(['2026-08' => 'August 2026', '2026-06' => 'June 2026'], $service->data('2026-06', null, $now)['months']);
    }

    public function testRecapRendersCountLabelsAndEscapesTheSelectedViewer(): void
    {
        [$database, $repository] = $this->repository();
        $service = new MonthlyRecapService($repository);
        $now = new DateTimeImmutable('2026-09-14');
        $twig = new \Twig\Environment(new \Twig\Loader\ChainLoader([
            new \Twig\Loader\ArrayLoader(['_shell.twig' => '{% block dashboard_content %}{% endblock %}']),
            new \Twig\Loader\FilesystemLoader(TEMPLATES_DIR),
        ]));
        $viewer = '<b>Viewer & Co</b>';

        $empty = $service->data('2026-08', '', $now);
        self::assertSame('', $empty['recap']['rankingNote']);
        self::assertStringContainsString('No viewing history for this month', $twig->render('statistics/recap.twig', $empty));

        $this->insert($database, '2026-08-02 10:00:00', $viewer, 'Movie', 'First film', '', 'first', 600, 600, 'Movies', true);
        $single = $service->data('2026-08', $viewer, $now);
        self::assertSame('', $single['recap']['rankingNote']);
        $html = $twig->render('statistics/recap.twig', $single);
        self::assertStringContainsString('Movies<span>1 title</span>', $html);
        self::assertStringContainsString('Series<span>0 titles</span>', $html);
        self::assertSame(3, preg_match_all('/\b1 play\b/', $html));
        self::assertStringNotContainsString('1 plays', $html);
        self::assertStringContainsString('Viewing by &lt;b&gt;Viewer &amp; Co&lt;/b&gt;', $html);
        self::assertStringNotContainsString('Viewing by <b>', $html);

        $this->insert($database, '2026-08-03 10:00:00', $viewer, 'Movie', 'First film', '', 'first', 600, 600, 'Movies', true);
        $this->insert($database, '2026-08-04 10:00:00', 'Another viewer', 'Movie', 'Second film', '', 'second', 600, 600, 'Movies', true);
        $html = $twig->render('statistics/recap.twig', $service->data('2026-08', '', $now));
        self::assertStringContainsString('Movies<span>2 titles</span>', $html);
        self::assertSame(3, preg_match_all('/\b2 plays\b/', $html));
        self::assertStringContainsString('Who was watching', $html);
    }

    /** @return array{Database, PlayHistoryRepository} */
    private function repository(): array
    {
        $database = Database::sqlite(':memory:');

        return [$database, new PlayHistoryRepository($database)];
    }

    private function insert(
        Database $database,
        string $startedAt,
        ?string $user,
        string $type,
        string $name,
        string $series,
        string $itemId,
        int $watchedSeconds,
        ?int $sampledSeconds,
        string $library,
        bool $libraryConfirmed,
    ): void {
        static $sequence = 0;
        ++$sequence;
        $database->getDibi()->insert('play_history', [
            'session_key' => 'recap-' . $sequence,
            'user_name' => $user,
            'item_id' => $itemId,
            'item_type' => $type,
            'series_name' => $series !== '' ? $series : null,
            'item_name' => $name,
            'library' => $library,
            'library_resolved_at' => $libraryConfirmed ? $startedAt : null,
            'play_method' => 'DirectPlay',
            'watched_sec' => $watchedSeconds,
            'watch_duration_sec' => $sampledSeconds,
            'runtime_sec' => max($watchedSeconds, 1),
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'is_finished' => 1,
            'notified' => 1,
        ])->execute();
    }
}
