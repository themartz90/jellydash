<?php

use Mk\Framework\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests for environment-backed Config (Phase 3 / #13, S14).
 */
final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Fully clear test keys from every source Config reads (a loaded .env
        // populates $_ENV, $_SERVER and getenv).
        foreach (['CFG_FOO', 'CFG_FLAG', 'CFG_PROCESS', 'APP_DEBUG', 'APP_ENV'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', Config::get('CFG_FOO', 'fallback'));
        $this->assertNull(Config::get('CFG_FOO'));
    }

    public function testGetReadsEnv(): void
    {
        $_ENV['CFG_FOO'] = 'bar';
        $this->assertSame('bar', Config::get('CFG_FOO'));
    }

    public function testProcessEnvironmentOverridesLoadedEnvironmentArrays(): void
    {
        putenv('CFG_PROCESS=process-value');
        $_ENV['CFG_PROCESS'] = 'dotenv-value';
        $_SERVER['CFG_PROCESS'] = 'server-value';

        $this->assertSame('process-value', Config::get('CFG_PROCESS'));
    }

    public function testBoolParsing(): void
    {
        $_ENV['CFG_FLAG'] = 'true';
        $this->assertTrue(Config::bool('CFG_FLAG'));

        $_ENV['CFG_FLAG'] = '0';
        $this->assertFalse(Config::bool('CFG_FLAG'));

        $this->assertTrue(Config::bool('CFG_MISSING', true));
    }

    public function testWorkerIntervalsUseOnlyPositiveWholeSecondsWithinOneDay(): void
    {
        foreach (['', '0', '-5', 'abc', '30 seconds', '030', '86401', '999999999999999999999'] as $value) {
            putenv('CFG_FOO=' . $value);
            $this->assertSame(30, Config::interval('CFG_FOO', 30), $value);
        }

        putenv('CFG_FOO=86400');
        $this->assertSame(86400, Config::interval('CFG_FOO', 30));
        putenv('CFG_FOO= 120 ');
        $this->assertSame(120, Config::interval('CFG_FOO', 30));
    }

    public function testInvalidAuthenticationFlagCannotBecomeAuthOff(): void
    {
        $previous = getenv('AUTH_ENABLED');
        try {
            putenv('AUTH_ENABLED=truee');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('AUTH_ENABLED must be a valid boolean.');
            Config::bool('AUTH_ENABLED');
        } finally {
            putenv($previous === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previous);
        }
    }

    public function testAuthenticationFlagKeepsAcceptedVariantsAndEmptyDefault(): void
    {
        $previous = getenv('AUTH_ENABLED');
        try {
            foreach (['true', 'TRUE', '  true ', "\tyes\t", '1', 'On'] as $value) {
                putenv('AUTH_ENABLED=' . $value);
                $this->assertTrue(Config::bool('AUTH_ENABLED'));
            }
            foreach (['false', 'FALSE', ' no ', '0', 'off', ''] as $value) {
                putenv('AUTH_ENABLED=' . $value);
                $this->assertFalse(Config::bool('AUTH_ENABLED'));
            }
        } finally {
            putenv($previous === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previous);
        }
    }

    public function testTlsFlagsRejectInvalidValuesAndKeepExplicitFalse(): void
    {
        foreach (['JELLYFIN_VERIFY_SSL', 'JELLYSEER_VERIFY_SSL', 'SAB_VERIFY_SSL'] as $key) {
            $previous = getenv($key);
            try {
                putenv($key . '=false');
                $this->assertFalse(Config::bool($key, true));
                putenv($key . '=true');
                $this->assertTrue(Config::bool($key, true));
                putenv($key . '=ture');
                try {
                    Config::bool($key, true);
                    $this->fail($key . ' accepted an invalid TLS flag.');
                } catch (InvalidArgumentException $e) {
                    $this->assertSame($key . ' must be a valid boolean.', $e->getMessage());
                }
            } finally {
                putenv($previous === false ? $key : $key . '=' . $previous);
            }
        }
    }

    public function testEnvDefaultsToProduction(): void
    {
        $this->assertSame('production', Config::env());
        $this->assertFalse(Config::isDebug());
        $this->assertTrue(Config::isProduction());
    }

    public function testTimezonePrefersAppTimezoneOverDockerTimezone(): void
    {
        $this->withTimezoneEnvironment('America/Los_Angeles', 'Europe/London', function (): void {
            $this->assertSame('America/Los_Angeles', Config::timezone());
        });
    }

    public function testTimezoneUsesDockerTimezoneWhenAppTimezoneIsMissing(): void
    {
        $this->withTimezoneEnvironment('', 'America/New_York', function (): void {
            $this->assertSame('America/New_York', Config::timezone());
        });
    }

    public function testTimezoneSkipsInvalidValuesAndFallsBackToUtc(): void
    {
        $this->withTimezoneEnvironment('Invalid/App', 'Europe/London', function (): void {
            $this->assertSame('Europe/London', Config::timezone());
        });

        $this->withTimezoneEnvironment('+02:00', 'Europe/London', function (): void {
            $this->assertSame('Europe/London', Config::timezone());
        });

        $this->withTimezoneEnvironment('Invalid/App', 'Invalid/Tz', function (): void {
            $this->assertSame('UTC', Config::timezone());
        });

        $this->withTimezoneEnvironment(null, null, function (): void {
            $this->assertSame('UTC', Config::timezone());
        });
    }

    private function withTimezoneEnvironment(?string $appTimezone, ?string $dockerTimezone, callable $assertion): void
    {
        $snapshot = [];

        foreach (['APP_TIMEZONE', 'TZ'] as $key) {
            $snapshot[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            if ($appTimezone !== null) {
                putenv('APP_TIMEZONE=' . $appTimezone);
            }
            if ($dockerTimezone !== null) {
                putenv('TZ=' . $dockerTimezone);
            }

            $assertion();
        } finally {
            foreach ($snapshot as $key => $values) {
                $process = $values['process'];
                putenv($process === false ? $key : $key . '=' . $process);

                if ($values['env_exists']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($values['server_exists']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}
