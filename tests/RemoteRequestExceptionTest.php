<?php

declare(strict_types=1);

use Mk\Framework\Http\RemoteRequestException;
use PHPUnit\Framework\TestCase;

final class RemoteRequestExceptionTest extends TestCase
{
    public function testRetryHintsSupportSecondsAndDatesWithBoundedDelays(): void
    {
        $now = 1800000000;
        self::assertSame(120, RemoteRequestException::parseRetryAfter('120', $now));
        self::assertSame(120, RemoteRequestException::parseRetryAfter('000120', $now));
        self::assertSame(120, RemoteRequestException::parseRetryAfter(gmdate(DATE_RFC7231, $now + 120), $now));
        self::assertSame(86400, RemoteRequestException::parseRetryAfter(str_repeat('9', 100), $now));
        self::assertSame(86400, RemoteRequestException::parseRetryAfter(gmdate(DATE_RFC7231, $now + 864000), $now));
        foreach (['-1', 'tomorrow', '1.5', '120,300', str_repeat('9', 129), gmdate(DATE_RFC7231, $now - 1)] as $value) {
            self::assertSame(0, RemoteRequestException::parseRetryAfter($value, $now));
        }
    }
}
