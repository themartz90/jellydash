<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkManager;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class ArtworkManagerTest extends TestCase
{
    public function testExplicitSeriesMatchIsReversibleAndCannotOverrideLiveConflictingIdentity(): void
    {
        $database = Container::db();
        $base = 'https://manager-' . bin2hex(random_bytes(8)) . '.invalid';
        $repository = new ArtworkRepository($database, ThemePlaybackExclusions::serverKey($base));
        $repository->anchor('server-one');
        $item = ['item_id' => 'gone-episode', 'item_type' => 'Episode', 'title' => 'Pilot',
            'series_name' => 'Custom Show', 'poster_id' => null, 'observed_at' => 0,
            'evidence' => 'legacy', 'status' => 'review', 'next_attempt' => 0];
        $repository->remember($item);
        $live = false;
        $incomplete = false;
        $client = new JellyfinClient($base, 'synthetic', true, static function (string $path) use (&$live, &$incomplete): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'server-one'];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $items = ($query['Ids'] ?? '') === 'series-current'
                ? [['Id' => 'series-current', 'Type' => 'Series', 'Name' => 'Custom Show']]
                : ($live ? [['Id' => 'gone-episode', 'Type' => 'Episode', 'SeriesId' => 'other-series']] : []);
            return ['Items' => $items, 'TotalRecordCount' => $items === [] && $incomplete ? 1 : count($items)];
        });
        try {
            $manager = new ArtworkManager($database, $client, $repository);
            $group = $manager->status()['groups'][0];
            $manager->link($group['ids'], $group['fingerprint'], 'series-current');
            self::assertSame('series-current', $repository->item('gone-episode')['poster_id']);
            self::assertSame('manual', $repository->item('gone-episode')['evidence']);
            $rows = $repository->items(['gone-episode']);
            $repository->matchItems(['gone-episode'], null, ArtworkRepository::fingerprint($rows));
            self::assertNull($repository->item('gone-episode')['poster_id']);
            $repository->rememberWithPoster($item + []);
            self::assertSame('dismissed', $repository->item('gone-episode')['evidence']);
            $live = true;
            $group = $manager->status()['groups'][0];
            try {
                $manager->link($group['ids'], $group['fingerprint'], 'series-current');
                self::fail('A current conflicting episode must not be remapped.');
            } catch (RuntimeException) {
                self::assertNull($repository->item('gone-episode')['poster_id']);
            }
            $live = false;
            $incomplete = true;
            try {
                $manager->link($group['ids'], $group['fingerprint'], 'series-current');
                self::fail('Incomplete metadata must not establish absence.');
            } catch (RuntimeException) {
                self::assertNull($repository->item('gone-episode')['poster_id']);
            }
        } finally {
            foreach (['artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
                $database->getDibi()->delete($table)->where('source_key = %s', $repository->scope)->execute();
            }
        }
    }
}
