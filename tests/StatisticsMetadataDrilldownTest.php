<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class StatisticsMetadataDrilldownTest extends TestCase
{
    private PlayHistoryRepository $repository;
    private \Dibi\Connection $db;
    private const USER = 'PHPUnit Metadata Drilldowns';

    protected function setUp(): void
    {
        $this->repository = new PlayHistoryRepository(Container::db());
        $this->db = Container::db()->getDibi();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testCodecFiltersAreExactAndCarryThroughEveryHistoryRead(): void
    {
        foreach (['HEVC', 'hevc', 'HEVC extra', 'AV1', 'H.264', 'A%_"雪'] as $index => $codec) {
            $this->play('codec-' . $index, ['sourceVideoCodec' => $codec]);
        }
        $filters = HistoryFilters::fromQuery(['user' => self::USER, 'range' => 'all', 'codec' => ['HEVC', 'AV1']]);
        self::assertSame(['HEVC', 'AV1'], $filters->queryParameters()['codec'] ?? null);
        self::assertSame(2, $this->repository->historyTotal($filters));
        self::assertSame(2, $this->repository->historyAggregate($filters)['plays']);
        self::assertCount(2, $this->repository->historyRows($filters));
        self::assertCount(2, iterator_to_array($this->repository->historyExportRows($filters)));
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        self::assertSame(2, (new HistoryCsvExporter($this->repository))->write($filters, $stream));
        fclose($stream);
        self::assertSame(1, $this->repository->historyTotal(HistoryFilters::fromQuery([
            'user' => self::USER, 'range' => 'all', 'codec' => 'A%_"雪',
        ])));
    }

    public function testCodecBarsKeepExactMembersIncludingOtherAndWhitespaceVariants(): void
    {
        foreach (['HEVC', 'HEVC ', 'AV1', 'H.264', 'VP9', 'MPEG2', 'VC1', 'Other codecs', 'VP8', 'MPEG4'] as $index => $codec) {
            $this->play('group-' . $index, ['sourceVideoCodec' => $codec]);
        }
        $stats = (new PlaybackStatisticsService($this->repository))->data('week', new DateTimeImmutable('2091-10-08 12:00:00'));
        self::assertCount(7, $stats['codecs']);
        foreach ($stats['codecs'] as $bar) {
            self::assertArrayHasKey('href', $bar);
            parse_str((string) parse_url($bar['href'], PHP_URL_QUERY), $query);
            self::assertSame('2091-10-02', $query['start']);
            self::assertSame('2091-10-09', $query['end']);
            self::assertSame($bar['count'], $this->repository->historyTotal(HistoryFilters::fromQuery($query)));
        }
    }

    public function testHistoryCodecScopeSurvivesControllerFormsAndPagination(): void
    {
        $this->play('included', ['sourceVideoCodec' => 'HEVC', 'transcodeReasons' => ['Audio codec not supported']]);
        $this->play('excluded', ['sourceVideoCodec' => 'AV1', 'transcodeReasons' => ['Video codec not supported']]);
        $query = ['user' => self::USER, 'range' => 'custom', 'start' => '2091-10-02', 'end' => '2091-10-09', 'codec' => ['HEVC'], 'reason' => ['Audio codec not supported']];
        $previous = $_GET;
        $_GET = $query;
        try {
            $view = new class () extends \Mk\Framework\View {
                public array $data = [];
                public function __construct()
                {
                }
                public function render(string $template, array $data = []): void
                {
                    $this->data = $data;
                }
            };
            $controller = new \Mk\Framework\Pages\HistoryController($view);
            $controller->handle();
            self::assertSame(1, $view->data['summary']['filtered_total']);
            self::assertCount(1, $view->data['groups'][0]['plays']);
            $scope = $view->data['filters']['metadata_scopes'][0];
            self::assertSame(['HEVC'], $scope['values']);
            parse_str((string) parse_url($scope['clear_url'], PHP_URL_QUERY), $clear);
            self::assertArrayNotHasKey('codec', $clear);
            self::assertSame('2091-10-02', $clear['start']);
            self::assertSame(self::USER, $clear['user']);
            self::assertSame($query['reason'], $clear['reason']);
            $reasonScope = $view->data['filters']['metadata_scopes'][1];
            self::assertSame($query['reason'], $reasonScope['values']);
            parse_str((string) parse_url($reasonScope['clear_url'], PHP_URL_QUERY), $reasonClear);
            self::assertArrayNotHasKey('reason', $reasonClear);
            self::assertSame($query['codec'], $reasonClear['codec']);
            $url = (new ReflectionMethod($controller, 'historyUrl'))->invoke($controller, HistoryFilters::fromQuery($query), 2);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $page);
            self::assertSame(['HEVC'], $page['codec']);
            self::assertSame('2', $page['p']);
            self::assertSame($query['reason'], $page['reason']);
            $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(TEMPLATES_DIR));
            $header = $twig->load('history/index.twig')->renderBlock('dashboard_header', $view->data);
            self::assertStringContainsString('Source video codec: <strong>HEVC</strong>', $header);
            self::assertStringContainsString('name="codec[]" value="HEVC"', $header);
            self::assertStringContainsString('Transcoding reason: <strong>Audio codec not supported</strong>', $header);
            self::assertStringContainsString('name="reason[]" value="Audio codec not supported"', $header);
            $export = $twig->render('history/_export_dialog.twig', $view->data);
            self::assertStringContainsString('name="codec[]" value="HEVC"', $export);
            self::assertStringContainsString('name="reason[]" value="Audio codec not supported"', $export);
            self::assertStringContainsString('data-history-export-metadata-scope', $export);
        } finally {
            $_GET = $previous;
        }
    }

    public function testReasonFiltersMatchArrayTokensOnceAndCombineWithCodecs(): void
    {
        $reason = 'Video codec not supported';
        $this->play('both', ['sourceVideoCodec' => 'HEVC', 'transcodeReasons' => [$reason, 'Audio codec not supported']]);
        $this->play('audio', ['sourceVideoCodec' => 'AV1', 'transcodeReasons' => ['Audio codec not supported']]);
        $this->play('substring', ['transcodeReasons' => [$reason . ' extra']]);
        $this->play('case', ['transcodeReasons' => ['video codec not supported']]);
        $this->play('special', ['transcodeReasons' => ['A%_"雪\\reason']]);
        foreach ([
            'bad-json' => '[broken',
            'object' => '{"reason":"Video codec not supported"}',
            'numeric-object' => '{"0":"Video codec not supported"}',
            'scalar' => '"Video codec not supported"',
            'nested' => '[["Video codec not supported"]]',
        ] as $id => $encoded) {
            $this->play($id);
            $this->db->update('play_history', ['transcode_reasons' => $encoded])->where('item_id = %s', 'metadata-' . $id)->execute();
        }
        $query = ['user' => self::USER, 'range' => 'all', 'reason' => [$reason, 'Audio codec not supported']];
        $filters = HistoryFilters::fromQuery($query);
        self::assertSame($query['reason'], $filters->queryParameters()['reason'] ?? null);
        self::assertSame(2, $this->repository->historyTotal($filters));
        self::assertSame(2, $this->repository->historyAggregate($filters)['plays']);
        self::assertCount(2, $this->repository->historyRows($filters));
        self::assertCount(2, iterator_to_array($this->repository->historyExportRows($filters)));
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        self::assertSame(2, (new HistoryCsvExporter($this->repository))->write($filters, $stream));
        fclose($stream);
        self::assertSame(1, $this->repository->historyTotal(HistoryFilters::fromQuery($query + ['codec' => 'HEVC'])));
        self::assertSame(1, $this->repository->historyTotal(HistoryFilters::fromQuery([
            'user' => self::USER, 'range' => 'all', 'reason' => 'A%_"雪\\reason',
        ])));
        $counts = (new ReflectionMethod(PlaybackStatisticsService::class, 'reasonCounts'))->invoke(
            new PlaybackStatisticsService($this->repository),
            $this->repository->historyRows(HistoryFilters::fromQuery(['user' => self::USER, 'range' => 'all'])),
        );
        self::assertSame(1, $counts[$reason]);
    }

    public function testOtherReasonsKeepTheirMembersAndDifferentOccurrenceDenominator(): void
    {
        foreach (range(1, 6) as $index) {
            foreach (range(1, 3) as $play) {
                $this->play('top-' . $index . '-' . $play, ['transcodeReasons' => ['Frequent ' . $index]]);
            }
        }
        $this->play('tail-pair', ['transcodeReasons' => ['Tail A', 'Tail B']]);
        $this->play('tail-space', ['transcodeReasons' => [' Tail A ']]);
        $this->play('tail-literal', ['transcodeReasons' => ['Other reasons']]);
        $stats = (new PlaybackStatisticsService($this->repository))->data('week', new DateTimeImmutable('2091-10-08 12:00:00'));
        self::assertCount(7, $stats['reasons']);
        $other = $stats['reasons'][6];
        self::assertSame('Other reasons', $other['name']);
        self::assertSame(4, $other['count']);
        self::assertArrayHasKey('href', $other);
        parse_str((string) parse_url($other['href'], PHP_URL_QUERY), $query);
        self::assertEqualsCanonicalizing(['Tail A', 'Tail B', ' Tail A ', 'Other reasons'], $query['reason']);
        self::assertSame('2091-10-02', $query['start']);
        self::assertSame('2091-10-09', $query['end']);
        self::assertSame(3, $this->repository->historyTotal(HistoryFilters::fromQuery($query)));
    }

    public function testMetadataQueriesPreserveRawValuesAndRejectNestedValues(): void
    {
        $filters = HistoryFilters::fromQuery([
            'codec' => ['HEVC ', 'HEVC ', '', ' ', ['AV1']],
            'reason' => ' Audio codec not supported ',
        ]);
        self::assertSame(['HEVC '], $filters->codecs);
        self::assertSame([' Audio codec not supported '], $filters->reasons);
        parse_str(http_build_query($filters->queryParameters()), $roundTrip);
        self::assertSame($filters->queryParameters(), HistoryFilters::fromQuery($roundTrip)->queryParameters());
    }

    public function testNestedReasonDataOnlyMatchesItsTopLevelStrings(): void
    {
        $reason = 'Video codec not supported';
        foreach ([
            'nested-only' => [[$reason]],
            'nested-and-top' => [[$reason], $reason],
            'nested-with-wrong-case-top' => [[$reason], 'video codec not supported'],
        ] as $id => $tokens) {
            $this->play($id);
            $this->db->update('play_history', ['transcode_reasons' => json_encode($tokens, JSON_THROW_ON_ERROR)])
                ->where('item_id = %s', 'metadata-' . $id)->execute();
        }
        $filters = HistoryFilters::fromQuery(['user' => self::USER, 'range' => 'all', 'reason' => $reason]);
        self::assertSame(1, $this->repository->historyTotal($filters));
        self::assertSame('nested-and-top', $this->repository->historyRows($filters)[0]['item_name']);
        $stats = (new PlaybackStatisticsService($this->repository))->data('week', new DateTimeImmutable('2091-10-08 12:00:00'));
        $bar = array_values(array_filter($stats['reasons'], static fn (array $item): bool => $item['name'] === $reason))[0];
        self::assertSame(1, $bar['count']);
    }

    /** @param array<string, mixed> $metadata */
    private function play(string $id, array $metadata = []): void
    {
        $this->repository->logActiveStreams([array_merge([
            'id' => 'phpunit-metadata-' . $id, 'itemId' => 'metadata-' . $id,
            'itemType' => 'Movie', 'itemName' => $id, 'user' => self::USER,
            'client' => 'Fixture client', 'playMethod' => 'Transcode', 'watchedSec' => 60,
        ], $metadata)], new DateTimeImmutable('2091-10-08 12:00:00'));
    }

    private function cleanup(): void
    {
        $this->db->delete('play_history')->where('user_name = %s', self::USER)->execute();
    }
}
