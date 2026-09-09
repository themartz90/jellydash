<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use PHPUnit\Framework\TestCase;

final class MonitoringExclusionsTest extends TestCase
{
    public function testExactCaseInsensitiveNamesIncludeUnicodeAndNumericUsernames(): void
    {
        $policy = new MonitoringExclusions([' Admin ', 'admin', 'ÄNNE', '0']);
        self::assertTrue($policy->excludes('ADMIN'));
        self::assertTrue($policy->excludes('änne'));
        self::assertTrue($policy->excludes('0'));
        self::assertFalse($policy->excludes('Administrator'));
        self::assertFalse($policy->excludes('Anne'));
        self::assertFalse($policy->excludes(null));
        self::assertSame($policy->fingerprint(), (new MonitoringExclusions(['0', 'änne', 'admin']))->fingerprint());
    }

    public function testExcludedSessionsDoNotContributeEvenHiddenClientMetadata(): void
    {
        $policy = new MonitoringExclusions(['Manager']);
        $sessions = [
            ['UserName' => 'Manager', 'DeviceName' => 'private-device', 'NowPlayingItem' => ['Name' => 'private-title']],
            ['UserName' => 'manager', 'Client' => 'private-client'],
            ['UserName' => 'Viewer'],
        ];
        self::assertSame([['UserName' => 'Viewer']], $policy->filterSessions($sessions));
    }

    public function testSettingsOverrideEnvironmentAndClearingChangesTheStatisticsCacheContext(): void
    {
        $previous = AppSettings::get('ignore_users');
        $environment = getenv('IGNORE_USERS');
        try {
            AppSettings::set('ignore_users', null);
            putenv('IGNORE_USERS=Manager');
            self::assertTrue((new MonitoringExclusions())->excludes('manager'));
            $fingerprint = new ReflectionMethod(PlaybackStatisticsService::class, 'cacheContextFingerprint');
            $before = $fingerprint->invoke(new PlaybackStatisticsService());

            AppSettings::set('ignore_users', 'Viewer');
            self::assertFalse((new MonitoringExclusions())->excludes('Manager'));
            self::assertTrue((new MonitoringExclusions())->excludes('Viewer'));
            self::assertNotSame($before, $fingerprint->invoke(new PlaybackStatisticsService()));

            AppSettings::set('ignore_users', '');
            self::assertSame([], (new MonitoringExclusions())->names());
        } finally {
            AppSettings::set('ignore_users', $previous);
            putenv($environment === false ? 'IGNORE_USERS' : 'IGNORE_USERS=' . $environment);
        }
    }
}
