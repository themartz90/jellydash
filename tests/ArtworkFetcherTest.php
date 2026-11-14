<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\ArtworkFetcher;
use Mk\Framework\Jellyfin\ArtworkRepository;
use PHPUnit\Framework\TestCase;

final class ArtworkFetcherTest extends TestCase
{
    public static function gif(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    }

    public function testPreservesSelectedArtworkBytes(): void
    {
        self::assertSame(['body' => self::gif(), 'contentType' => 'image/gif'], ArtworkFetcher::validate(self::gif(), 'image/gif; charset=binary'));
    }

    public function testInvalidBodiesCannotReplaceSavedArtwork(): void
    {
        $huge = self::gif();
        $huge = substr_replace($huge, pack('vv', 2048, 2048), 6, 4);
        foreach ([['<html>error</html>', 'image/png'], [self::gif(), 'image/jpeg'],
            [str_repeat('x', ArtworkRepository::MAX_IMAGE_BYTES + 1), 'image/png'],
            [$huge, 'image/gif'], [substr(self::gif(), 0, -5) . ';', 'image/gif']] as [$body, $type]) {
            try {
                ArtworkFetcher::validate($body, $type);
                self::fail('An invalid or truncated body was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('invalid_image', $error->getMessage());
            }
        }
    }

    public function testAnimatedGifIsRetainedAsOneDecodedStillAndEveryFrameIsBounded(): void
    {
        $gif = self::gif();
        $block = substr($gif, strpos($gif, ','), -1);
        $animated = substr($gif, 0, -1) . $block . ';';
        $image = ArtworkFetcher::validate($animated, 'image/gif');
        self::assertSame('image/png', $image['contentType']);
        self::assertSame('image/png', getimagesizefromstring($image['body'])['mime']);
        $invalid = substr($gif, 0, -1) . substr_replace($block, pack('v', 2049), 5, 2) . ';';
        $this->expectException(\RuntimeException::class);
        ArtworkFetcher::validate($invalid, 'image/gif');
    }

    public function testApngDoesNotRetainUndecodedAnimationData(): void
    {
        $decoded = imagecreatefromstring(self::gif());
        ob_start();
        imagepng($decoded);
        $png = ob_get_clean();
        imagedestroy($decoded);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
        $animated = substr($png, 0, -12) . $chunk('acTL', pack('NN', 2, 0))
            . $chunk('fcTL', pack('NNNNNnnCC', 0, 2049, 1, 0, 0, 1, 1, 0, 0))
            . $chunk('fdAT', pack('N', 1) . 'invalid compressed frame') . substr($png, -12);
        $saved = ArtworkFetcher::validate($animated, 'image/png');
        self::assertStringNotContainsString('fdAT', $saved['body']);
        self::assertStringNotContainsString('acTL', $saved['body']);
        self::assertSame(1, getimagesizefromstring($saved['body'])[0]);
    }
}
