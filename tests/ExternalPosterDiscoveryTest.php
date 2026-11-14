<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ExternalPosterCollector;
use Mk\Framework\Jellyfin\JellyfinClient;
use PHPUnit\Framework\TestCase;

final class ExternalPosterDiscoveryTest extends TestCase
{
    private ArtworkRepository $art;
    private int $queries = 0;
    private int $downloads = 0;
    /** @var list<array<string, mixed>> */
    private array $requests = [];
    private ?Closure $response = null;
    private ?Closure $lookupAction = null;
    private ?Closure $verifyAction = null;

    protected function setUp(): void
    {
        $this->art = new ArtworkRepository(Container::db(), hash('sha256', random_bytes(16)));
        $this->art->anchor('discovery-test');
        $this->art->confirmLegacy();
    }

    protected function tearDown(): void
    {
        $db = Container::db()->getDibi();
        foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings', 'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            if ($db->getDatabaseInfo()->hasTable($table)) {
                $db->delete($table)->where('source_key = %s', $this->art->scope)->execute();
            }
        }
        foreach (['movie_identity_items', 'movie_identity_state', 'movie_replacements'] as $table) {
            if ($db->getDatabaseInfo()->hasTable($table)) {
                $db->delete($table)->where('server_key = %s', $this->art->scope)->execute();
            }
        }
    }

    public function testMissingNativeSeriesPreparesSuggestionsWithoutChoosingOrDownloading(): void
    {
        $this->item('ep-one', '進撃の巨人', 'Episode');
        $result = $this->collector()->runBatch();
        self::assertSame(1, $this->queries);
        self::assertSame(['Name' => '進撃の巨人'], $this->requests[0]);
        self::assertSame(0, $this->downloads);
        self::assertSame(0, $result['saved']);
        self::assertNull($this->art->externalBinding('ep-one')['tmdb_id']);
        self::assertNull($this->art->externalBinding('ep-one')['verified_id']);
        $input = $this->art->discoveryInput('ep-one');
        $record = $this->art->discoveryRecord($input['key'], time());
        self::assertNotNull($record);
        self::assertSame('1429', $record['candidates'][0]['id']);
    }

    public function testEpisodesShareMetadataButKeepIndependentHistoricalBindings(): void
    {
        $this->item('ep-one', '進撃の巨人', 'Episode');
        $this->item('ep-two', '進撃の巨人', 'Episode');
        $this->collector()->runBatch();
        self::assertSame(1, $this->queries);
        foreach (['ep-one', 'ep-two'] as $id) {
            self::assertNull($this->art->externalBinding($id)['tmdb_id']);
            self::assertGreaterThan(time(), (int) $this->art->externalBinding($id)['discovery_after']);
        }
    }

    public function testSnoozeSuppressionDisableAndManualUnlinkPreventTitleRequests(): void
    {
        $this->item('gone', 'Synthetic movie');
        $this->art->externalObserve('gone', 0);
        $this->art->manageExternal(['gone'], $this->art->externalFingerprint(['gone']), 'snooze');
        $this->collector()->runBatch();
        self::assertSame(0, $this->queries);
        $db = Container::db()->getDibi();
        $db->update('artwork_external_bindings', ['snoozed_until' => 0, 'next_attempt' => 0])->where('source_key = %s', $this->art->scope)->execute();
        $this->art->manageExternal(['gone'], $this->art->externalFingerprint(['gone']), 'unlink');
        $this->collector()->runBatch();
        self::assertSame(0, $this->queries);
        $this->art->externalSuppress(['gone'], $this->art->externalFingerprint(['gone']), true);
        $this->art->configureExternal(false);
        $this->collector()->runBatch();
        self::assertSame(0, $this->queries);
    }

    public function testMinuteGateDefersOtherTitlesPromptlyAndDoesNotHoldExactIdWork(): void
    {
        $now = time();
        $this->item('a-title', 'One movie');
        $this->item('b-title', 'Another movie');
        $this->collector()->runBatch($now);
        self::assertSame(1, $this->queries);
        self::assertLessThanOrEqual($now + 61, (int) $this->art->externalBinding('b-title')['next_attempt']);
        $this->collector()->runBatch($now + 61);
        self::assertSame(2, $this->queries);
        $this->item('z-exact', 'Exact movie');
        $this->art->externalObserve('z-exact', 0, '1429');
        $this->item('c-title', 'Third movie');
        $this->collector()->runBatch($now + 62);
        self::assertArrayHasKey('ProviderIds', $this->requests[2]);
        self::assertSame(3, $this->queries);
    }

    public function testEmptyResultIsNeutralAndRetriedAfterShortNegativeCache(): void
    {
        $this->item('gone', 'No result');
        $this->response = static fn (): array => [];
        $now = time();
        $this->collector()->runBatch($now);
        $input = $this->art->discoveryInput('gone');
        $record = $this->art->discoveryRecord($input['key'], $now);
        self::assertSame([], $record['candidates']);
        self::assertNull($record['last_error']);
        self::assertSame($now + 900, (int) $record['expires_at']);
        $this->collector()->runBatch($now + 899);
        self::assertSame(1, $this->queries);
        $this->collector()->runBatch($now + 901);
        self::assertSame(2, $this->queries);
    }

    public function testTurningRecoveryOffDuringLookupDiscardsTheResults(): void
    {
        $this->item('gone', 'Synthetic movie');
        $this->response = function (): array {
            $this->art->configureExternal(false);
            return self::candidateResults();
        };
        $this->collector()->runBatch();
        $rows = Container::db()->getDibi()->select('candidates_json')->from('artwork_external_searches')->where('source_key = %s', $this->art->scope)->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('[]', $rows[0]['candidates_json']);
        self::assertNull($this->art->externalBinding('gone')['tmdb_id']);
    }

    public function testEvictingAFullMetadataCacheDoesNotRestartARecentLookup(): void
    {
        $this->item('first', 'First title');
        $now = time();
        $this->collector()->runBatch($now);
        $db = Container::db()->getDibi();
        $db->update('artwork_external_searches', ['expires_at' => $now + 604799])->where('source_key = %s', $this->art->scope)->execute();
        for ($index = 1; $index <= 99; ++$index) {
            $db->insert('artwork_external_searches', ['source_key' => $this->art->scope, 'query_key' => hash('sha256', 'filler-' . $index),
                'server_id' => 'discovery-test', 'config_revision' => 1, 'kind' => 'Movie', 'query_title' => 'Filler ' . $index,
                'acquired_at' => $now, 'expires_at' => $now + 604800, 'candidates_json' => '[]'])->execute();
        }
        $this->item('new', 'New title');
        $this->collector()->runBatch($now + 61);
        self::assertSame(2, $this->queries);
        self::assertSame(100, (int) $db->select('COUNT(*)')->from('artwork_external_searches')->where('source_key = %s', $this->art->scope)->fetchSingle());
        self::assertNull($this->art->discoveryRecord($this->art->discoveryInput('first')['key'], $now + 61));
        $db->update('artwork_external_bindings', ['next_attempt' => 0])->where('source_key = %s AND item_id = %s', $this->art->scope, 'first')->execute();
        $this->collector()->runBatch($now + 122);
        self::assertSame(2, $this->queries);
    }

    public function testReenableSchedulesFreshSuggestionsForTheNewConfiguration(): void
    {
        $this->item('gone', 'Synthetic movie');
        $now = time();
        $this->collector()->runBatch($now);
        self::assertSame(1, $this->queries);
        $this->art->configureExternal(false);
        $this->art->configureExternal(true);
        $this->collector()->runBatch($now + 61);
        self::assertSame(2, $this->queries);
        self::assertNotNull($this->art->discoveryRecord($this->art->discoveryInput('gone')['key'], $now + 61));
    }

    public function testCooldownReportedDuringItemInspectionPreventsTitleHttp(): void
    {
        $this->item('gone', 'Synthetic movie');
        $now = time();
        $this->lookupAction = function () use ($now): void {
            $this->art->externalNetworkFailure('discovery-test', 1, 'throttled', $now + 7200);
        };
        $this->collector()->runBatch($now);
        self::assertSame(0, $this->queries);
        self::assertGreaterThanOrEqual($now + 7200, (int) $this->art->externalBinding('gone')['next_attempt']);
    }

    public function testConcurrentCooldownDuringSourceVerificationIsNotCleared(): void
    {
        $this->item('gone', 'Synthetic movie');
        $now = time();
        $this->verifyAction = function () use ($now): void {
            $this->art->externalNetworkFailure('discovery-test', 1, 'throttled', $now + 7200);
        };
        $this->collector()->runBatch($now);
        self::assertSame(0, $this->queries);
        self::assertSame($now + 7200, (int) $this->art->source()['external_next_attempt']);
    }

    public function testLookupCannotCommitAfterItsLeaseExpiresWithoutASuccessor(): void
    {
        $this->item('gone', 'Synthetic movie');
        $now = time();
        $this->response = function () use ($now): array {
            Container::db()->getDibi()->update('artwork_sources', ['lease_until' => $now + 1])->where('source_key = %s', $this->art->scope)->execute();
            usleep(2100000);
            return self::candidateResults();
        };
        $this->collector()->runBatch($now);
        self::assertSame('[]', Container::db()->getDibi()->select('candidates_json')->from('artwork_external_searches')->where('source_key = %s', $this->art->scope)->fetchSingle());
    }

    private function collector(): ExternalPosterCollector
    {
        $client = new JellyfinClient('https://synthetic.invalid', 'synthetic', true, function (string $path, string $method, ?array $payload): array {
            if ($path === '/System/Info/Public') {
                if ($this->verifyAction !== null) {
                    ($this->verifyAction)();
                }
                return ['Id' => 'discovery-test'];
            }
            if ($method === 'POST') {
                ++$this->queries;
                $this->requests[] = $payload['SearchInfo'];
                return $this->response === null ? self::candidateResults() : ($this->response)();
            }
            if ($this->lookupAction !== null) {
                ($this->lookupAction)();
            }
            return ['Items' => [], 'TotalRecordCount' => 0];
        });
        return new ExternalPosterCollector(Container::db(), $client, $this->art, fetcher: function (): array {
            ++$this->downloads;
            throw new RuntimeException('Discovery must not download images.');
        });
    }

    private static function candidateResults(): array
    {
        return [['Name' => '進撃の巨人', 'ProductionYear' => 2013, 'ProviderIds' => ['Tmdb' => '1429'], 'ImageUrl' => 'https://image.tmdb.org/t/p/w342/poster.jpg']];
    }

    private function item(string $id, string $title, string $type = 'Movie'): void
    {
        $this->art->remember(['item_id' => $id, 'item_type' => $type, 'title' => $type === 'Episode' ? 'Episode One' : $title,
            'series_name' => $type === 'Episode' ? $title : '', 'library' => 'Synthetic library', 'poster_id' => null,
            'observed_at' => 0, 'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
    }
}
