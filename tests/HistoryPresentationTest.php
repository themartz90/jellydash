<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class HistoryPresentationTest extends TestCase
{
    public function testFilteredEmptyHistoryOffersAnUnrestrictedPeriod(): void
    {
        $html = $this->twig()->render('history/_empty.twig', ['summary' => ['total' => 12]]);
        $xpath = $this->xpath($html);
        $links = $xpath->query('//a[normalize-space(.)="Clear filters"]');

        self::assertSame(1, $links->length);
        self::assertSame('/history?range=all', $links->item(0)->getAttribute('href'));
        self::assertStringContainsString('date range', $html);
        self::assertStringNotContainsString('Import existing history', $html);
    }

    public function testNewInstallationStillOffersHistoryImport(): void
    {
        $html = $this->twig()->render('history/_empty.twig', ['summary' => ['total' => 0]]);
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//a[@href="/settings#import-history"]')->length);
        self::assertStringContainsString('No plays yet', $html);
        self::assertStringNotContainsString('Clear filters', $html);
    }

    public function testPhoneDisclosureRetainsEscapedPlaybackDetails(): void
    {
        $play = [
            'time' => '20:45', 'initials' => 'MJ', 'avatarBg' => '#302040', 'avatarUrl' => '',
            'user' => 'Mártin & Jane', 'title' => 'An <unusually long> title', 'sub' => 'S1 E2',
            'poster' => 'none', 'library' => 'Movies & TV', 'client' => 'Web <client>',
            'device' => 'Living room & television', 'watchedLabel' => '2h 45m recorded',
            'methodLabel' => 'Direct Stream', 'isTranscode' => false,
            'finished' => true, 'completionPct' => 100,
        ];
        $html = $this->twig()->render('history/_history_row.twig', ['play' => $play]);
        $xpath = $this->xpath($html);
        $details = $xpath->query('//details')->item(0);

        self::assertInstanceOf(DOMElement::class, $details);
        $summary = $xpath->query('./summary', $details)->item(0)->textContent;
        self::assertStringContainsString('Playback details', $summary);
        self::assertStringContainsString($play['title'], $summary);
        self::assertStringContainsString($play['user'], $summary);
        self::assertStringContainsString('20:45', $summary);
        foreach (['methodLabel', 'client', 'device', 'library'] as $field) {
            self::assertStringContainsString($play[$field], $details->textContent);
        }
        self::assertSame(0, $xpath->query('//unusually | //client')->length);
        self::assertStringContainsString('2h 45m recorded', $html);
        self::assertStringNotContainsString('Watched</span>', $html);
        self::assertSame(1, $xpath->query('//*[@aria-label="Marked complete"]')->length);
    }

    public function testShortRecordedViewingCanShowFullPositionWithoutClaimingFullWatchTime(): void
    {
        $controller = (new ReflectionClass(Mk\Framework\Pages\HistoryController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'rowView');
        $row = new Dibi\Row(['item_type' => 'Movie', 'item_id' => 'synthetic-movie', 'play_method' => 'DirectPlay',
            'watched_sec' => 7200, 'watch_duration_sec' => 780, 'runtime_sec' => 7200, 'is_finished' => 1,
            'series_name' => null, 'item_name' => 'Synthetic movie', 'user_name' => 'Synthetic viewer',
            'user_id' => null, 'library' => '', 'client' => 'Findroid', 'device' => 'Synthetic phone']);
        $play = $method->invoke($controller, $row, new DateTimeImmutable('2000-01-01'), new Mk\Framework\Jellyfin\JellyfinUserAvatars());
        self::assertSame('13m recorded', $play['watchedLabel']);
        self::assertSame(100, $play['completionPct']);
        self::assertTrue($play['finished']);
        $html = $this->twig()->render('history/_history_row.twig', ['play' => $play]);
        $xpath = $this->xpath($html);
        self::assertSame(1, $xpath->query('//*[@role="progressbar" and @aria-valuenow="100" and @aria-label="Furthest playback position"]')->length);
        self::assertStringContainsString('Position 100%', $html);
        self::assertSame(1, $xpath->query('//*[@aria-label="At least 95% position"]')->length);
        $row['watch_duration_sec'] = null;
        $row['runtime_sec'] = 0;
        $legacy = $method->invoke($controller, $row, new DateTimeImmutable('2000-01-01'), new Mk\Framework\Jellyfin\JellyfinUserAvatars());
        self::assertStringEndsWith(' estimated', $legacy['watchedLabel']);
        $legacyHtml = $this->twig()->render('history/_history_row.twig', ['play' => $legacy]);
        self::assertStringContainsString('Position unavailable', $legacyHtml);
        self::assertSame(0, $this->xpath($legacyHtml)->query('//*[@role="progressbar"]')->length);
        $row['session_key'] = 'pr-v2-' . str_repeat('a', 40);
        $row['watch_duration_sec'] = 780;
        $row['watched_sec'] = 780;
        $row['runtime_sec'] = 7200;
        $imported = $method->invoke($controller, $row, new DateTimeImmutable('2000-01-01'), new Mk\Framework\Jellyfin\JellyfinUserAvatars());
        self::assertFalse($imported['positionKnown']);
        self::assertSame('Marked complete', $imported['finishedLabel']);
        self::assertSame('13m recorded', $imported['watchedLabel']);
        self::assertStringContainsString('Position unavailable', $this->twig()->render('history/_history_row.twig', ['play' => $imported]));
        $row['session_key'] = 'synthetic-live';
        $row['runtime_sec'] = 10000;
        $row['watched_sec'] = 9460;
        $row['is_finished'] = 0;
        $belowThreshold = $method->invoke($controller, $row, new DateTimeImmutable('2000-01-01'), new Mk\Framework\Jellyfin\JellyfinUserAvatars());
        self::assertSame(95, $belowThreshold['completionPct']);
        self::assertFalse($belowThreshold['finished']);
        $row['watched_sec'] = 9500;
        $atThreshold = $method->invoke($controller, $row, new DateTimeImmutable('2000-01-01'), new Mk\Framework\Jellyfin\JellyfinUserAvatars());
        self::assertTrue($atThreshold['finished']);
        self::assertSame('At least 95% position', $atThreshold['finishedLabel']);
    }

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(TEMPLATES_DIR), ['autoescape' => 'html']);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);

        return new DOMXPath($document);
    }
}
