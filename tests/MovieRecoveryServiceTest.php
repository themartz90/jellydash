<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Container;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyfin\HistoryLibraryBackfillService;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\MovieIdentityRepository;
use Mk\Framework\Jellyfin\MovieRecoveryService;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\MovieReplacementService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class MovieRecoveryServiceTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_movie_recovery_';
    private Database $database;
    private \Dibi\Connection $connection;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';
    private string $sqlitePath = '';
    private MovieIdentityRepository $identities;
    private MovieReplacements $replacements;
    private MovieRecoveryService $service;
    private int $now = 1000000;
    private string $server = 'server-one';
    /** @var array<string, array<string, mixed>> */
    private array $live = [];
    /** @var list<string> */
    private array $requests = [];
    private ?\Closure $override = null;
    private TestHandler $logs;
    private LoggerInterface $originalLogger;

    protected function setUp(): void
    {
        $this->originalLogger = Container::logger();
        $this->logs = new TestHandler();
        Container::set('logger', new Logger('movie-recovery-tests', [$this->logs]));
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'jellydash-movie-recovery-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
            $this->database = Database::sqlite($this->sqlitePath);
            $this->connection = $this->database->getDibi();
        } else {
            $config = ['driver' => DATABASE_DRIVER_DIBI, 'host' => DATABASE_HOST,
                'username' => DATABASE_USERNAME, 'password' => DATABASE_PASSWORD];
            if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
                $config['port'] = (int) DATABASE_PORT;
            }
            $this->admin = new \Dibi\Connection($config);
            $this->databaseName = self::DATABASE_PREFIX . getmypid() . '_' . bin2hex(random_bytes(4));
            $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
            $config['database'] = $this->databaseName;
            $this->connection = new \Dibi\Connection($config);
            $this->database = new Database($this->connection);
        }
        new PlayHistoryRepository($this->database);
        $this->identities = new MovieIdentityRepository($this->database, 'test-server');
        $this->replacements = new MovieReplacements($this->database, 'test-server');
        $client = new JellyfinClient(requester: function (string $path, string $method): mixed {
            self::assertSame('GET', $method);
            $this->requests[] = $path;
            if ($this->override !== null) {
                $result = ($this->override)($path);
                if ($result !== null) {
                    return $result;
                }
            }
            if ($path === '/System/Info/Public') {
                return ['Id' => $this->server];
            }
            if ($path === '/Library/VirtualFolders') {
                return [['Name' => 'Movies', 'Locations' => ['/media/movies']],
                    ['Name' => 'Other Movies', 'Locations' => ['/media/other']]];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            if (isset($query['Ids'])) {
                $items = isset($this->live[$query['Ids']]) ? [$this->live[$query['Ids']]] : [];
                return ['Items' => $items, 'TotalRecordCount' => count($items)];
            }
            self::assertSame('SortName', $query['SortBy']);
            self::assertStringNotContainsString('ProductionYear', $query['Fields']);
            $items = array_values($this->live);
            return ['Items' => array_slice($items, (int) $query['StartIndex'], (int) $query['Limit']),
                'TotalRecordCount' => count($items)];
        });
        $this->service = new MovieRecoveryService($this->database, $client, $this->identities,
            $this->replacements, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        Container::set('logger', $this->originalLogger);
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/D', $this->databaseName) !== 1) {
                throw new \RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
        foreach ([$this->sqlitePath, $this->sqlitePath . '-shm', $this->sqlitePath . '-wal'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testCapturedMovieAutomaticallyRecoversBeforeReplacementIsPlayed(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $before = $this->rows();
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();

        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertTrue($this->service->status()['repairs'][0]['automatic']);
        self::assertSame($before, $this->rows());
        self::assertSame(1, (int) $this->connection->select('COUNT(*)')->from('play_history')->fetchSingle());
    }

    public function testBoxSetCollapseCannotStallLegacyMovieSuggestions(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->simulateDefaultBoxSetCollapse();
        $this->settle();

        self::assertSame(1, $this->service->status()['suggestionCount']);
        self::assertSame('new', $this->service->status()['suggestions'][0]['candidates'][0]['id']);
        $this->assertMetadataQueriesDisableBoxSetCollapse();
    }

    public function testBoxSetCollapseCannotStallAutomaticMovieReconnection(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        $before = $this->rows();
        $this->live = ['new' => $this->movie('new')];
        $this->simulateDefaultBoxSetCollapse();
        $this->settle();

        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertSame($before, $this->rows());
        $this->assertMetadataQueriesDisableBoxSetCollapse();
    }

    public function testServerIgnoringCollapseFlagStillCannotSupplyBoxSetsAsMovies(): void
    {
        $this->seed('old');
        $this->override = static fn (string $path): mixed => str_contains($path, 'StartIndex=')
            ? ['Items' => [['Id' => 'collection', 'Name' => 'Example collection', 'Type' => 'BoxSet']], 'TotalRecordCount' => 1]
            : null;

        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testRecoveryFailureLogsTheCauseWithoutPuttingItInPublicStatus(): void
    {
        $cause = new \RuntimeException('Specific fixture metadata failure');
        $this->override = static function (string $path) use ($cause): mixed {
            if ($path === '/System/Info/Public') {
                throw $cause;
            }
            return null;
        };

        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertTrue($this->logs->hasErrorRecords());
        $record = $this->logs->getRecords()[0];
        self::assertSame($cause, $record->context['exception']);
        self::assertSame($cause->getMessage(), $record->message);
        self::assertStringNotContainsString('Specific fixture', $this->service->status()['message']);
    }

    public function testLoggerFailureCannotPreventRecoveryRetryOrLeaseRelease(): void
    {
        Container::set('logger', new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('Fixture log destination is unavailable.');
            }
        });
        $this->override = static function (string $path): mixed {
            if ($path === '/System/Info/Public') {
                throw new \RuntimeException('Fixture metadata request failed.');
            }
            return null;
        };

        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame($this->now + 60, $this->identities->state()['retryAfter']);
        self::assertSame('Movie metadata is unavailable. Recovery will retry.', $this->service->status()['message']);
        $lease = $this->identities->acquireLease($this->now);
        self::assertNotNull($lease);
        $this->identities->releaseLease($lease);
    }

    private function simulateDefaultBoxSetCollapse(): void
    {
        $this->override = static function (string $path): mixed {
            if (!str_starts_with($path, '/Items?')) {
                return null;
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            return ($query['CollapseBoxSetItems'] ?? '') === 'false' ? null
                : ['Items' => [['Id' => 'collection', 'Name' => 'Example collection', 'Type' => 'BoxSet']], 'TotalRecordCount' => 1];
        };
    }

    private function assertMetadataQueriesDisableBoxSetCollapse(): void
    {
        $lookups = 0;
        foreach ($this->requests as $path) {
            if (str_starts_with($path, '/Items?')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                self::assertSame('false', $query['CollapseBoxSetItems'] ?? null);
                $lookups += isset($query['Ids']) ? 1 : 0;
            }
        }
        self::assertGreaterThan(0, $lookups);
    }

    public function testLibraryContainmentUsesPlatformPathRulesAndLongestRoot(): void
    {
        $method = new \ReflectionMethod(MovieRecoveryService::class, 'identity');
        foreach ([
            ['c:\\MEDIA\\other\\new.mkv', 'C:\\Media\\Other\\', 'Other Movies'],
            ['\\\\SERVER\\Share\\Nested\\new.mkv', '//server/share', 'Other Movies'],
            ['/Media/Other/new.mkv', '/media/other', ''],
            ['C:/Media/Otherness/new.mkv', 'C:/Media/Other', ''],
        ] as [$path, $root, $expected]) {
            $identity = $method->invoke($this->service, array_replace($this->movie('new'), ['Path' => $path]),
                $this->server, ['roots' => [['path' => $root, 'name' => 'Other Movies']]]);
            self::assertSame($expected, $identity['library']);
        }
        $identity = $method->invoke($this->service, array_replace($this->movie('new'), ['Path' => 'c:/media/other/new.mkv']),
            $this->server, ['roots' => [['path' => 'C:/Media', 'name' => 'Movies'],
                ['path' => 'C:/Media/Other', 'name' => 'Other Movies']]]);
        self::assertSame('Other Movies', $identity['library']);
    }

    public function testWindowsCaseCannotHideCrossLibraryReplacement(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123'],
            'library' => 'Movies']);
        $this->live = ['new' => array_replace($this->movie('new'), ['Path' => 'c:\\media\\other\\new.mkv'])];
        $this->override = static fn (string $path): mixed => $path === '/Library/VirtualFolders'
            ? [['Name' => 'Other Movies', 'Locations' => ['C:\\Media\\Other']]] : null;
        $this->settle();
        self::assertSame([], $this->replacements->all());
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testConfirmRejectsHistoryThatChangedAfterSuggestion(): void
    {
        foreach (['episode', 'library', 'deleted', 'upstream', 'unknown-library'] as $change) {
            $source = 'source-' . $change;
            $this->seed($source);
            if ($change === 'unknown-library') {
                $this->connection->update('play_history', ['library' => '', 'library_resolved_at' => null])
                    ->where('item_id = %s', $source)->execute();
            }
            $this->live = ['new' => $this->movie('new')];
            $this->now += 301;
            $this->settle();
            self::assertNotEmpty($this->identities->get($source)['candidateIds']);
            if ($change === 'deleted') {
                $this->connection->delete('play_history')->where('item_id = %s', $source)->execute();
            } elseif ($change === 'episode') {
                $this->seed($source);
                $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', $source)->execute();
            } elseif ($change === 'upstream') {
                $this->seed('upstream');
                $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', 'upstream')->execute();
                $this->replacements->link('upstream', $source);
            } else {
                if ($change === 'library') {
                    $this->seed($source);
                }
                $row = $this->connection->select('id')->from('play_history')->where('item_id = %s', $source)->orderBy('id DESC')->fetchSingle();
                $this->connection->update('play_history', ['library' => 'Other Movies', 'library_resolved_at' => '2026-10-11 12:00:00'])
                    ->where('id = %i', $row)->execute();
            }
            $before = $this->rows();
            $aliases = $this->replacements->all();
            try {
                $this->service->confirm($source, 'new');
                self::fail('Changed source history must reject ' . $change);
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
            self::assertSame($aliases, $this->replacements->all());
            self::assertSame($before, $this->rows());
            self::assertArrayNotHasKey('repairAfter', $this->identities->get($source));
        }
    }

    public function testGuidEquivalentHistoryConflictsCannotEscapeConfirmationOrReview(): void
    {
        $source = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $alternate = 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA';
        $this->seed($source);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->seed($alternate);
        foreach (['episode', 'library'] as $conflict) {
            $this->connection->update('play_history', ['item_type' => $conflict === 'episode' ? 'Episode' : 'Movie',
                'library' => 'Other Movies'])->where('item_id = %s', $alternate)->execute();
            $error = null;
            try {
                $this->service->confirm($source, 'new');
            } catch (\RuntimeException $exception) {
                $error = $exception;
            }
            self::assertNotNull($error, 'Equivalent GUID ' . $conflict . ' evidence must reject the alias.');
            self::assertSame([], $this->replacements->all());
            self::assertArrayNotHasKey('repairAfter', $this->identities->get($source));
        }
        $this->connection->delete('play_history')->where('item_id = %s', $alternate)->execute();
        $this->service->confirm($source, 'new');
        $this->seed($alternate);
        $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', $alternate)->execute();
        self::assertTrue($this->service->status()['repairs'][0]['needsReview']);
    }

    public function testGuidEquivalentTargetAndIncomingMemberConflictsAreChecked(): void
    {
        $source = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $alternate = 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB';
        $this->seed('old');
        $this->replacements->link('old', $alternate);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->seed($source);
        $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', $source)->execute();
        self::assertTrue($this->service->status()['repairs'][0]['needsReview']);
        $error = null;
        try {
            $this->service->confirm($alternate, 'new');
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertNotNull($error);
        self::assertSame(['old' => $alternate], $this->replacements->all());
    }

    public function testOpaqueCaseAndHyphensDoNotExpandRecoveryHistoryEvidence(): void
    {
        $this->seed('a-b');
        $this->seed('ab');
        $this->seed('A-B');
        $this->connection->update('play_history', ['item_type' => 'Episode'])->where($this->database->getPlatform()->isSqlite()
            ? 'item_id COLLATE BINARY IN %in' : 'BINARY item_id IN %in', ['ab', 'A-B'])->execute();
        self::assertSame('Movie', $this->connection->select('item_type')->from('play_history')
            ->where($this->database->getPlatform()->isSqlite()
                ? 'item_id COLLATE BINARY = %s' : 'BINARY item_id = %s', 'a-b')->fetchSingle());
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->confirm('a-b', 'new');
        self::assertSame('new', $this->replacements->resolve('a-b'));
        self::assertFalse($this->service->status()['repairs'][0]['needsReview']);
    }

    public function testGuidEquivalentIncomingAliasTargetsCannotHideSourceConflict(): void
    {
        $source = 'cccccccccccccccccccccccccccccccc';
        $alternate = 'CCCCCCCC-CCCC-CCCC-CCCC-CCCCCCCCCCCC';
        $this->seed($source);
        $this->seed('upstream');
        $this->replacements->link('upstream', $alternate);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', 'upstream')->execute();
        $error = null;
        try {
            $this->service->confirm($source, 'new');
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertNotNull($error);
        self::assertSame(['upstream' => $alternate], $this->replacements->all());
        self::assertTrue($this->service->status()['repairs'][0]['needsReview']);
    }

    public function testGuidEquivalentSavedProviderConflictRejectsStaleConfirm(): void
    {
        $source = 'dddddddddddddddddddddddddddddddd';
        $alternate = 'DDDDDDDD-DDDD-DDDD-DDDD-DDDDDDDDDDDD';
        $this->seed($source);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->put($alternate, ['serverId' => $this->server, 'title' => 'Example Movie',
            'providers' => ['tmdb' => '999'], 'library' => 'Movies']);
        $error = null;
        try {
            $this->service->confirm($source, 'new');
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertNotNull($error);
        self::assertSame([], $this->replacements->all());
        self::assertArrayNotHasKey('repairAfter', $this->identities->get($source));
        $this->now += 301;
        $this->settle();
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testGuidEquivalentConflictLatchDisablesAutomaticButKeepsManualConfirmation(): void
    {
        $source = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $alternate = 'EEEEEEEE-EEEE-EEEE-EEEE-EEEEEEEEEEEE';
        $this->seed($source);
        $evidence = ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123'],
            'library' => 'Movies'];
        $this->put($source, $evidence);
        $this->put($alternate, $evidence + ['conflicted' => true]);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame([], $this->replacements->all());
        self::assertTrue($this->identities->get($source)['conflicted']);
        self::assertSame(1, $this->service->status()['suggestionCount']);
        $this->service->confirm($source, 'new');
        self::assertSame('new', $this->replacements->resolve($source));
    }

    public function testEquivalentLatchObservedDuringTargetCheckStillBlocksAutomaticWrite(): void
    {
        $source = 'ffffffffffffffffffffffffffffffff';
        $alternate = 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF';
        $this->seed($source);
        $evidence = ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123'],
            'library' => 'Movies'];
        $this->put($source, $evidence);
        $this->live = ['new' => $this->movie('new')];
        $this->service->runBatch();
        $this->service->runBatch();
        $this->service->runBatch();
        $this->override = function (string $path) use ($alternate, $evidence): mixed {
            if (str_contains($path, 'Ids=new')) {
                $this->identities->put($alternate, $evidence + ['conflicted' => true]);
            }
            return null;
        };
        $this->service->runBatch();
        self::assertSame([], $this->replacements->all());
    }

    public function testOpaqueSavedIdentityVariantsDoNotContaminateAutomaticRecovery(): void
    {
        $this->seed('a-b');
        $this->put('a-b', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123'],
            'library' => 'Movies']);
        foreach (['ab', 'A-B'] as $id) {
            $this->put($id, ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '999'],
                'library' => 'Other Movies', 'conflicted' => true]);
        }
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('a-b'));
    }

    public function testLinkedGroupReviewIncludesEquivalentSavedIdentityConflicts(): void
    {
        $source = 'dddddddddddddddddddddddddddddddd';
        $alternate = 'DDDDDDDD-DDDD-DDDD-DDDD-DDDDDDDDDDDD';
        $this->seed($source);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->confirm($source, 'new');
        $journal = $this->identities->get($source);
        $aliases = $this->replacements->all();
        $history = $this->rows();
        $target = $this->identities->get('new');
        foreach ([['providers' => ['tmdb' => '999']], ['year' => 1999], ['library' => 'Other Movies'],
            ['serverId' => 'another-server']] as $conflict) {
            $this->put($alternate, array_replace($target, $conflict));
            self::assertTrue($this->service->status()['repairs'][0]['needsReview'], json_encode($conflict));
            self::assertSame($aliases, $this->replacements->all());
            self::assertSame($journal, $this->identities->get($source));
            self::assertSame($history, $this->rows());
        }
        $this->put($alternate, array_replace($target, ['conflicted' => true]));
        self::assertFalse($this->service->status()['repairs'][0]['needsReview'], 'Agreeing metadata with only a historical latch stays manually confirmable.');
    }

    public function testLinkedGroupReviewKeepsOpaqueSavedIdentitySpellingsSeparate(): void
    {
        $this->seed('a-b');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->confirm('a-b', 'new');
        foreach (['ab', 'A-B'] as $id) {
            $this->put($id, ['serverId' => $this->server, 'providers' => ['tmdb' => '999'], 'library' => 'Other Movies']);
        }
        self::assertFalse($this->service->status()['repairs'][0]['needsReview']);
    }

    public function testConflictingHistoryAndNonMovieReappearanceClearSavedProposals(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', 'old')->execute();
        // Recovery may already have a saved source queued when a writer changes its type.
        $method = new \ReflectionMethod(MovieRecoveryService::class, 'recover');
        $lease = $this->identities->acquireLease($this->now);
        try {
            $method->invoke($this->service, 'old', $this->identities->state(), []);
        } finally {
            $this->identities->releaseLease($lease);
        }
        self::assertSame([], $this->identities->get('old')['candidateIds']);
        $this->connection->update('play_history', ['item_type' => 'Movie'])->where('item_id = %s', 'old')->execute();
        $this->put('old', array_replace($this->identities->get('old'), ['candidateIds' => ['new'], 'absent' => true]));
        $this->override = fn (string $path): mixed => str_contains($path, 'Ids=old')
            ? ['Items' => [['Id' => 'old', 'Type' => 'Episode', 'Name' => 'Episode']], 'TotalRecordCount' => 1] : null;
        $lease = $this->identities->acquireLease($this->now);
        try {
            $method->invoke($this->service, 'old', $this->identities->state(), []);
        } finally {
            $this->identities->releaseLease($lease);
        }
        self::assertSame([], $this->identities->get('old')['candidateIds']);
        self::assertFalse($this->identities->get('old')['absent']);
    }

    public function testBackgroundCycleClearsSuggestionsWhoseMovieHistoryDisappeared(): void
    {
        foreach (['deleted', 'episode-only'] as $change) {
            $this->seed($change);
        }
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame(2, $this->service->status()['suggestionCount']);
        $this->connection->delete('play_history')->where('item_id = %s', 'deleted')->execute();
        $this->connection->update('play_history', ['item_type' => 'Episode'])->where('item_id = %s', 'episode-only')->execute();
        $this->now += 301;
        $this->settle();
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testStaleDismissRejectsLinkedAndUnofferedSources(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->confirm('old', 'new');
        $before = $this->identities->get('old');
        try {
            $this->service->dismiss('old');
            self::fail('A linked source cannot be kept separate by a stale action.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Refresh', $error->getMessage());
        }
        self::assertSame($before, $this->identities->get('old'));
        self::assertSame(0, $this->service->status()['dismissedCount']);
    }

    public function testDismissRejectsChangedHistoryAndRepeatedOrdinaryDismissIsIdempotent(): void
    {
        $this->seed('old');
        $this->seed('other-old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->connection->delete('play_history')->where('item_id = %s', 'old')->execute();
        $error = null;
        try {
            $this->service->dismiss('old');
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertNotNull($error);
        self::assertEmpty($this->identities->get('old')['suppressed'] ?? false);
        $this->service->dismiss('other-old');
        $before = $this->identities->get('other-old');
        $this->service->dismiss('other-old');
        self::assertSame($before, $this->identities->get('other-old'));
    }

    public function testRepeatedConfirmPreservesTheCompleteUndoJournal(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->confirm('old', 'new');
        $before = $this->identities->get('old');
        $this->service->confirm('old', 'new');
        self::assertSame($before, $this->identities->get('old'));
        $this->service->undo('old');
        self::assertSame([], $this->replacements->all());
    }

    public function testCliChainAdvancementAndStaleConfirmationKeepAnHonestGroupJournal(): void
    {
        $this->seed('old');
        $this->seed('other-old');
        $this->live = ['middle' => $this->movie('middle')];
        $this->settle();
        $this->service->confirm('old', 'middle');
        $this->service->confirm('other-old', 'middle');
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->service->runBatch();
        $this->service->runBatch();
        $client = new JellyfinClient(requester: function (string $path): mixed {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $items = isset($this->live[$query['Ids']]) ? [$this->live[$query['Ids']]] : [];
            return ['Items' => $items, 'TotalRecordCount' => count($items)];
        });
        $manual = new MovieReplacementService($this->database, $client, $this->replacements);
        $manual->apply('middle', 'new', true);
        $journal = $this->identities->get('middle');
        self::assertEquals(['old' => 'middle', 'other-old' => 'middle', 'middle' => null], $journal['repairBefore'] ?? []);
        $this->service->confirm('middle', 'new');
        self::assertSame($journal, $this->identities->get('middle'));
        $manual->apply('middle', 'new', true);
        self::assertSame($journal, $this->identities->get('middle'));
        $this->service->undo('middle');
        self::assertSame(['old' => 'middle', 'other-old' => 'middle'], $this->replacements->all());
        self::assertTrue($this->identities->get('middle')['suppressed']);
        self::assertTrue($this->identities->get('old')['suppressed']);
    }

    public function testEmptyLegacyJournalUsesTruthfulSingleMappingRemoval(): void
    {
        $this->seed('old');
        $this->replacements->link('old', 'new');
        $this->put('old', ['repairBefore' => [], 'repairAfter' => [], 'title' => 'Old movie']);
        self::assertSame('remove', $this->service->status()['repairs'][0]['undoMode'] ?? null);
        $this->service->undo('old');
        self::assertSame([], $this->replacements->all());
        self::assertSame(1, $this->service->status()['dismissedCount']);
    }

    public function testCurrentCliJournalHidesItsLegacyMemberWithoutSavedIdentity(): void
    {
        $this->advanceLegacyChainWithCli();

        self::assertNull($this->identities->get('old'));
        $status = $this->service->status();
        self::assertSame(['middle'], array_column($status['repairs'], 'sourceId'));
        self::assertSame(['group'], array_column($status['repairs'], 'undoMode'));
        self::assertSame(1, $status['repairCount']);
    }

    public function testDirectLegacyUndoCannotInvalidateTheCurrentCliGroupJournal(): void
    {
        $this->advanceLegacyChainWithCli();
        $aliases = $this->replacements->all();
        $journal = $this->identities->get('middle');

        foreach (['undo', 'removeMapping'] as $action) {
            $error = null;
            try {
                if ($action === 'undo') {
                    $this->service->undo('old');
                } else {
                    $this->service->removeMapping('old', 'new');
                }
            } catch (\RuntimeException $exception) {
                $error = $exception;
            }
            self::assertNotNull($error, 'A legacy member owned by the current group journal cannot be removed individually.');
            self::assertStringContainsString('later repair changed', $error->getMessage());
            self::assertSame($aliases, $this->replacements->all());
            self::assertSame($journal, $this->identities->get('middle'));
            self::assertNull($this->identities->get('old'));
        }

        $this->service->undo('middle');
        self::assertSame(['old' => 'middle'], $this->replacements->all());
        self::assertTrue($this->identities->get('old')['suppressed']);
        self::assertTrue($this->identities->get('middle')['suppressed']);
    }

    public function testLegacyMappingWithoutIdentityStillAllowsSingleMappingUndo(): void
    {
        $this->seed('old');
        $this->replacements->link('old', 'middle');
        self::assertNull($this->identities->get('old'));
        self::assertSame(['old'], array_column($this->service->status()['repairs'], 'sourceId'));
        self::assertSame('remove', $this->service->status()['repairs'][0]['undoMode']);
        $this->service->undo('old');
        self::assertSame([], $this->replacements->all());
        self::assertTrue($this->identities->get('old')['suppressed']);
    }

    private function advanceLegacyChainWithCli(): void
    {
        $this->seed('old');
        $this->replacements->link('old', 'middle');
        $client = new JellyfinClient(requester: function (string $path): array {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $items = ($query['Ids'] ?? '') === 'new' ? [$this->movie('new')] : [];
            return ['Items' => $items, 'TotalRecordCount' => count($items)];
        });
        (new MovieReplacementService($this->database, $client, $this->replacements))->apply('middle', 'new', true);
    }

    public function testRecoveryQueueHasExactStableOrderAfterAliasDeduplication(): void
    {
        foreach (['zulu', 'bravo', 'Alpha', 'alpha', 'upstream'] as $id) {
            $this->seed($id);
        }
        $this->replacements->link('upstream', 'bravo');
        $method = new \ReflectionMethod(MovieRecoveryService::class, 'recordedMovieIds');
        self::assertSame(['Alpha', 'alpha', 'bravo', 'zulu'], $method->invoke($this->service));
        $this->connection->query('CREATE INDEX recovery_input_order ON play_history (item_type, item_id DESC)');
        self::assertSame(['Alpha', 'alpha', 'bravo', 'zulu'], $method->invoke($this->service));
    }

    public function testInterruptedQueueResumesAndNextCycleRevisitsEarlierNewIds(): void
    {
        foreach (['z', 'm', 'a'] as $id) {
            $this->seed($id);
        }
        $this->seed('b');
        $this->seed('n');
        $stateMethod = new \ReflectionMethod(MovieRecoveryService::class, 'currentState');
        $inventoryMethod = new \ReflectionMethod(MovieRecoveryService::class, 'inventoryPage');
        $lease = $this->identities->acquireLease($this->now);
        try {
            $state = $stateMethod->invoke($this->service);
            $state['recoveryIds'] = ['a', 'm', 'z'];
            $state['recoveryOffset'] = 1;
            $inventoryMethod->invokeArgs($this->service, [&$state, $this->now]);
            self::assertSame(['a', 'b', 'm', 'n', 'z'], $state['recoveryIds']);
            self::assertSame(2, $state['recoveryOffset']);

            $this->connection->delete('play_history')->where('item_id = %s', 'm')->execute();
            $removed = $stateMethod->invoke($this->service);
            $removed['recoveryIds'] = $state['recoveryIds'];
            $removed['recoveryOffset'] = 2;
            $inventoryMethod->invokeArgs($this->service, [&$removed, $this->now]);
            self::assertSame(0, $removed['recoveryOffset']);

            $this->replacements->link('n', 'a');
            $complete = $stateMethod->invoke($this->service);
            $complete['recoveryIds'] = $state['recoveryIds'];
            $complete['recoveryOffset'] = count($state['recoveryIds']);
            $inventoryMethod->invokeArgs($this->service, [&$complete, $this->now]);
            self::assertSame(['a', 'b', 'z'], $complete['recoveryIds']);
            self::assertSame(0, $complete['recoveryOffset']);
        } finally {
            $this->identities->releaseLease($lease);
        }
    }

    public function testLegacyUnjournaledChainKeepsEverySurvivingAliasManageable(): void
    {
        $this->seed('old');
        $this->live = ['middle' => $this->movie('middle')];
        $this->settle();
        $this->service->confirm('old', 'middle');
        $this->replacements->link('middle', 'new');
        $status = $this->service->status();
        self::assertEqualsCanonicalizing(['old', 'middle'], array_column($status['repairs'], 'sourceId'));
        self::assertSame(['remove', 'remove'], array_column($status['repairs'], 'undoMode'));
        $this->service->removeMapping('old', 'new');
        self::assertSame(['middle' => 'new'], $this->replacements->all());
    }

    public function testJournalFailureRollsBackTheWholeAliasOperation(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $before = $this->identities->get('old');
        $listener = static function (\Dibi\Event $event): void {
            if ($event->type === \Dibi\Event::INSERT && str_contains($event->sql, 'movie_identity_items')
                && str_contains($event->sql, 'repairBefore')) {
                throw new \RuntimeException('Synthetic journal failure.');
            }
        };
        $this->connection->onEvent[] = $listener;
        try {
            $error = null;
            try {
                $this->service->confirm('old', 'new');
            } catch (\RuntimeException $exception) {
                $error = $exception;
            }
            self::assertNotNull($error);
        } finally {
            array_pop($this->connection->onEvent);
        }
        self::assertSame([], $this->replacements->all());
        self::assertSame($before, $this->identities->get('old'));
    }

    public function testConcurrentConflictingWriterSerializesAfterAliasCommitAndRequiresReview(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-recovery-race-' . bin2hex(random_bytes(8));
        $config = $this->connection->getConfig();
        $process = proc_open([PHP_BINARY, ROOT_DIR . '/tests/fixtures/movie-recovery-history-writer.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode(['config' => $config, 'marker' => $marker], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $triggered = false;
        $listener = function (\Dibi\Event $event) use ($marker, &$triggered): void {
            if (!$triggered && $event->type === \Dibi\Event::INSERT && str_contains($event->sql, 'movie_replacements')) {
                $triggered = true;
                file_put_contents($marker . '.go', 'go');
                usleep(200000);
                clearstatcache();
                self::assertFileDoesNotExist($marker . '.done', 'The writer must not commit a conflict during the alias transaction.');
            }
        };
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($marker . '.ready') && microtime(true) < $deadline) {
                usleep(10000);
                clearstatcache();
            }
            self::assertFileExists($marker . '.ready');
            $this->connection->onEvent[] = $listener;
            $this->service->confirm('old', 'new');
            self::assertTrue($triggered);
            $stderr = stream_get_contents($pipes[2]);
            self::assertSame('', $stderr);
            self::assertSame(0, proc_close($process));
            $process = null;
            self::assertTrue(is_file($marker . '.done') || is_file($marker . '.queued'));
            (new PlayHistoryRepository($this->database))->logActiveStreams([]);
            self::assertSame('new', $this->replacements->resolve('old'));
            // Observe the later writer as a new request, with a fresh SQLite read snapshot.
            $this->connection->disconnect();
            $this->connection->connect();
            self::assertSame(1, (int) $this->connection->select('COUNT(*)')->from('play_history')->where('item_type = %s', 'Episode')->fetchSingle());
            self::assertTrue($this->service->status()['repairs'][0]['needsReview']);
        } finally {
            $this->connection->onEvent = array_values(array_filter($this->connection->onEvent, static fn ($entry): bool => $entry !== $listener));
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            @unlink($marker . '.queued');
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            foreach (['.ready', '.go', '.done'] as $suffix) {
                if (is_file($marker . $suffix)) {
                    unlink($marker . $suffix);
                }
            }
        }
    }

    public function testBackfillCommittedDuringRemoteCheckRejectsConfirmation(): void
    {
        $this->prepareUnresolvedBackfillPlay();
        $second = new \Dibi\Connection($this->connection->getConfig());
        $lock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-backfill-check-' . bin2hex(random_bytes(8)) . '.lock';
        $backfill = new HistoryLibraryBackfillService(new Database($second),
            static fn (array $ids): array => array_fill_keys($ids, ['runtime_sec' => 3600, 'library' => 'Other Movies']), $lock);
        $written = false;
        $this->override = function (string $path) use ($backfill, &$written): mixed {
            if (!$written && str_contains($path, 'Ids=new')) {
                $written = true;
                self::assertSame('complete', $backfill->runBatch()['state']);
            }
            return null;
        };
        $before = $this->identities->get('old');
        try {
            $error = null;
            try {
                $this->service->confirm('old', 'new');
            } catch (\RuntimeException $exception) {
                $error = $exception;
            }
            self::assertTrue($written);
            self::assertNotNull($error);
            self::assertSame([], $this->replacements->all());
            self::assertSame($before, $this->identities->get('old'));
            self::assertSame(1, (int) $this->connection->select('COUNT(*)')->from('play_history')
                ->where('library = %s', 'Other Movies')->fetchSingle());
        } finally {
            $second->disconnect();
            if (is_file($lock)) {
                unlink($lock);
            }
        }
    }

    public function testConcurrentBackfillSerializesAfterAliasCommitAndRequiresReview(): void
    {
        $this->prepareUnresolvedBackfillPlay();
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-backfill-race-' . bin2hex(random_bytes(8));
        $process = proc_open([PHP_BINARY, ROOT_DIR . '/tests/fixtures/movie-recovery-backfill-writer.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode(['config' => $this->connection->getConfig(), 'marker' => $marker], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $triggered = false;
        $listener = function (\Dibi\Event $event) use ($marker, &$triggered): void {
            if (!$triggered && $event->type === \Dibi\Event::INSERT && str_contains($event->sql, 'movie_replacements')) {
                $triggered = true;
                file_put_contents($marker . '.go', 'go');
                $deadline = microtime(true) + 5;
                while (!is_file($marker . '.loaded') && microtime(true) < $deadline) {
                    usleep(10000);
                    clearstatcache();
                }
                self::assertFileExists($marker . '.loaded', 'Metadata lookup must run outside the History write guard.');
                usleep(200000);
                clearstatcache();
                self::assertFileDoesNotExist($marker . '.done', 'Backfill must not commit conflicting library evidence during the alias transaction.');
            }
        };
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($marker . '.ready') && microtime(true) < $deadline) {
                usleep(10000);
                clearstatcache();
            }
            self::assertFileExists($marker . '.ready');
            $this->connection->onEvent[] = $listener;
            $this->service->confirm('old', 'new');
            self::assertTrue($triggered);
            self::assertSame('', stream_get_contents($pipes[2]));
            self::assertSame(0, proc_close($process));
            $process = null;
            self::assertFileExists($marker . '.done');
            $this->connection->disconnect();
            $this->connection->connect();
            self::assertSame(1, (int) $this->connection->select('COUNT(*)')->from('play_history')
                ->where('library = %s', 'Other Movies')->fetchSingle());
            self::assertSame('new', $this->replacements->resolve('old'));
            self::assertTrue($this->service->status()['repairs'][0]['needsReview']);
            self::assertSame(['old' => 'new'], $this->identities->get('old')['repairAfter']);
        } finally {
            $this->connection->onEvent = array_values(array_filter($this->connection->onEvent, static fn ($entry): bool => $entry !== $listener));
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            foreach (['.ready', '.go', '.loaded', '.done', '.lock'] as $suffix) {
                if (is_file($marker . $suffix)) {
                    unlink($marker . $suffix);
                }
            }
        }
    }

    private function prepareUnresolvedBackfillPlay(): void
    {
        $this->seed('old');
        $this->seed('old');
        $lastId = (int) $this->connection->select('MAX(id)')->from('play_history')->fetchSingle();
        $this->connection->update('play_history', ['library_resolved_at' => null, 'library_resolved_at_epoch' => null])
            ->where('id = %i', $lastId)->execute();
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
    }

    public function testSecondConnectionConflictCommittedDuringRemoteCheckRejectsConfirmation(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $second = new \Dibi\Connection($this->connection->getConfig());
        $history = new PlayHistoryRepository(new Database($second));
        $history->prepareHistoryImport();
        $written = false;
        $this->override = function (string $path) use ($history, &$written): mixed {
            if (!$written && str_contains($path, 'Ids=new')) {
                $written = true;
                $history->logActiveStreams([[
                    'id' => 'conflicting-play', 'itemId' => 'old', 'itemType' => 'Episode', 'itemName' => 'Episode',
                    'library' => 'Movies', 'libraryResolved' => true,
                ]], new \DateTimeImmutable('2026-10-11 12:05:00'));
            }
            return null;
        };
        try {
            $error = null;
            try {
                $this->service->confirm('old', 'new');
            } catch (\RuntimeException $exception) {
                $error = $exception;
            }
            self::assertTrue($written);
            self::assertNotNull($error);
            self::assertSame([], $this->replacements->all());
            self::assertArrayNotHasKey('repairAfter', $this->identities->get('old'));
        } finally {
            $second->disconnect();
        }
    }

    public function testFirstCompleteInventoryCanSuggestButCannotAutomaticallyLink(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123'],
            'year' => 2020, 'library' => 'Movies']);
        $this->live = ['new' => $this->movie('new')];
        $this->service->runBatch();
        $this->service->runBatch();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(1, $this->service->status()['suggestionCount']);
    }

    public function testLegacyHistoryOffersCandidateAndConfirmsWithoutRawIdCommands(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $status = $this->service->status();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame('new', $status['suggestions'][0]['candidates'][0]['id']);
        self::assertSame(1, $status['suggestions'][0]['plays']);
        $before = $this->rows();
        $this->service->confirm('old', 'new');
        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertFalse($this->service->status()['repairs'][0]['automatic']);
        self::assertSame($before, $this->rows());
    }

    public function testDifferentlyCasedRecordedIdsRemainSeparateRecoverySources(): void
    {
        $this->seed('old-A');
        $this->seed('OLD-A');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame(2, $this->service->status()['suggestionCount']);
        self::assertEqualsCanonicalizing(['old-A', 'OLD-A'], array_column($this->service->status()['suggestions'], 'sourceId'));
    }

    public function testExistingManualAliasesAreVisibleAndCanBeUndoneWithoutIdentityCapture(): void
    {
        $this->seed('old');
        $this->replacements->link('old', 'new');
        self::assertSame('old', $this->service->status()['repairs'][0]['sourceId']);
        $this->service->undo('old');
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(1, $this->service->status()['dismissedCount']);
    }

    public function testMultipleCopiesAreSuggestionsAndProviderConflictIsRejected(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie',
            'providers' => ['tmdb' => '123', 'imdb' => 'tt123'], 'year' => 2020, 'library' => 'Movies']);
        $this->live = ['copy-one' => $this->movie('copy-one'), 'copy-two' => $this->movie('copy-two'),
            'conflict' => $this->movie('conflict', ['Tmdb' => '123', 'Imdb' => 'tt999'])];
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertEqualsCanonicalizing(['copy-one', 'copy-two'],
            array_column($this->service->status()['suggestions'][0]['candidates'], 'id'));
    }

    public function testRejectingAContaminatedCandidateDoesNotCreateFalseUniqueness(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        (new PlayHistoryRepository($this->database))->logActiveStreams([[
            'id' => 'conflicting-session', 'itemId' => 'unsafe', 'itemType' => 'Episode',
            'itemName' => 'Episode', 'library' => 'Movies', 'libraryResolved' => true,
            'playMethod' => 'DirectPlay', 'watchedSec' => 300, 'runtimeSec' => 3600,
        ]], new \DateTimeImmutable('2026-10-11 12:00:00'));
        $this->live = ['safe' => $this->movie('safe'), 'unsafe' => $this->movie('unsafe')];
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(['safe'], array_column($this->service->status()['suggestions'][0]['candidates'], 'id'));
    }

    public function testMetadataOutageDoesNotProveDeletionAndRetriesWithoutDroppingEvidence(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        $this->live = ['new' => $this->movie('new')];
        $this->override = static function (string $path): mixed {
            if (str_contains($path, 'Ids=old')) {
                throw new \RuntimeException('private outage detail');
            }
            return null;
        };
        $this->service->runBatch();
        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(['tmdb' => '123'], $this->identities->get('old')['providers']);
        self::assertStringNotContainsString('private outage', $this->service->status()['message']);
        $count = count($this->requests);
        $this->service->runBatch();
        self::assertCount($count, $this->requests);
    }

    public function testPartialOrOverlappingInventoryCannotAuthorizeRecovery(): void
    {
        $this->seed('old');
        for ($i = 0; $i < 101; ++$i) {
            $id = 'movie-' . $i;
            $this->live[$id] = $this->movie($id);
        }
        $this->service->runBatch();
        self::assertSame(0, $this->service->status()['suggestionCount']);
        $this->override = fn (string $path): mixed => str_contains($path, 'StartIndex=100')
            ? ['Items' => [$this->movie('movie-0')], 'TotalRecordCount' => 101] : null;
        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testLateProviderMetadataCanEnableRecovery(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old', [])];
        $this->settle();
        self::assertSame([], $this->identities->get('old')['providers']);
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        self::assertSame(['imdb' => 'tt123', 'tmdb' => '123'], $this->identities->get('old')['providers']);
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
    }

    public function testRawPartialPayloadPreservesIdentityAndWrongServerIsIgnored(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $before = $this->identities->get('old');
        $calls = count($this->requests);
        $this->service->captureSessions([['NowPlayingItem' => ['Id' => 'old', 'Type' => 'Movie', 'ServerId' => $this->server]],
            ['NowPlayingItem' => $this->movie('wrong') + ['ServerId' => 'another-server']]]);
        self::assertSame($before['providers'], $this->identities->get('old')['providers']);
        self::assertSame($before['year'], $this->identities->get('old')['year']);
        self::assertNull($this->identities->get('wrong'));
        self::assertCount($calls, $this->requests);
    }

    public function testFirstPlaybackCaptureSurvivesDeletionBeforeInitialServerProbe(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        self::assertSame([], $this->requests);
        self::assertSame($this->server, $this->identities->get('old')['serverId']);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
    }

    public function testProvisionalCaptureFromDifferentServerCannotAnchorOldEvidenceToNewServer(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => 'old-server']]]);
        $this->replacements->link('old', 'new');
        self::assertSame('blocked', $this->service->runBatch()['state']);
        self::assertTrue($this->identities->state()['serverMismatch']);
        self::assertSame('old', $this->replacements->resolve('old'));
    }

    public function testAuthoritativeRemovedProviderDoesNotLeaveStaleIdentity(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $this->live['old']['ProviderIds'] = [];
        $this->now += 301;
        $this->settle();
        self::assertSame([], $this->identities->get('old')['providers']);
        self::assertTrue($this->identities->get('old')['conflicted']);
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(1, $this->service->status()['suggestionCount']);
    }

    public function testProviderCorrectionSupersedesOldRevisionAndBlocksAutomaticRepair(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $this->live['old']['ProviderIds'] = ['Tmdb' => '999'];
        $this->now += 301;
        $this->settle();
        self::assertSame(['tmdb' => '999'], $this->identities->get('old')['providers']);
        self::assertTrue($this->identities->get('old')['conflicted']);
        $this->live = ['new' => $this->movie('new', ['Tmdb' => '999'])];
        $this->now += 301;
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
    }

    public function testIncomingAliasIdentityConflictPreventsAutomaticChainAdvance(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '999']]);
        $this->put('middle', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        $this->replacements->link('old', 'middle');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertSame('middle', $this->replacements->resolve('middle'));
    }

    public function testUpstreamProviderConflictCannotBeHiddenByMissingIntermediateProvider(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['imdb' => 'tt111']]);
        $this->put('middle', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        $this->replacements->link('old', 'middle');
        $this->live = ['new' => $this->movie('new', ['Tmdb' => '123', 'Imdb' => 'tt222'])];
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertSame('middle', $this->replacements->resolve('middle'));
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testLeaseExpiryDuringNetworkCannotWriteAlias(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        $this->live = ['new' => $this->movie('new')];
        $this->service->runBatch();
        $this->service->runBatch();
        $this->service->runBatch();
        $this->override = function (string $path): mixed {
            if (str_contains($path, 'Ids=new')) {
                $this->now += 121;
            }
            return null;
        };
        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertArrayNotHasKey('repairAfter', $this->identities->get('old'));
    }

    public function testServerUuidChangeIsPermanentlyBlocked(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->server = 'server-two';
        $this->now += 301;
        self::assertSame('blocked', $this->service->runBatch()['state']);
        self::assertTrue($this->identities->state()['serverMismatch']);
        $this->server = 'server-one';
        self::assertSame('blocked', $this->service->runBatch()['state']);
        $this->expectException(\RuntimeException::class);
        $this->service->confirm('old', 'new');
    }

    public function testDismissAndResumeAreReversibleWithoutAutomaticRecreation(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->dismiss('old');
        $this->now += 301;
        $this->settle();
        self::assertSame(0, $this->service->status()['suggestionCount']);
        self::assertSame(1, $this->service->status()['dismissedCount']);
        $this->service->resume('old');
        $this->settle();
        self::assertSame(1, $this->service->status()['suggestionCount']);
    }

    public function testUnplayedIntermediateRecoversAndUndoRestoresIncomingAliases(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $this->live = ['middle' => $this->movie('middle')];
        $this->now += 301;
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertSame('new', $this->replacements->resolve('middle'));
        $this->service->undo('middle');
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertSame('middle', $this->replacements->resolve('middle'));
        $this->now += 301;
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertTrue($this->identities->get('old')['suppressed']);
        self::assertTrue($this->identities->get('middle')['suppressed']);
        $this->service->resume('old');
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
    }

    public function testLeaseCollisionDoesNotCallJellyfinOrMutateData(): void
    {
        $other = new MovieIdentityRepository($this->database, 'test-server');
        $lease = $other->acquireLease($this->now);
        self::assertNotNull($lease);
        self::assertSame('busy', $this->service->runBatch()['state']);
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old')]]);
        self::assertSame([], $this->requests);
        $other->releaseLease($lease);
    }

    public function testConfirmRevalidatesTargetAndRejectsUnOfferedChoice(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        unset($this->live['new']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer available');
        $this->service->confirm('old', 'new');
    }

    public function testUndoEarlierRepairRejectsChangedGroupWithoutTouchingLatestRepair(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        $this->live = ['middle' => $this->movie('middle')];
        $this->settle();
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame(['middle'], array_column($this->service->status()['repairs'], 'sourceId'));
        try {
            $this->service->undo('old');
            self::fail('Undoing an obsolete group must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('later repair changed', $error->getMessage());
        }
        self::assertSame('new', $this->replacements->resolve('old'));
        $this->service->undo('middle');
        self::assertSame('middle', $this->replacements->resolve('old'));
    }

    /** @return array<string, mixed> */
    private function movie(string $id, array $providers = ['Tmdb' => '123', 'Imdb' => 'tt123']): array
    {
        return ['Id' => $id, 'Type' => 'Movie', 'Name' => 'Example Movie', 'ProductionYear' => 2020,
            'ProviderIds' => $providers, 'Path' => '/media/movies/' . $id . '/movie.mkv'];
    }

    private function seed(string $id): void
    {
        (new PlayHistoryRepository($this->database))->logActiveStreams([[
            'id' => 'session-' . bin2hex(random_bytes(4)), 'itemId' => $id, 'itemType' => 'Movie', 'itemName' => 'Example Movie',
            'library' => 'Movies', 'libraryResolved' => true, 'playMethod' => 'DirectPlay',
            'watchedSec' => 300, 'runtimeSec' => 3600,
        ]], new \DateTimeImmutable('2026-10-11 12:00:00'));
    }

    private function settle(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $result = $this->service->runBatch();
            if ($result['state'] === 'unavailable' || $result['state'] === 'blocked') {
                self::fail('Unexpected recovery state: ' . json_encode($this->identities->state()));
            }
            if ($result['state'] === 'ready' && $this->identities->state()['inventoryStable']) {
                return;
            }
        }
        self::fail('Recovery did not finish its bounded cycle.');
    }

    /** @param array<string, mixed> $data */
    private function put(string $id, array $data): void
    {
        $lease = $this->identities->acquireLease($this->now);
        self::assertNotNull($lease);
        $this->identities->put($id, $data);
        $this->identities->releaseLease($lease);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(static function (\Dibi\Row $row): array {
            $values = $row->toArray();
            foreach ($values as $key => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $values[$key] = $value->format('Y-m-d H:i:s.uP');
                }
            }
            return $values;
        }, $this->connection->select('*')->from('play_history')->orderBy('id')->fetchAll());
    }
}
