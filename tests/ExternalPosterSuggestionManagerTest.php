<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ExternalPosterCache;
use Mk\Framework\Jellyfin\ExternalPosterManager;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ExternalPosterSuggestionManagerTest extends TestCase
{
    private ArtworkRepository $art;
    private ExternalPosterManager $manager;
    private string $cacheRoot;
    private int $requests = 0;

    protected function setUp(): void
    {
        $url = 'https://' . bin2hex(random_bytes(8)) . '.invalid';
        $this->art = new ArtworkRepository(Container::db(), ThemePlaybackExclusions::serverKey($url));
        $this->art->anchor('suggestion-test-server');
        $this->art->confirmLegacy();
        $this->cacheRoot = ROOT_DIR . '/internal/artwork-title-recovery/test-cache/' . bin2hex(random_bytes(12));
        $client = new JellyfinClient($url, 'synthetic-token', true, function (): array {
            ++$this->requests;
            throw new RuntimeException('Manager suggestion reads must not make HTTP requests.');
        });
        $this->manager = new ExternalPosterManager(
            Container::db(),
            $this->art,
            $client,
            new ExternalPosterCache($this->art->scope, $this->cacheRoot)
        );
    }

    protected function tearDown(): void
    {
        $db = Container::db()->getDibi();
        foreach (['artwork_external_searches', 'artwork_external_bodies', 'artwork_external_assets', 'artwork_external_bindings',
            'artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            if ($db->getDatabaseInfo()->hasTable($table)) {
                $db->delete($table)->where('source_key = %s', $this->art->scope)->execute();
            }
        }
        if (isset($this->cacheRoot) && is_dir($this->cacheRoot)) {
            $this->removeOwnedDirectory($this->cacheRoot);
        }
    }

    public function testStatusAndSuggestionReadsUseOnlyCachedMetadataAndDoNotChooseIt(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->addItem('movie-two', 'Same title');
        $this->addItem('other-library', 'Same title', library: 'Other movies');
        $this->seedDiscovery('movie-one');
        $groups = $this->manager->status()['review'];

        self::assertCount(2, $groups);
        self::assertSame(0, $this->requests);
        $group = $this->findGroup($groups, 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        self::assertSame('discovery', $suggestions['origin']);
        self::assertSame('Same title', $suggestions['queryTitle']);
        self::assertCount(1, $suggestions['candidates']);
        self::assertSame('未確認の題名', $suggestions['candidates'][0]['title']);
        self::assertSame(0, $this->requests);
        foreach (['movie-one', 'movie-two', 'other-library'] as $id) {
            self::assertNull($this->art->externalBinding($id)['tmdb_id']);
            self::assertSame('automatic', $this->art->externalBinding($id)['origin']);
        }
    }

    public function testSuggestionTokenCannotBeUsedForAnOtherSubset(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->addItem('movie-two', 'Same title');
        $this->seedDiscovery('movie-one');
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $subset = [$group['ids'][0]];
        $suggestions = $this->manager->suggestions($subset, $group['fingerprint']);

        try {
            $this->manager->select([$group['ids'][1]], $group['fingerprint'], $suggestions['candidates'][0]['token']);
            self::fail('A cached suggestion token must stay bound to its exact selected subset.');
        } catch (RuntimeException) {
            self::assertNull($this->art->externalBinding($group['ids'][1])['tmdb_id']);
            self::assertNull($this->art->externalBinding($group['ids'][0])['tmdb_id']);
        }
    }

    public function testSameTitleGroupsStaySeparateUntilAnExplicitManualSelection(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->addItem('movie-two', 'Same title', library: 'Other movies');
        $this->seedDiscovery('movie-one');
        $groups = $this->manager->status()['review'];
        $first = $this->findGroup($groups, 'Movies');
        $other = $this->findGroup($groups, 'Other movies');
        $suggestions = $this->manager->suggestions($first['ids'], $first['fingerprint']);

        self::assertNull($this->art->externalBinding('movie-one')['tmdb_id']);
        self::assertNull($this->art->externalBinding('movie-two')['tmdb_id']);
        $this->manager->select($first['ids'], $first['fingerprint'], $suggestions['candidates'][0]['token']);

        self::assertSame('1429', $this->art->externalBinding('movie-one')['tmdb_id']);
        self::assertSame('manual', $this->art->externalBinding('movie-one')['origin']);
        self::assertSame('未確認の題名', $this->art->externalBinding('movie-one')['selected_title']);
        self::assertNull($this->art->externalBinding($other['ids'][0])['tmdb_id']);
        self::assertSame('automatic', $this->art->externalBinding($other['ids'][0])['origin']);
    }

    public function testConfigurationChangeInvalidatesCachedSuggestionToken(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->seedDiscovery('movie-one');
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        $revision = (int) $this->manager->status()['configRevision'];
        $this->art->configureExternal(false, $revision);
        $this->art->configureExternal(true, $revision + 1);

        $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
    }

    public function testJellyfinIdentityChangeInvalidatesCachedSuggestionToken(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->seedDiscovery('movie-one');
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        $this->art->anchor('replacement-jellyfin-server');

        $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
    }

    public function testChangingAGroupMemberInvalidatesItsSuggestionToken(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->addItem('movie-two', 'Same title');
        $this->seedDiscovery('movie-one');
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        $item = $this->art->item('movie-two');
        $item['library'] = 'Other movies';
        $this->art->remember($item);

        $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
    }

    public function testChangingAnEpisodeParentInvalidatesItsSuggestionToken(): void
    {
        $this->addItem('episode-one', 'Episode title', type: 'Episode', seriesName: 'Parent series', posterId: 'parent-one');
        $this->seedDiscovery('episode-one');
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        $item = $this->art->item('episode-one');
        $item['poster_id'] = 'parent-two';
        $this->art->remember($item);

        $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
    }

    public function testChangingRecordedProviderYearInvalidatesItsSuggestionToken(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->art->enqueue('movie-one', 'Movie', null, 1);
        $db = Container::db()->getDibi();
        $db->update('artwork_posters', ['provider_year' => 1998])->where('source_key = %s AND poster_id = %s', $this->art->scope, 'movie-one')->execute();
        $this->art->externalObserve('movie-one', 0);
        $this->seedDiscovery('movie-one');
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        $db->update('artwork_posters', ['provider_year' => 1999])->where('source_key = %s AND poster_id = %s', $this->art->scope, 'movie-one')->execute();

        $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
    }

    public function testDiscoveryDeadlineAlsoExpiresTheTenMinuteSelectionToken(): void
    {
        $this->addItem('movie-one', 'Same title');
        $expires = time() + 2;
        $this->seedDiscovery('movie-one', $expires);
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        self::assertCount(1, $suggestions['candidates']);
        usleep(2_100_000);

        $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
        self::assertNull($this->art->externalBinding('movie-one')['tmdb_id']);
    }

    public function testSuggestionCannotBeSelectedAfterItsDeadlinePassesInsideTheWriteLock(): void
    {
        $this->addItem('movie-one', 'Same title');
        $this->seedDiscovery('movie-one', time() + 2);
        $group = $this->findGroup($this->manager->status()['review'], 'Movies');
        $suggestions = $this->manager->suggestions($group['ids'], $group['fingerprint']);
        $db = Container::db()->getDibi();
        $originalEvents = $db->onEvent;
        $delayed = false;
        $db->onEvent[] = static function (Dibi\Event $event) use (&$delayed): void {
            if (!$delayed && str_contains($event->sql, 'used_bytes = used_bytes')) {
                $delayed = true;
                usleep(2100000);
            }
        };
        try {
            $this->assertSelectionRejected($group['ids'], $group['fingerprint'], $suggestions['candidates'][0]['token']);
            self::assertTrue($delayed);
        } finally {
            $db->onEvent = $originalEvents;
        }
    }

    public function testManualUnlinkWithoutCachedResultsDoesNotPromiseAutomaticDiscovery(): void
    {
        $this->addItem('movie-one', 'Same title');
        self::assertSame('waiting', $this->manager->status()['review'][0]['discovery']['status']);
        $this->art->manageExternal(['movie-one'], $this->art->externalFingerprint(['movie-one']), 'unlink');
        self::assertSame('manual', $this->manager->status()['review'][0]['discovery']['status']);
        $this->art->configureExternal(false);
        self::assertSame('paused', $this->manager->status()['review'][0]['discovery']['status']);
    }

    private function addItem(string $id, string $title, string $library = 'Movies', string $type = 'Movie', string $seriesName = '', ?string $posterId = null): void
    {
        $this->art->remember(['item_id' => $id, 'item_type' => $type, 'title' => $title,
            'series_name' => $type === 'Episode' ? $seriesName : '', 'library' => $library, 'poster_id' => $posterId,
            'observed_at' => 1, 'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0]);
        $this->art->externalObserve($id, 0);
    }

    private function seedDiscovery(string $id, ?int $expires = null): void
    {
        $input = $this->art->discoveryInput($id);
        self::assertNotNull($input);
        $expires ??= time() + 3600;
        $candidate = ['id' => '1429', 'kind' => $input['kind'], 'title' => '未確認の題名', 'year' => 2013,
            'path' => 'suggestion.jpg', 'expires_at' => time() + ArtworkRepository::EXTERNAL_LIFETIME];
        Container::db()->getDibi()->insert('artwork_external_searches', [
            'source_key' => $this->art->scope, 'query_key' => $input['key'], 'server_id' => 'suggestion-test-server',
            'config_revision' => (int) $this->art->source()['external_revision'], 'kind' => $input['kind'], 'query_title' => $input['title'],
            'acquired_at' => time() - 10, 'expires_at' => $expires,
            'candidates_json' => json_encode([$candidate], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error' => null, 'revision' => 1,
        ])->execute();
    }

    /** @param list<array<string, mixed>> $groups @return array<string, mixed> */
    private function findGroup(array $groups, string $library): array
    {
        foreach ($groups as $group) {
            if ($group['library'] === $library) {
                return $group;
            }
        }
        self::fail('Expected review group was not found.');
    }

    /** @param list<string> $ids */
    private function assertSelectionRejected(array $ids, string $fingerprint, string $token): void
    {
        try {
            $this->manager->select($ids, $fingerprint, $token);
            self::fail('The suggestion token must be rejected after its group context changes.');
        } catch (RuntimeException) {
            foreach ($ids as $id) {
                self::assertNull($this->art->externalBinding($id)['tmdb_id']);
            }
        }
    }

    private function removeOwnedDirectory(string $path): void
    {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeOwnedDirectory($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
