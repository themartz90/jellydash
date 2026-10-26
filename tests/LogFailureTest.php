<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\ErrorHandler;
use Mk\Framework\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogFailureTest extends TestCase
{
    private LoggerInterface $previousLogger;
    private string $path;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->previousLogger = Container::logger();
        $this->path = sys_get_temp_dir() . '/jellydash-log-failure-' . bin2hex(random_bytes(8));
        mkdir($this->path);
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->path . '/fallback.log');
        Container::set('logger', new Logger('test', [new StreamHandler($this->path)]));
    }

    protected function tearDown(): void
    {
        Container::set('logger', $this->previousLogger);
        ini_set('error_log', $this->previousErrorLog);
        if (is_file($this->path . '/fallback.log')) {
            unlink($this->path . '/fallback.log');
        }
        rmdir($this->path);
    }

    public function testEveryFacadeMethodSurvivesAnActualLogOpenFailure(): void
    {
        Log::logException(new RuntimeException('private synthetic failure'));
        Log::logErrorMessage('private synthetic error', self::class);
        Log::logInfoMessage('private synthetic info', self::class);
        Log::logDebugMessage('private synthetic debug', self::class);

        $fallback = file_get_contents($this->path . '/fallback.log');
        $this->assertIsString($fallback);
        $this->assertStringContainsString('could not write', $fallback);
        $this->assertStringNotContainsString('private synthetic', $fallback);
    }

    public function testGlobalHandlerPreservesItsResponseWhenLoggingFails(): void
    {
        $previous = getenv('APP_DEBUG');
        putenv('APP_DEBUG=false');
        ob_start();
        try {
            ErrorHandler::handle(new RuntimeException('private synthetic failure'));
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
            putenv($previous === false ? 'APP_DEBUG' : 'APP_DEBUG=' . $previous);
        }
        $this->assertSame('<h1>500 Internal Server Error</h1>', $body);
    }
}
