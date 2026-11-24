<?php

declare(strict_types=1);

use Mk\Framework\Jellyseerr\JellyseerrClient;
use PHPUnit\Framework\TestCase;

final class JellyseerrClientTest extends TestCase
{
    public function testRequestPagePassesTakeAndSkipToTheListEndpoint(): void
    {
        $client = new QueryCapturingJellyseerrClient();

        $requests = $client->requestPage(40, 80);

        $this->assertSame([['id' => 81]], $requests);
        $this->assertSame('/api/v1/request', $client->path);
        $this->assertSame(['take' => 40, 'skip' => 80, 'sort' => 'added'], $client->query);
    }

    public function testRequestsRemainsTheFirstPageShortcut(): void
    {
        $client = new QueryCapturingJellyseerrClient();

        $client->requests(24);

        $this->assertSame(['take' => 24, 'skip' => 0, 'sort' => 'added'], $client->query);
    }

    public function testBrowsingReadsOneFilteredPageAndKeepsUpstreamTotals(): void
    {
        $client = new QueryCapturingJellyseerrClient();
        $client->payload['pageInfo'] = ['pages' => 6, 'pageSize' => 24, 'results' => 125, 'page' => 5];

        $page = $client->requestPageWithMetadata(5, 'pending');
        self::assertSame($client->payload['pageInfo'], $page['pageInfo']);
        self::assertSame($client->payload['results'], $page['results']);
        self::assertSame('/api/v1/request', $client->path);
        self::assertSame(['take' => 24, 'skip' => 96, 'sort' => 'added', 'filter' => 'pending'], $client->query);
    }

    public function testMalformedPageCannotMasqueradeAsAnEmptyQueue(): void
    {
        $client = new QueryCapturingJellyseerrClient();
        $this->expectException(RuntimeException::class);
        $client->requestPageWithMetadata(1);
    }

    public function testOutOfRangePageKeepsRealTotalsForClamping(): void
    {
        $client = new QueryCapturingJellyseerrClient();
        $client->payload = ['pageInfo' => ['pages' => 1, 'pageSize' => 24, 'results' => 1, 'page' => 5], 'results' => []];

        self::assertSame($client->payload, $client->requestPageWithMetadata(5));
    }

    public function testInvalidPageOrFilterDoesNotContactUpstream(): void
    {
        $client = new QueryCapturingJellyseerrClient();
        foreach ([[0, 'all'], [PHP_INT_MAX, 'all'], [1, 'unknown']] as [$page, $filter]) {
            try {
                $client->requestPageWithMetadata($page, $filter);
                self::fail('Expected invalid paging input to be refused.');
            } catch (InvalidArgumentException) {
                self::assertSame('', $client->path);
            }
        }
    }

    public function testDetailRejectsAResponseForAnotherRequest(): void
    {
        $client = new QueryCapturingJellyseerrClient();
        $client->payload = ['id' => 82];

        try {
            $client->requestDetail(81);
            self::fail('Expected mismatched request identity to be refused.');
        } catch (RuntimeException) {
            self::assertSame('/api/v1/request/81', $client->path);
            self::assertSame([], $client->query);
        }
    }
}

final class QueryCapturingJellyseerrClient extends JellyseerrClient
{
    public string $path = '';
    /** @var array<string, string|int> */
    public array $query = [];
    public array $payload = ['results' => [['id' => 81]]];

    public function __construct()
    {
        parent::__construct('http://jellyseerr.test', 'token');
    }

    protected function get(string $path, array $query = []): array
    {
        $this->path = $path;
        $this->query = $query;

        return $this->payload;
    }
}
