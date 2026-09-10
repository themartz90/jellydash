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

    public function testMetadataFailureDoesNotBlockRecordingOrAvailableTotal(): void
    {
        $service = new NowPlayingService();
        $cycle = new ReflectionMethod($service, 'historyCycle');
        $recorded = null;
        $streams = [['id' => 'session', 'library' => 'Movies']];

        $result = $cycle->invoke(
            $service,
            $streams,
            static fn (): never => throw new RuntimeException('metadata failed'),
            static function (array $value) use (&$recorded): void {
                $recorded = $value;
            },
            static fn (): int => 59,
            static fn (): bool => false,
        );

        $this->assertSame($streams, $recorded);
        $this->assertSame($streams, $result['streams']);
        $this->assertFalse($result['metadata_available']);
        $this->assertTrue($result['recording_available']);
        $this->assertTrue($result['watch_today_available']);
        $this->assertFalse($result['watch_today_estimated']);
    }

    public function testRecordingFailureIsDegradedEvenWhenOlderTotalIsAvailable(): void
    {
        $service = new NowPlayingService();
        $cycle = new ReflectionMethod($service, 'historyCycle');
        $result = $cycle->invoke(
            $service,
            [],
            static fn (array $streams): array => $streams,
            static fn (): never => throw new RuntimeException('record failed'),
            static fn (): int => 61,
            static fn (): bool => false,
        );
        $stats = (new ReflectionMethod($service, 'stats'))->invoke(
            $service,
            [],
            $result['watch_today'],
            $result['watch_today_estimated'],
            $result['watch_today_available'],
            $result['watch_today_estimate_available'],
            $result['recording_available'],
            $result['metadata_available'],
        );

        $this->assertFalse($result['recording_available']);
        $this->assertSame('1m', $stats['watch_today']);
        $this->assertSame('degraded', $stats['collection_status']);
        $this->assertFalse($stats['recording_available']);
    }

    public function testFailedTotalReadReturnsUnavailableInsteadOfZero(): void
    {
        $service = new NowPlayingService();
        $cycle = new ReflectionMethod($service, 'historyCycle');
        $result = $cycle->invoke(
            $service,
            [],
            static fn (array $streams): array => $streams,
            static function (): void {
            },
            static fn (): never => throw new RuntimeException('total failed'),
            static fn (): bool => false,
        );
        $stats = (new ReflectionMethod($service, 'stats'))->invoke(
            $service,
            [],
            $result['watch_today'],
            $result['watch_today_estimated'],
            $result['watch_today_available'],
            $result['watch_today_estimate_available'],
            $result['recording_available'],
            $result['metadata_available'],
        );

        $this->assertFalse($stats['watch_today_available']);
        $this->assertSame('Unavailable', $stats['watch_today']);
    }

    public function testFailedEstimateReadMarksAvailableTotalAsEstimated(): void
    {
        $service = new NowPlayingService();
        $cycle = new ReflectionMethod($service, 'historyCycle');
        $result = $cycle->invoke(
            $service,
            [],
            static fn (array $streams): array => $streams,
            static function (): void {
            },
            static fn (): int => 59,
            static fn (): never => throw new RuntimeException('estimate failed'),
        );
        $stats = (new ReflectionMethod($service, 'stats'))->invoke(
            $service,
            [],
            $result['watch_today'],
            $result['watch_today_estimated'],
            $result['watch_today_available'],
            $result['watch_today_estimate_available'],
            $result['recording_available'],
            $result['metadata_available'],
        );

        $this->assertTrue($stats['watch_today_available']);
        $this->assertFalse($stats['watch_today_estimate_available']);
        $this->assertSame('about <1m', $stats['watch_today']);
    }
}
