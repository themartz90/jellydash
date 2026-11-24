<?php

declare(strict_types=1);

use Mk\Framework\Http\RemoteRequestException;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeerrReadContractTest extends TestCase
{
    public static function versions(): array
    {
        return ['Seerr' => ['3.5.2'], 'Jellyseerr' => ['2.7.3']];
    }

    #[DataProvider('versions')]
    public function testReadContract(string $version): void
    {
        $process = proc_open([PHP_BINARY, ROOT_DIR . '/tests/fixtures/seerr-read-server.php', $version], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        try {
            $address = trim((string) fgets($pipes[1]));
            self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/D', $address);
            $client = new JellyseerrClient('http://' . $address, 'seerr-read-fixture-key');
            $movies = $client->requestPageWithMetadata(1, pageSize: 2);
            self::assertSame(['pages' => 3, 'pageSize' => 2, 'results' => 6, 'page' => 1], $movies['pageInfo']);
            self::assertSame(['pageInfo', 'results'], array_keys($movies));
            self::assertSame([16, 15], array_column($movies['results'], 'id'));
            self::assertSame([16, 15], array_column($client->requests(2), 'id'));
            self::assertSame([14, 13], array_column($client->requestPage(2, 2), 'id'));

            $metadata = RequestMapper::details($client->movie(801), 'movie');
            $standard = RequestMapper::display($movies['results'][0], $metadata);
            self::assertSame([
                'requestId' => 16, 'mediaId' => 401, 'tmdbId' => 801, 'mediaType' => 'movie', 'is4k' => false,
                'requestStatus' => 2, 'mediaStatus' => 5, 'seasonNumbers' => [], 'requestedAt' => '2026-11-22T10:00:00.000Z',
                'requester' => 'Fixture Viewer', 'title' => 'Fixture Movie', 'year' => '2024',
                'posterUrl' => 'https://image.tmdb.org/t/p/w342/movie.jpg', 'statusLabel' => 'Available', 'statusKind' => 'available',
            ], $standard);
            $fourK = RequestMapper::display($client->requestDetail(15), $metadata);
            self::assertSame(15, $fourK['requestId']);
            self::assertSame(401, $fourK['mediaId']);
            self::assertTrue($fourK['is4k']);
            self::assertSame(3, $fourK['mediaStatus']);
            self::assertSame('Requested', $fourK['statusLabel']);

            $pending = $client->requestPageWithMetadata(1, 'pending');
            self::assertSame(1, $pending['pageInfo']['results']);
            self::assertSame([13], array_column($pending['results'], 'id'));
            $series = RequestMapper::display($client->requestDetail(14));
            $otherSeason = RequestMapper::display($client->requestDetail(13));
            self::assertSame($series['mediaId'], $otherSeason['mediaId']);
            self::assertSame([0, 3], $series['seasonNumbers']);
            self::assertSame([2], $otherSeason['seasonNumbers']);
            self::assertSame(4, $series['mediaStatus']);
            self::assertSame('Partially available', $series['statusLabel']);
            self::assertSame('Unknown title', $series['title']);
            self::assertNull($series['posterUrl']);

            $older = $client->requestPageWithMetadata(3, pageSize: 2);
            self::assertSame([12, 11], array_column($older['results'], 'id'));
            $failed = $client->requestPageWithMetadata(1, 'failed');
            self::assertSame(1, $failed['pageInfo']['results']);
            self::assertSame([12], array_column($failed['results'], 'id'));
            self::assertSame('failed', RequestMapper::display($failed['results'][0])['statusKind']);
            self::assertSame('declined', RequestMapper::display($older['results'][1])['statusKind']);

            foreach ([99 => 403, 98 => 404] as $id => $code) {
                try {
                    $client->requestDetail($id);
                    self::fail('Expected an upstream read refusal.');
                } catch (RemoteRequestException $error) {
                    self::assertSame($code, $error->getCode());
                    self::assertStringNotContainsString('synthetic private diagnostic', $error->getMessage());
                    self::assertStringNotContainsString('seerr-read-fixture-key', $error->getMessage());
                }
            }
        } finally {
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testMissingFourKStateOrSeasonsDoesNotBorrowOtherMediaState(): void
    {
        $display = RequestMapper::display([
            'id' => 81, 'status' => 2, 'is4k' => true, 'seasonCount' => 2,
            'media' => ['mediaType' => 'tv', 'status' => 5, 'seasons' => [['seasonNumber' => 3]]],
            'requestedBy' => ['email' => 'private@example.test'],
        ]);

        self::assertTrue($display['is4k']);
        self::assertNull($display['mediaStatus']);
        self::assertNull($display['seasonNumbers']);
        self::assertNull($display['mediaId']);
        self::assertSame('Unknown', $display['requester']);
        self::assertSame('Requested', $display['statusLabel']);
    }
}
