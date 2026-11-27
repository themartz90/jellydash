<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyseerr\JellyfinRequestLink;
use PHPUnit\Framework\TestCase;

final class SeerrJellyfinLinkTest extends TestCase
{
    private const SERVER = '11111111111111111111111111111111';
    private const ITEM = '33333333333333333333333333333333';
    private const OTHER = '44444444444444444444444444444444';

    public function testEditionBindingRequiresDistinctIdsOnTheDeclaredServer(): void
    {
        $request = [
            'id' => 7, 'type' => 'movie', 'status' => 2, 'is4k' => true,
            'media' => ['id' => 17, 'tmdbId' => 567, 'mediaType' => 'movie', 'status4k' => 5,
                'jellyfinMediaId4k' => self::ITEM, 'jellyfinMediaId' => self::OTHER,
                'mediaUrl4k' => 'https://seerr-claimed.invalid/web/index.html#!/details?id=' . self::ITEM . '&serverId=' . self::SERVER],
        ];
        self::assertSame(['item' => self::ITEM, 'server' => self::SERVER, 'type' => 'Movie',
            'tmdb' => 567, 'tvdb' => null, 'is4k' => true, 'seasons' => []], JellyfinRequestLink::binding($request));
        $ambiguous = $request;
        $ambiguous['media']['jellyfinMediaId'] = self::ITEM;
        self::assertNull(JellyfinRequestLink::binding($ambiguous));
        $mismatch = $request;
        $mismatch['media']['mediaUrl4k'] = str_replace(self::ITEM, self::OTHER, $request['media']['mediaUrl4k']);
        self::assertNull(JellyfinRequestLink::binding($mismatch));
        $request['status'] = 1;
        self::assertNull(JellyfinRequestLink::binding($request));
    }

    public function testLinkUsesBoundedMetadataAndConfiguredWebBase(): void
    {
        $item = [
            'Id' => self::ITEM, 'ServerId' => self::SERVER, 'Type' => 'Movie', 'LocationType' => 'FileSystem',
            'ProviderIds' => ['Tmdb' => '567'],
            'MediaSources' => [['Id' => self::ITEM, 'Protocol' => 'File', 'MediaStreams' => [['Type' => 'Video', 'Width' => 3840]]]],
        ];
        $client = new JellyfinClient('https://api.example.test/jellyfin', 'server-fixture',
            requester: static function (string $path, string $method, ?array $body, int $timeout) use (&$item): array {
                self::assertSame('GET', $method);
                self::assertNull($body);
                self::assertSame(2, $timeout);
                self::assertStringStartsWith('/Items?', $path);
                parse_str(substr($path, strlen('/Items?')), $query);
                self::assertSame(self::ITEM, $query['Ids'] ?? null);
                self::assertSame('2', $query['Limit'] ?? null);
                self::assertSame('false', $query['EnableUserData'] ?? null);
                self::assertArrayNotHasKey('UserId', $query);

                return ['Items' => [$item], 'StartIndex' => 0, 'TotalRecordCount' => 1];
            });
        $resolver = new JellyfinRequestLink($client, 'https://watch.example.test/jellyfin');
        $binding = ['item' => self::ITEM, 'server' => self::SERVER, 'type' => 'Movie',
            'tmdb' => 567, 'tvdb' => null, 'is4k' => true, 'seasons' => []];
        self::assertSame('https://watch.example.test/jellyfin/web/#/details?id=' . self::ITEM . '&serverId=' . self::SERVER,
            $resolver->verifiedLink($binding));
        self::assertNull((new JellyfinRequestLink($client, 'https://watch.example.test/?api_key=unexpected'))->verifiedLink($binding));

        foreach ([
            ['Id' => self::OTHER], ['ServerId' => self::OTHER], ['Type' => 'Series'],
            ['ProviderIds' => ['Tmdb' => '432']], ['MediaSources' => [$item['MediaSources'][0], $item['MediaSources'][0]]],
        ] as $mismatch) {
            $valid = $item;
            $item = $mismatch + $item;
            self::assertNull($resolver->verifiedLink($binding));
            $item = $valid;
        }
        $item['MediaSources'][0]['MediaStreams'][0]['Width'] = 1920;
        self::assertNull($resolver->verifiedLink($binding));
    }
}
