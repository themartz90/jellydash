<?php

declare(strict_types=1);

use Mk\Framework\Http\RemoteRequestException;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestBrowser;
use Mk\Framework\Jellyseerr\RequestEditor;
use Mk\Framework\Jellyseerr\RequestManager;
use PHPUnit\Framework\TestCase;

final class SeerrRequestManagerTest extends TestCase
{
    private string $cache;
    private SeerrActionFixtureClient $client;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/jellydash-seerr-actions-' . bin2hex(random_bytes(8));
        $this->client = new SeerrActionFixtureClient();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cache)) {
            rmdir($this->cache);
        }
    }

    public function testSuccessfulApprovalInvalidatesSnapshotsAndReplayedActionSendsOneWrite(): void
    {
        $browse = new RequestBrowser($this->client, $this->cache);
        $page = $browse->page(1, 'all');
        $browse->detail(41);
        $browse->otherTotals('all');
        $version = $page['requests'][0]['managementVersion'];
        $manager = new RequestManager($this->client, $this->cache);
        self::assertSame('approved', $manager->execute('approve', 41, $version, static fn (): bool => true));
        self::assertSame('approved', $manager->execute('approve', 41, $version, static fn (): bool => true));
        self::assertSame(['approve:41'], $this->client->writes);

        $this->client->offline = true;
        $offline = new RequestBrowser($this->client, $this->cache);
        self::assertSame('unavailable', $offline->page(1, 'all')['state']);
        self::assertSame('unavailable', $offline->detail(41)['state']);
        self::assertSame(['pending' => null, 'failed' => null], $offline->otherTotals('all'));
    }

    public function testSeasonEditionAndPermissionChangesStopTheWrite(): void
    {
        $version = RequestManager::version($this->client->item);
        $manager = new RequestManager($this->client, $this->cache);
        $this->client->item['seasons'] = [['seasonNumber' => 3]];
        self::assertSame('stale', $manager->execute('decline', 41, $version, static fn (): bool => true));
        $version = RequestManager::version($this->client->item);
        $this->client->item['is4k'] = false;
        self::assertSame('stale', $manager->execute('delete', 41, $version, static fn (): bool => true));
        $version = RequestManager::version($this->client->item);
        $permissionChecks = 0;
        $permission = static function () use (&$permissionChecks): bool {
            return ++$permissionChecks === 1;
        };
        self::assertSame('denied', $manager->execute('approve', 41, $version, $permission));
        self::assertSame([], $this->client->writes);
    }

    public function testAmbiguousWriteIsReadBackAndUnresolvedReplayDoesNotWriteAgain(): void
    {
        $manager = new RequestManager($this->client, $this->cache);
        $version = RequestManager::version($this->client->item);
        $this->client->mode = 'timeout-before-change';
        self::assertSame('unknown', $manager->execute('approve', 41, $version, static fn (): bool => true));
        self::assertSame('unknown', $manager->execute('decline', 41, $version, static fn (): bool => true));
        self::assertSame(['approve:41'], $this->client->writes);
        self::assertSame(2, $this->client->detailReads);

        $this->client->item['status'] = 4;
        $this->client->mode = 'timeout-after-change';
        self::assertSame('observed-approved', $manager->execute('retry', 41, RequestManager::version($this->client->item), static fn (): bool => true));
        self::assertSame(['approve:41', 'retry:41'], $this->client->writes);
    }

    public function testBrowseReadsRecoverAnInterruptedWriterAndKeepItsUnknownReceipt(): void
    {
        $version = RequestManager::version($this->client->item);
        $file = RequestBrowser::stateFile($this->client, $this->cache);
        $file->write(['generation' => 'abandoned', 'writing' => true, 'receipts' => [
            '41:' . $version => ['at' => time(), 'action' => 'approve', 'result' => 'unknown'],
        ]]);
        $now = time();
        $clock = static function () use (&$now): int {
            return $now;
        };
        $browser = new RequestBrowser($this->client, $this->cache, $clock);
        self::assertFalse($file->read()['writing']);
        self::assertSame('current', $browser->page(1, 'all')['state']);
        $now += 30;
        $this->client->offline = true;
        self::assertSame('cached', (new RequestBrowser($this->client, $this->cache, $clock))->page(1, 'all')['state']);
        self::assertSame('unknown', (new RequestManager($this->client, $this->cache))->execute('approve', 41, $version, static fn (): bool => true));
        self::assertSame([], $this->client->writes);
    }

    public function testARefusedWriteCanBeRetriedDeliberately(): void
    {
        $manager = new RequestManager($this->client, $this->cache);
        $version = RequestManager::version($this->client->item);
        $this->client->mode = 'rate-limited';
        self::assertSame('refused', $manager->execute('approve', 41, $version, static fn (): bool => true));
        $this->client->mode = 'normal';
        self::assertSame('approved', $manager->execute('approve', 41, $version, static fn (): bool => true));
        self::assertSame(['approve:41', 'approve:41'], $this->client->writes);
    }

    public function testEditChecksEditionAndPreservesOverridesWithoutSendingNulls(): void
    {
        $this->client->item += ['requestedBy' => ['id' => 7], 'serverId' => null, 'profileId' => 2,
            'rootFolder' => '/fixture/series', 'tags' => [], 'languageProfileId' => null];
        $version = RequestManager::version($this->client->item);
        $options = (new RequestEditor($this->client))->options($this->client->item);
        self::assertNotNull($options);
        self::assertSame([1, 2, 3], $options['seasons']);
        self::assertArrayNotHasKey('default', $options['routes']['default']['profiles']);

        $manager = new RequestManager($this->client, $this->cache);
        $edit = ['optionsVersion' => $options['version'], 'seasons' => [1, 4]];
        self::assertSame('invalid-edit', $manager->execute('edit', 41, $version, static fn (): bool => true, $edit));
        $edit = ['optionsVersion' => $options['version'], 'seasons' => [1, 3], 'destination' => 'default', 'profile' => 'id:3'];
        self::assertSame('edited', $manager->execute('edit', 41, $version, static fn (): bool => true, $edit));
        self::assertSame('edited', $manager->execute('edit', 41, $version, static fn (): bool => true, $edit));
        self::assertSame([['mediaType' => 'tv', 'seasons' => [1, 3], 'profileId' => 3]], $this->client->editBodies);
        self::assertSame([], $this->client->item['tags']);
        self::assertNull($this->client->item['languageProfileId']);
        self::assertSame('/fixture/series', $this->client->item['rootFolder']);
        self::assertSame(1, $this->client->item['status']);
        self::assertSame(['id' => 7], $this->client->item['requestedBy']);
    }

    public function testFifthDestinationIsVerifiedOnDemandAndAgainOnSave(): void
    {
        $this->client->item += ['requestedBy' => ['id' => 7], 'serverId' => null, 'profileId' => null,
            'rootFolder' => null, 'tags' => [], 'languageProfileId' => null];
        $this->client->serverCount = 5;
        $editor = new RequestEditor($this->client);
        $initial = $editor->options($this->client->item);
        self::assertSame([null, 0], $this->client->serviceReads);
        self::assertSame([], $initial['routes']['id:4']['profiles']);

        $this->client->serviceReads = [];
        $selected = $editor->options($this->client->item, 'id:4');
        self::assertSame([null, 0, 4], $this->client->serviceReads);
        self::assertSame($initial['baseVersion'], $selected['baseVersion']);
        self::assertNotSame($initial['version'], $selected['version']);
        self::assertSame('4K best', $selected['routes']['id:4']['profiles']['id:3']);

        $manager = new RequestManager($this->client, $this->cache);
        $version = RequestManager::version($this->client->item);
        $edit = ['optionsVersion' => $selected['version'], 'destination' => 'id:4', 'profile' => 'id:3'];
        $this->client->serviceReads = [];
        $this->client->alteredProfileServer = 4;
        self::assertSame('stale-options', $manager->execute('edit', 41, $version, static fn (): bool => true, $edit));
        self::assertSame([null, 0, 4], $this->client->serviceReads);
        self::assertSame([], $this->client->editBodies);

        $this->client->serviceReads = [];
        $this->client->alteredProfileServer = null;
        self::assertSame('edited', $manager->execute('edit', 41, $version, static fn (): bool => true, $edit));
        self::assertSame([null, 0, 4], $this->client->serviceReads);
        self::assertSame([['mediaType' => 'tv', 'seasons' => [1, 2], 'serverId' => 4, 'profileId' => 3]], $this->client->editBodies);
        self::assertSame([], $this->client->item['tags']);
    }
}

