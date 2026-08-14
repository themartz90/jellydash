<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinUserAvatars;
use PHPUnit\Framework\TestCase;

final class JellyfinUserAvatarsTest extends TestCase
{
    public function testProxyUrlRequiresAUserId(): void
    {
        $this->assertNull(JellyfinUserAvatars::proxyUrl(''));
        $this->assertNull(JellyfinUserAvatars::proxyUrl('user/1'));
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80',
            JellyfinUserAvatars::proxyUrl('user-1')
        );
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80&tag=abc123',
            JellyfinUserAvatars::proxyUrl('user-1', 'abc123')
        );
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80',
            JellyfinUserAvatars::proxyUrl('user-1', 'tag with spaces')
        );
    }

    public function testUrlUsesAUserIdWithoutLookingUpUsers(): void
    {
        $avatars = new JellyfinUserAvatars();

        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80',
            $avatars->url('user-1')
        );
        $this->assertNull($avatars->url('user/1'));
    }

    public function testUrlResolvesAMissingIdFromTheUserDirectory(): void
    {
        $avatars = new JellyfinUserAvatars();
        $avatars->loadFrom([
            [
                'Id' => 'user-with-photo',
                'Name' => 'Maya Okafor',
            ],
            [
                'Id' => 'user-2',
                'Name' => 'Sam',
            ],
        ]);

        $this->assertSame(
            '/api/image.php?user=user-with-photo&maxWidth=80',
            $avatars->url('', 'Maya Okafor')
        );
        $this->assertSame(
            '/api/image.php?user=user-2&maxWidth=80',
            $avatars->url('', 'sam')
        );
        $this->assertNull($avatars->url('', 'Nobody'));
        $this->assertNull($avatars->url('', ''));
    }
}
