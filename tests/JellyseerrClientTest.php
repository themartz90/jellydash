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
}

final class QueryCapturingJellyseerrClient extends JellyseerrClient
{
    public string $path = '';
    /** @var array<string, string|int> */
    public array $query = [];

    public function __construct()
    {
        parent::__construct('http://jellyseerr.test', 'token');
    }

    protected function get(string $path, array $query = []): array
    {
        $this->path = $path;
        $this->query = $query;

        return ['results' => [['id' => 81]]];
    }
}
