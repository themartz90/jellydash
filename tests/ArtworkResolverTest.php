<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\ArtworkResolver;
use Mk\Framework\Jellyfin\MovieReplacements;
use PHPUnit\Framework\TestCase;

final class ArtworkResolverTest extends TestCase
{
    public function testDeletedEpisodeAndMovieChainUseSavedImagesWithoutUpstreamAccess(): void
    {
        $db = Container::db();
        $scope = hash('sha256', random_bytes(16));
        $art = new ArtworkRepository($db, $scope);
        $movies = new MovieReplacements($db, $scope);
        try {
            $art->anchor('server-one');
            $art->rememberWithPoster(['item_id' => 'gone-episode', 'item_type' => 'Episode', 'title' => 'Pilot',
                'series_name' => 'Custom Show', 'poster_id' => 'series-one', 'observed_at' => 100,
                'evidence' => 'observed', 'status' => 'ready', 'next_attempt' => 0]);
            $art->enqueue('old-movie', 'Movie', null, 100);
            $lease = $art->claim(100);
            foreach ($art->jobs(100) as $job) {
                $art->publish($job, 'saved-' . $job['poster_id'], 'image/png', $lease, 101);
            }
            $movies->link('old-movie', 'new-movie');
            $resolver = new ArtworkResolver($db, $art, $movies);
            self::assertSame('saved-series-one', $resolver->image('gone-episode', true)['body']);
            self::assertSame('series-one', $resolver->target('gone-episode', true));
            self::assertSame('saved-old-movie', $resolver->image('new-movie', false)['body']);
            $art->rememberWithPoster(['item_id' => 'old-movie', 'item_type' => 'Movie', 'title' => 'Movie',
                'series_name' => '', 'poster_id' => 'art-target', 'observed_at' => 200,
                'evidence' => 'manual', 'status' => 'ready', 'next_attempt' => 0]);
            $art->publish($art->poster('art-target'), 'selected-art', 'image/png', $lease, 102);
            self::assertSame('selected-art', $resolver->image('new-movie', false)['body']);
            $movies->unlink('old-movie');
            self::assertNull($resolver->image('new-movie', false));
            self::assertSame('selected-art', $resolver->image('old-movie', false)['body']);
            $art->anchor('other-server');
            self::assertTrue($resolver->blocked());
            self::assertNull($resolver->image('gone-episode', true));
        } finally {
            foreach (['artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
                $db->getDibi()->delete($table)->where('source_key = %s', $scope)->execute();
            }
            foreach (['movie_replacements', 'movie_identity_items', 'movie_identity_state'] as $table) {
                $db->getDibi()->delete($table)->where('server_key = %s', $scope)->execute();
            }
        }
    }
}
