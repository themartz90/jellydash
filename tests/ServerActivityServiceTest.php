<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\ServerActivityCache;
use Mk\Framework\Jellyfin\ServerActivityClient;
use Mk\Framework\Jellyfin\ServerActivityException;
use Mk\Framework\Jellyfin\ServerActivityFilters;
use Mk\Framework\Jellyfin\ServerActivityService;
use PHPUnit\Framework\TestCase;

final class ServerActivityServiceTest extends TestCase
{
    private const NOW = 1791374430;

    public function testTaskWindowsAndMissingProgressRemainTruthful(): void
    {
        $tasks = [
            ['Id' => 'running', 'Name' => 'Scan library', 'State' => 'Running', 'CurrentProgressPercentage' => 32.4],
            ['Id' => 'unknown', 'Name' => 'Extract chapters', 'State' => 'Running', 'CurrentProgressPercentage' => 160],
            $this->task('ok', 'Completed', 3500), $this->task('old', 'Completed', 3601),
            $this->task('fail', 'Failed', 80000), $this->task('old-fail', 'Failed', 86401),
        ];
        $service = $this->service(static fn (string $path): array => $path === '/ScheduledTasks' ? $tasks : ['ServerName' => 'Studio', 'Version' => '10.11.0', 'OperatingSystem' => 'obsolete', 'ProgramDataPath' => '/private']);
        $overview = $service->overview();
        self::assertSame('Studio', $overview['server']['data']['name']);
        self::assertSame(['running', 'unknown'], array_column($overview['tasks']['data']['running'], 'id'));
        self::assertNull($overview['tasks']['data']['running'][1]['progress']);
        self::assertSame(['ok', 'fail'], array_column($overview['tasks']['data']['recent'], 'id'));
        self::assertStringNotContainsString('/private', json_encode($overview, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('operating_system', $overview['server']['data']);
    }

    public function testOneForbiddenSectionDoesNotDiscardHealthyIdentity(): void
    {
        $service = $this->service(static function (string $path): array {
            if ($path === '/ScheduledTasks') {
                throw new ServerActivityException('forbidden');
            }
            return ['ServerName' => 'Studio', 'Version' => '10.11.0'];
        });
        $overview = $service->overview();
        self::assertSame('ready', $overview['server']['state']);
        self::assertSame('forbidden', $overview['tasks']['state']);
    }

    public function testSnapshotFiltersAreExactAndCountsComeFromMatches(): void
    {
        $calls = [];
        $service = $this->service(static function (string $path) use (&$calls): array {
            $calls[] = $path;
            return ['TotalRecordCount' => 3, 'Items' => [
                ['Id' => 3, 'Name' => '<script>event</script>', 'Date' => gmdate('c', self::NOW - 5), 'Type' => 'TaskCompleted', 'Severity' => 'Warning', 'Overview' => 'SECRET'],
                ['Id' => 2, 'Name' => 'Other', 'Date' => gmdate('c', self::NOW - 10), 'Type' => 'TaskCompletedExtra', 'Severity' => 'Warning'],
                ['Id' => 1, 'Name' => 'Info', 'Date' => gmdate('c', self::NOW - 15), 'Type' => 'TaskCompleted', 'Severity' => 'Information'],
            ]];
        });
        $payload = $service->activity(ServerActivityFilters::fromQuery(['type' => 'TaskCompleted', 'severity' => 'Warning'], self::NOW, 'UTC'));
        self::assertSame(1, $payload['data']['total']);
        self::assertSame('3', $payload['data']['items'][0]['id']);
        self::assertStringNotContainsString('SECRET', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('type=', $calls[0]);
        self::assertStringNotContainsString('maxDate=', $calls[0]);
    }

    public function testMalformedEventsAreUnavailableInsteadOfApparentlyEmpty(): void
    {
        $service = $this->service(static fn (): array => ['Items' => [['Id' => 1, 'Date' => 'not-a-date']], 'TotalRecordCount' => 1]);
        $payload = $service->activity(ServerActivityFilters::fromQuery([], self::NOW, 'UTC'));
        self::assertSame('unavailable', $payload['state']);
        self::assertNull($payload['data']);
    }

    public function testPublicIdentityRemainsAvailableAcrossCachedCredentialDenials(): void
    {
        $directory = sys_get_temp_dir() . '/jellydash-identity-' . bin2hex(random_bytes(8));
        $now = self::NOW;
        $publicReads = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $client = new ServerActivityClient('http://jellyfin.test', 'test-token', true, static function (string $path) use (&$publicReads): array {
            if ($path !== '/System/Info/Public') {
                throw new ServerActivityException('forbidden');
            }
            ++$publicReads;
            return ['ServerName' => 'Studio', 'Version' => '10.11.0'];
        });
        $service = new ServerActivityService($client, new ServerActivityCache($directory, $clock), $clock);
        try {
            foreach ([0, 5, 16, 31] as $offset) {
                $now = self::NOW + $offset;
                $overview = $service->overview();
                self::assertSame('Studio', $overview['server']['data']['name']);
                self::assertSame('forbidden', $overview['tasks']['state']);
            }
            self::assertSame(2, $publicReads);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testImpossibleCalendarTimestampDoesNotBecomeAnEvent(): void
    {
        $service = $this->service(static fn (): array => ['Items' => [['Id' => 1, 'Name' => 'Impossible', 'Date' => '2026-02-30T12:00:00Z']], 'TotalRecordCount' => 1]);
        self::assertSame('unavailable', $service->activity(ServerActivityFilters::fromQuery([], self::NOW, 'UTC'))['state']);
    }

    private function service(callable $request): ServerActivityService
    {
        return new ServerActivityService(new ServerActivityClient('http://jellyfin.test', 'test-token', true, $request), new ServerActivityCache(null, static fn (): int => self::NOW), static fn (): int => self::NOW);
    }

    private function task(string $id, string $status, int $age): array
    {
        return ['Id' => $id, 'Name' => $id, 'State' => 'Idle', 'LastExecutionResult' => ['Status' => $status, 'EndTimeUtc' => gmdate('c', self::NOW - $age), 'StartTimeUtc' => gmdate('c', self::NOW - $age - 60), 'ErrorMessage' => 'SECRET']];
    }
}
