<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Pages\HistoryController;
use PHPUnit\Framework\TestCase;

final class PlaybackFiguresConsistencyTest extends TestCase
{
    public function testStatisticsAndHistoryShareDurationFormatting(): void
    {
        $history = (new ReflectionClass(HistoryController::class))->newInstanceWithoutConstructor();
        $historyDuration = new ReflectionMethod($history, 'durationLabel');

        foreach ([0 => '0m', 59 => '<1m', 60 => '1m', 3660 => '1h 1m'] as $seconds => $expected) {
            $this->assertSame($expected, PlaybackStatisticsService::formatDuration($seconds));
            $this->assertSame($expected, $historyDuration->invoke($history, $seconds));
        }
    }

    public function testStatisticsAndHistoryShareStandaloneRateRounding(): void
    {
        $history = (new ReflectionClass(HistoryController::class))->newInstanceWithoutConstructor();
        $summary = new ReflectionMethod($history, 'summary');
        $result = $summary->invoke($history, [], [
            'plays' => 8,
            'unique_users' => 1,
            'watch_sec' => 59,
            'estimated_plays' => 0,
            'transcodes' => 1,
        ], 8, 0);

        $this->assertIsArray($result);
        $this->assertSame(13, PlaybackStatisticsService::standalonePercentage(1, 8));
        $this->assertSame('13%', $result['transcoded_pct']);
        $this->assertSame('<1m', $result['watch_time']);
    }
}
