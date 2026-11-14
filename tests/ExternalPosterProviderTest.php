<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\ExternalPosterProvider;
use Mk\Framework\Jellyfin\JellyfinClient;
use PHPUnit\Framework\TestCase;

final class ExternalPosterProviderTest extends TestCase
{
    public function testAutomaticLookupDoesNotSendATitleAndRequiresReturnedIdentity(): void
    {
        $client = new JellyfinClient('https://synthetic.invalid', 'synthetic', true, static function ($path, $method, $payload): array {
            self::assertSame('/Items/RemoteSearch/Series', $path);
            self::assertSame('POST', $method);
            self::assertSame(['ProviderIds' => ['Tmdb' => '1399']], $payload['SearchInfo']);
            self::assertSame('TheMovieDb', $payload['SearchProviderName']);
            self::assertFalse($payload['IncludeDisabledProviders']);
            return [self::candidate('1399')];
        });
        self::assertSame('1399', (new ExternalPosterProvider($client))->byId('Series', '1399')['id']);
    }

    public function testProviderTitleFallbackCannotBecomeAnAutomaticMatch(): void
    {
        $client = new JellyfinClient(
            'https://synthetic.invalid',
            'synthetic',
            true,
            static fn (): array => [self::candidate('999')]
        );
        $this->expectException(RuntimeException::class);
        (new ExternalPosterProvider($client))->byId('Movie', '348');
    }

    public function testAmbiguousAutomaticResultsAreRejected(): void
    {
        $client = new JellyfinClient(
            'https://synthetic.invalid',
            'synthetic',
            true,
            static fn (): array => [self::candidate('348'), self::candidate('348')]
        );
        $this->expectException(RuntimeException::class);
        (new ExternalPosterProvider($client))->byId('Movie', '348');
    }

    public function testImageUrlsCannotSupplyAnArbitraryFetchDestination(): void
    {
        foreach (['http://image.tmdb.org/t/p/w500/a.jpg', 'https://image.tmdb.org.evil.test/t/p/w500/a.jpg',
            'https://user@image.tmdb.org/t/p/w500/a.jpg', 'https://image.tmdb.org:444/t/p/w500/a.jpg',
            'https://image.tmdb.org/t/p/w500/../a.jpg', 'https://image.tmdb.org/t/p/w500/a.svg',
            'https://image.tmdb.org/t/p/w500/a.jpg?url=http://127.0.0.1'] as $url) {
            self::assertNull(ExternalPosterProvider::posterPath($url));
        }
        self::assertSame('a.jpg', ExternalPosterProvider::posterPath('https://image.tmdb.org/t/p/w500/a.jpg'));
    }

    public function testDiscardingAnInvalidResultDoesNotHideAutomaticAmbiguity(): void
    {
        $client = new JellyfinClient(
            'https://synthetic.invalid',
            'synthetic',
            true,
            static fn (): array => [self::candidate('348'), ['Name' => 'Untrusted extra result']]
        );
        $this->expectException(RuntimeException::class);
        (new ExternalPosterProvider($client))->byId('Movie', '348');
    }

    public function testMetadataLimitAppliesBeforeCandidateSelection(): void
    {
        $client = new JellyfinClient(
            'https://synthetic.invalid',
            'synthetic',
            true,
            static fn (): array => [['Name' => str_repeat('x', 524289)]]
        );
        $this->expectException(RuntimeException::class);
        (new ExternalPosterProvider($client))->search('Movie', 'Synthetic');
    }

    public function testExplicitSearchKeepsMovieAndSeriesSeparate(): void
    {
        $client = new JellyfinClient('https://synthetic.invalid', 'synthetic', true, static function ($path, $method, $payload): array {
            self::assertSame('/Items/RemoteSearch/Movie', $path);
            self::assertSame(['Name' => 'Synthetic movie', 'Year' => 2000], $payload['SearchInfo']);
            return [self::candidate('348')];
        });
        self::assertSame('Movie', (new ExternalPosterProvider($client))->search('Movie', 'Synthetic movie', 2000)[0]['kind']);
    }

    private static function candidate(string $id): array
    {
        return ['Name' => 'Synthetic title', 'ProductionYear' => 2000, 'ProviderIds' => ['Tmdb' => $id],
            'ImageUrl' => 'https://image.tmdb.org/t/p/w500/a.jpg'];
    }
}
