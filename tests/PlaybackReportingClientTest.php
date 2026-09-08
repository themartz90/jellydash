<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackReportingClient;
use Mk\Framework\Jellyfin\PlaybackReportingParser;
use PHPUnit\Framework\TestCase;

final class PlaybackReportingClientTest extends TestCase
{
    public function testActivityChunkRequestsPauseDurationWhenPluginProvidesIt(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(true);

        $chunk = $client->activityChunk(new PlaybackReportingParser(), 0, 25);

        $this->assertCount(1, $chunk['rows']);
        $this->assertSame(480, $chunk['rows'][0]['watched_sec']);
        $this->assertStringContainsString('PauseDuration', $client->queries[1] ?? '');
        $this->assertStringNotContainsString('RemoteAddress', $client->queries[1] ?? '');
    }

    public function testActivityChunkKeepsLegacyColumnsWhenPauseDurationIsMissing(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(false);

        $chunk = $client->activityChunk(new PlaybackReportingParser(), 0, 25);

        $this->assertCount(1, $chunk['rows']);
        $this->assertSame(600, $chunk['rows'][0]['watched_sec']);
        $this->assertStringNotContainsString('PauseDuration', $client->queries[1] ?? '');
    }

    public function testActivityChunkFallsBackWhenSchemaProbeFails(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(null);

        $chunk = $client->activityChunk(new PlaybackReportingParser(), 0, 25);

        $this->assertCount(1, $chunk['rows']);
        $this->assertSame(600, $chunk['rows'][0]['watched_sec']);
        $this->assertStringNotContainsString('PauseDuration', $client->queries[1] ?? '');
    }

    public function testActivityChunkCachesTheSchemaProbe(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(true);

        $client->activityChunk(new PlaybackReportingParser(), 0, 25);
        $client->activityChunk(new PlaybackReportingParser(), 25, 25);

        $schemaQueries = array_filter(
            $client->queries,
            static fn (string $query): bool => str_starts_with($query, 'PRAGMA table_info'),
        );
        $this->assertCount(1, $schemaQueries);
    }

    public function testKeysetImportSurvivesTiedDatesAndPruningWithoutIncludingNewRows(): void
    {
        $sqlite = new SQLite3(':memory:');
        $sqlite->exec('CREATE TABLE PlaybackActivity (DateCreated TEXT, UserId TEXT, ItemId TEXT, ItemType TEXT, ItemName TEXT, PlaybackMethod TEXT, ClientName TEXT, DeviceName TEXT, PlayDuration INTEGER)');
        $insert = $sqlite->prepare("INSERT INTO PlaybackActivity VALUES ('2026-09-08 12:00:00', '12345', :item, 'Movie', 'Example', 'DirectPlay', 'Web', 'Browser', 30)");
        for ($id = 1; $id <= 501; ++$id) {
            $insert->bindValue(':item', (string) $id, SQLITE3_TEXT);
            $insert->execute()->finalize();
        }
        $client = new class ($sqlite) extends PlaybackReportingClient {
            public function __construct(private SQLite3 $sqlite)
            {
            }
            protected function customQuery(string $sql, int $timeout): array
            {
                $result = $this->sqlite->query($sql);
                $columns = [];
                for ($i = 0; $i < $result->numColumns(); ++$i) {
                    $columns[] = $result->columnName($i);
                }
                $rows = [];
                while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
                    $rows[] = $row;
                }
                $result->finalize();
                return ['columns' => $columns, 'results' => $rows];
            }
        };
        $boundary = $client->activityBoundary();
        $parser = new PlaybackReportingParser();
        $first = $client->activityPage($parser, 0, $boundary['lastRowId']);
        $this->assertSame(501, $boundary['count']);
        $this->assertCount(500, $first['rows']);

        $sqlite->exec('DELETE FROM PlaybackActivity WHERE rowid = 1');
        $insert->bindValue(':item', '502', SQLITE3_TEXT);
        $insert->execute()->finalize();
        $second = $client->activityPage($parser, $first['cursor'], $boundary['lastRowId']);
        $this->assertCount(1, $second['rows']);
        $this->assertSame('501', $second['rows'][0]['item_id']);
        $this->assertSame(501, $second['cursor']);
        $this->assertSame(0, $client->activityPage($parser, $second['cursor'], $boundary['lastRowId'])['fetched']);
        $sqlite->close();
    }
}

/** @internal */
final class SchemaAwarePlaybackReportingClient extends PlaybackReportingClient
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly ?bool $withPauseDuration)
    {
    }

    /**
     * @return array{columns: array<int, mixed>, results: array<int, mixed>}
     */
    protected function customQuery(string $sql, int $timeout): array
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'PRAGMA table_info')) {
            if ($this->withPauseDuration === null) {
                throw new RuntimeException('Schema probe unavailable.');
            }

            $names = [
                'DateCreated',
                'UserId',
                'ItemId',
                'ItemType',
                'ItemName',
                'PlaybackMethod',
                'ClientName',
                'DeviceName',
                'PlayDuration',
            ];
            if ($this->withPauseDuration) {
                $names[] = 'PauseDuration';
            }

            return [
                'columns' => ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk'],
                'results' => array_map(
                    static fn (int $cid, string $name): array => [$cid, $name, 'TEXT', 0, null, 0],
                    array_keys($names),
                    $names,
                ),
            ];
        }

        $columns = ['DateCreated', 'UserId', 'ItemId', 'ItemType', 'ItemName', 'PlaybackMethod', 'ClientName', 'DeviceName', 'PlayDuration'];
        $row = ['2026-08-31 20:14:00.1234567', '7654321', '1234567', 'Movie', 'Arrival', 'DirectPlay', 'Emby Web', 'Chrome', '600'];
        if ($this->withPauseDuration === true) {
            $columns[] = 'PauseDuration';
            $row[] = '120';
        }

        return ['columns' => $columns, 'results' => [$row]];
    }
}
