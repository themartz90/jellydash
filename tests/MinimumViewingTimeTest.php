<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Jellyfin\MinimumViewingTime;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use PHPUnit\Framework\TestCase;

final class MinimumViewingTimeTest extends TestCase
{
    public function testOnlyNonNegativeWholeSecondsAreAccepted(): void
    {
        foreach (['0' => 0, '60' => 60, '2147483647' => 2147483647] as $value => $expected) {
            self::assertSame($expected, MinimumViewingTime::parse((string) $value));
        }
        foreach ([null, [], ['60'], true, 60, '', '-1', '1.5', '1e2', ' 60 ', '01', '2147483648', '9999999999999999999999'] as $invalid) {
            self::assertNull(MinimumViewingTime::parse($invalid));
        }
    }

    public function testDefaultAndInvalidStoredValuesDisableFilteringAndChangesInvalidateStatistics(): void
    {
        $previous = AppSettings::get('minimum_viewing_seconds');
        try {
            AppSettings::set('minimum_viewing_seconds', null);
            self::assertSame(0, MinimumViewingTime::seconds());
            $method = new ReflectionMethod(PlaybackStatisticsService::class, 'cacheContextFingerprint');
            $service = new PlaybackStatisticsService();
            $default = $method->invoke($service);
            AppSettings::set('minimum_viewing_seconds', '60');
            self::assertSame(60, MinimumViewingTime::seconds());
            self::assertNotSame($default, $method->invoke($service));
            AppSettings::set('minimum_viewing_seconds', 'invalid');
            self::assertSame(0, MinimumViewingTime::seconds());
            self::assertSame($default, $method->invoke($service));
        } finally {
            AppSettings::set('minimum_viewing_seconds', $previous);
        }
    }
}
