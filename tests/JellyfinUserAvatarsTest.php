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

    public function testUrlKeepsInitialsWhenTheUserIdIsMissing(): void
    {
        $avatars = new JellyfinUserAvatars();

        $this->assertNull($avatars->url(''));
        $this->assertNull($avatars->url(null));
    }
}
