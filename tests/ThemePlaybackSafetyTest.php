<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\ThemePlaybackClassifier;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ThemePlaybackSafetyTest extends TestCase
{
    public function testOnlyIdentifiableThemeMediaIsClassified(): void
    {
        $classifier = new ThemePlaybackClassifier();
        foreach ([
            ['Type' => 'Audio', 'Path' => 'C:\\Movies\\Film\\THEME.MP3'],
            ['Type' => 'Audio', 'Path' => '/shows/series/theme-music/opening.flac'],
            ['Type' => 'Video', 'Path' => '/shows/series/backdrops/opening.mkv'],
            ['ExtraType' => 'ThemeSong'],
            ['ExtraType' => 'ThemeVideo'],
        ] as $item) {
            self::assertSame('theme', $classifier->classify($item));
        }
        foreach ([
            ['Type' => 'Audio', 'Path' => '/music/Theme Song.mp3', 'Name' => 'Theme'],
            ['Type' => 'Audio', 'Path' => '/music/theme-music-collection/song.mp3'],
            ['Type' => 'Video', 'Path' => '/movies/theme.mp4'],
            ['Type' => 'Movie', 'Path' => '/movies/backdrops/movie.mkv'],
            ['Type' => 'Audio', 'Path' => '/music/backdrops/song.mp3'],
        ] as $item) {
            self::assertSame('ordinary', $classifier->classify($item));
        }
        self::assertNull($classifier->classify(['Type' => 'Audio', 'Name' => 'theme.mp3']));
        self::assertNull($classifier->classify(['Id' => '1']));
        self::assertNull($classifier->classify(['Type' => 'Audio', 'Path' => 'https://radio.example/theme.mp3']));
        self::assertNull($classifier->classify(['Type' => 'Audio', 'MediaSources' => [
            ['Path' => '/film/theme.mp3'], ['Path' => '/music/song.mp3'],
        ]]));
    }

    public function testIdentifiersAndServerPathsDoNotCollide(): void
    {
        self::assertSame('12345', ThemePlaybackExclusions::canonicalItemId('12345'));
        self::assertSame('a-B', ThemePlaybackExclusions::canonicalItemId('a-B'));
        self::assertSame('abcdef0123456789abcdef0123456789', ThemePlaybackExclusions::canonicalItemId('ABCDEF01-2345-6789-ABCD-EF0123456789'));
        self::assertNotSame(ThemePlaybackExclusions::serverKey('https://host/Emby'), ThemePlaybackExclusions::serverKey('https://host/emby'));
        self::assertSame(ThemePlaybackExclusions::serverKey('HTTPS://HOST/Emby/'), ThemePlaybackExclusions::serverKey('https://host/Emby'));
    }

    public function testFailedLiveLookupBacksOffAndLaterHidesConfirmedTheme(): void
    {
        $database = Database::sqlite(':memory:');
        $exclusions = new ThemePlaybackExclusions($database, 'test');
        $calls = 0;
        $client = new JellyfinClient('https://example.invalid', 'test', true, static function () use (&$calls): array {
            ++$calls;
            if ($calls === 1) {
                throw new RuntimeException('Temporary outage');
            }
            return ['Items' => [['Id' => '1', 'Type' => 'Audio', 'Path' => '/film/theme.mp3']]];
        });
        $sessions = [['NowPlayingItem' => ['Id' => '1', 'Type' => 'Audio']]];
        self::assertSame($sessions, $exclusions->filterSessions($sessions, $client, 1000));
        self::assertSame($sessions, $exclusions->filterSessions($sessions, $client, 1005));
        self::assertSame(1, $calls);
        self::assertSame([], $exclusions->filterSessions($sessions, $client, 1060));
        self::assertSame(2, $calls);
        self::assertSame([], $exclusions->filterSessions([['NowPlayingItem' => ['Id' => '1']]], $client, 1070));
        self::assertSame(2, $calls);
    }

    public function testStableMetadataDoesNotRewriteClassificationEveryPoll(): void
    {
        $database = Database::sqlite(':memory:');
        $exclusions = new ThemePlaybackExclusions($database, 'test');
        $client = new JellyfinClient('https://example.invalid', 'test', true, static function (): never {
            throw new RuntimeException('No lookup expected');
        });
        $sessions = [['NowPlayingItem' => ['Id' => '1', 'Type' => 'Audio', 'Path' => '/film/theme.mp3']]];
        self::assertSame([], $exclusions->filterSessions($sessions, $client, 1000));
        $revision = $exclusions->revision();
        self::assertSame([], $exclusions->filterSessions($sessions, $client, 1005));
        self::assertSame($revision, $exclusions->revision());
        self::assertSame(1000, (int) $database->getDibi()->select('updated_at_epoch')->from('theme_item_classifications')->fetchSingle());
    }

    public function testBackgroundScanMovesPastMissingItemsWithoutChangingHistory(): void
    {
        $database = Database::sqlite(':memory:');
        $db = $database->getDibi();
        $db->query('CREATE TABLE play_history (id INTEGER PRIMARY KEY, item_id TEXT, item_type TEXT)');
        $db->query("INSERT INTO play_history VALUES (1, 'missing', 'Audio'), (2, '123', 'Audio')");
        $before = $db->select('*')->from('play_history')->fetchAll();
        $exclusions = new ThemePlaybackExclusions($database, 'test');
        $calls = [];
        $client = new JellyfinClient('https://example.invalid', 'test', true, static function (string $path) use (&$calls): array {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $calls[] = $query['Ids'];
            return $query['Ids'] === '123'
                ? ['Items' => [['Id' => '123', 'Type' => 'Audio', 'Path' => '/film/theme.mp3']]]
                : ['Items' => []];
        });
        self::assertSame(0, $exclusions->classifyHistoryBatch($client, 1, 1000));
        self::assertSame(1, $exclusions->classifyHistoryBatch($client, 1, 1005));
        self::assertSame(0, $exclusions->classifyHistoryBatch($client, 1, 1010));
        self::assertSame(['missing', '123'], $calls);
        self::assertSame(0, $exclusions->classifyHistoryBatch($client, 1, 1060));
        self::assertSame(['missing', '123', 'missing'], $calls);
        self::assertEquals($before, $db->select('*')->from('play_history')->fetchAll());
        self::assertSame(['missing'], $db->select('ph.item_id')->from('play_history AS ph')
            ->where($exclusions->visibilitySql('ph'))->fetchPairs(null, 'item_id'));
    }

    public function testVisibilityAndCacheIdentityAreServerAndItemSpecific(): void
    {
        $database = Database::sqlite(':memory:');
        $db = $database->getDibi();
        $db->query('CREATE TABLE play_history (id INTEGER PRIMARY KEY, item_id TEXT)');
        $db->query("INSERT INTO play_history VALUES (1, 'a-B'), (2, 'a-b'), (3, 'ab')");
        $one = new ThemePlaybackExclusions($database, 'one');
        $two = new ThemePlaybackExclusions($database, 'two');
        $one->remember('a-B', 'Audio', 'theme', 1000);
        $two->remember('ab', 'Audio', 'theme', 1000);
        self::assertSame($one->revision(), $two->revision());
        self::assertNotSame($one->fingerprint(), $two->fingerprint());
        self::assertSame(['a-b', 'ab'], $db->select('item_id')->from('play_history')
            ->where($one->visibilitySql())->orderBy('id')->fetchPairs(null, 'item_id'));
        self::assertSame(['a-B', 'a-b'], $db->select('item_id')->from('play_history')
            ->where($two->visibilitySql())->orderBy('id')->fetchPairs(null, 'item_id'));
    }
}
