<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\NowPlayingService;
use PHPUnit\Framework\TestCase;

final class NowPlayingServiceTest extends TestCase
{
    public function testActiveUsersIncludesUsernameZeroButIgnoresEmptyNames(): void
    {
        $stats = (new \ReflectionClass(NowPlayingService::class))
            ->getMethod('stats')
            ->invoke(new NowPlayingService(), [
                ['user' => '0', 'bitrate' => 1_000_000],
                ['user' => 'Martin', 'bitrate' => 2_000_000],
                ['user' => '', 'bitrate' => 3_000_000],
            ], 0, false);

        $this->assertIsArray($stats);
        $this->assertSame(2, $stats['active_users']);
    }
}