final class SeerrActionFixtureClient extends JellyseerrClient
{
    public array $item = [
        'id' => 41, 'status' => 1, 'type' => 'tv', 'is4k' => true, 'createdAt' => '2026-11-23T10:00:00.000Z',
        'media' => ['id' => 63, 'tmdbId' => 141, 'status' => 5, 'status4k' => 2],
        'seasons' => [['seasonNumber' => 2], ['seasonNumber' => 1]],
    ];
    public array $writes = [];
    public array $editBodies = [];
    public array $serviceReads = [];
    public int $serverCount = 1;
    public ?int $alteredProfileServer = null;
    public int $detailReads = 0;
    public bool $offline = false;
    public string $mode = 'normal';

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', 'action-fixture');
    }

    public function requestPageWithMetadata(int $page, string $filter = 'all', int $pageSize = 24): array
    {
        if ($this->offline) {
            throw new RuntimeException('Synthetic outage');
        }
        return ['pageInfo' => ['page' => $page, 'pages' => 1, 'pageSize' => $pageSize, 'results' => 1], 'results' => [$this->item]];
    }

    public function requestDetail(int $requestId): array
    {
        ++$this->detailReads;
        if ($this->offline) {
            throw new RuntimeException('Synthetic outage');
        }
        if ($this->item === []) {
            throw new RemoteRequestException('Missing', 404);
        }
        return $this->item;
    }

    public function tv(int $tmdbId): array
    {
        return ['name' => 'Fixture Series', 'id' => 141,
            'seasons' => array_map(static fn (int $season): array => ['seasonNumber' => $season, 'episodeCount' => 10], range(1, 5)),
            'mediaInfo' => ['id' => 63, 'tmdbId' => 141, 'mediaType' => 'tv',
                'requests' => [$this->item, ['id' => 42, 'is4k' => true, 'status' => 2, 'seasons' => [['seasonNumber' => 4]]],
                    ['id' => 43, 'is4k' => false, 'status' => 2, 'seasons' => [['seasonNumber' => 3]]]],
                'seasons' => [['seasonNumber' => 5, 'status4k' => 5]]],
        ];
    }

    public function requestServices(string $mediaType, ?int $id = null): array
    {
        $this->serviceReads[] = $id;
        $servers = array_map(static fn (int $id): array => [
            'name' => 'Fixture Sonarr ' . $id, 'id' => $id, 'is4k' => true, 'isDefault' => $id === 0,
            'activeProfileId' => 2, 'activeDirectory' => '/fixture/series',
        ], range(0, $this->serverCount - 1));
        $profiles = [['id' => 2, 'name' => '4K']];
        if ($id !== $this->alteredProfileServer) {
            $profiles[] = ['id' => 3, 'name' => '4K best'];
        }

        return $id === null ? $servers : ['server' => $servers[$id], 'rootFolders' => [['path' => '/fixture/series']], 'profiles' => $profiles];
    }

    public function editRequest(int $requestId, array $payload): bool
    {
        $this->editBodies[] = $payload;
        $this->item['seasons'] = array_map(static fn (int $season): array => ['seasonNumber' => $season], $payload['seasons']);
        $this->item['profileId'] = $payload['profileId'];
        $this->item['serverId'] = $payload['serverId'] ?? $this->item['serverId'];

        return true;
    }

    public function approve(int $requestId): void
    {
        $this->change('approve', $requestId, 2);
    }

    public function retry(int $requestId): void
    {
        $this->change('retry', $requestId, 2);
    }

    private function change(string $action, int $requestId, int $status): void
    {
        $this->writes[] = "$action:$requestId";
        if ($this->mode === 'rate-limited') {
            throw new RemoteRequestException('Synthetic rate limit', 429);
        }
        if ($this->mode !== 'timeout-before-change') {
            $this->item['status'] = $status;
        }
        if ($this->mode !== 'normal') {
            throw new RuntimeException('Synthetic write timeout');
        }
    }
}
