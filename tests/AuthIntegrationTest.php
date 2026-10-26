<?php

use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\LoginThrottle;
use Mk\Framework\Push\PushDeviceCapability;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\RememberTokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the owned auth layer (Phase 7). Needs the database;
 * skipped automatically when it isn't reachable. Uses a throwaway test user.
 */
final class AuthIntegrationTest extends TestCase
{
    private const USERNAME = 'phpunit_testuser';
    private const PASSWORD = 'test-password-123';
    private const ENV_USERNAME = 'phpunit_env_admin';
    private const ENV_PASSWORD = 'environment-password-123';
    private const CLI_USERNAME = 'phpunit_cli_admin';
    private const CLI_PASSWORD = 'command-password-123';
    private const OWNER_USERNAME = 'phpunit_policy_owner';
    private const USER_USERNAME = 'phpunit_policy_user';
    private const GUEST_USERNAME = 'phpunit_policy_guest';

    private Database $db;
    private \Dibi\Connection $dibi;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->db = Container::db();
            $this->dibi = $this->db->getDibi();
            // Fresh databases (no SQL import) get the auth tables on demand,
            // the same way the console user commands bootstrap them.
            $this->db->ensureAuthSchema();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $_SESSION = [];
        $_COOKIE = [];
        $this->cleanup();
        $this->userId = $this->db->addAuthUser(self::USERNAME, self::PASSWORD, 'Tester', Authorization::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
        $_SESSION = [];
        $_COOKIE = [];
    }

    public function testSuccessfulLoginSetsSessionAndRole(): void
    {
        $auth = new Authorization($this->db);

        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $this->assertTrue($auth->isUserLoggedIn());
        $this->assertSame(self::USERNAME, $auth->getUserData()['username']);

        // role admin(2): has admin, but not owner(1)
        $this->assertTrue($auth->hasRole(Authorization::ROLE_ADMIN));
        $this->assertFalse($auth->hasRole(Authorization::ROLE_OWNER));
    }

    public function testPasswordResetRevokesOrdinarySessionsAndKeepsOtherUsersValid(): void
    {
        $otherId = $this->db->addAuthUser(self::USER_USERNAME, self::PASSWORD, 'Other', Authorization::ROLE_USER);
        $other = new Authorization($this->db);
        $this->assertTrue($other->userLogin(self::USER_USERNAME, self::PASSWORD));
        $otherSession = $_SESSION;
        $_SESSION = [];
        $auth = new Authorization($this->db);
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $firstSession = $_SESSION;
        $_SESSION = [];
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $secondSession = $_SESSION;
        $this->assertTrue($this->db->setUserPassword(self::USERNAME, 'replacement-password-123'));
        foreach ([$firstSession, $secondSession] as $session) {
            $_SESSION = $session;
            $request = new Authorization($this->db);
            $this->assertFalse($request->isUserLoggedIn());
            $this->assertNull($request->verifiedUser());
            $this->assertArrayNotHasKey('auth_user', $_SESSION);
        }
        $_SESSION = $otherSession;
        $this->assertTrue((new Authorization($this->db))->isUserLoggedIn());
        $this->assertSame($otherId, $_SESSION['auth_user']['id']);
    }

    public function testDeletedAccountAndLegacyOrMalformedSessionLoseReadAccess(): void
    {
        $auth = new Authorization($this->db);
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $validSession = $_SESSION;
        unset($_SESSION['auth_user']['auth_generation']);
        $this->assertFalse((new Authorization($this->db))->isUserLoggedIn());
        foreach (['0', -1, [], null] as $generation) {
            $_SESSION = $validSession;
            $_SESSION['auth_user']['auth_generation'] = $generation;
            $this->assertFalse((new Authorization($this->db))->isUserLoggedIn());
        }
        $_SESSION = ['auth_user' => 'invalid'];
        $this->assertFalse((new Authorization($this->db))->isUserLoggedIn());
        $_SESSION = $validSession;
        $this->dibi->delete('users')->where('id = %i', $this->userId)->execute();
        $this->assertFalse((new Authorization($this->db))->isUserLoggedIn());
    }

    public function testResetRevokesRememberedRestorationSession(): void
    {
        $tokens = new RememberTokenRepository($this->db);
        $auth = new Authorization($this->db, $tokens, rememberCookieWriter: static function (): void {
        });
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD, true));
        $_SESSION = [];
        $restored = new Authorization($this->db, $tokens, rememberCookieWriter: static function (): void {
        });
        $this->assertTrue($restored->isUserLoggedIn());
        $this->assertTrue($this->db->setUserPassword(self::USERNAME, 'replacement-password-123'));
        $this->assertFalse($restored->isUserLoggedIn());
        $this->assertSame(0, $this->rememberTokenCount($this->userId));
    }

    public function testResetAfterLoginCredentialReadCannotEstablishNewGeneration(): void
    {
        $interleaved = false;
        $listener = function (\Dibi\Event $event) use (&$interleaved): void {
            if (!$interleaved && preg_match('/DELETE FROM [`\[]login_attempts/', (string) $event->sql)) {
                $interleaved = true;
                $this->db->setUserPassword(self::USERNAME, 'replacement-password-123');
            }
        };
        $this->dibi->onEvent[] = $listener;
        try {
            $auth = new Authorization($this->db);
            $auth->userLogin(self::USERNAME, self::PASSWORD, true);
            $this->assertTrue($interleaved);
            $this->assertFalse($auth->isUserLoggedIn());
            $this->assertSame(0, $this->rememberTokenCount($this->userId));
        } finally {
            array_pop($this->dibi->onEvent);
            // Dibi's profiling events retain unbuffered SQLite statements.
            // Release this instrumented connection before subprocess checks.
            $this->dibi->disconnect();
        }
    }

    public function testNewUsernameIsTrimmedForLoginAndPasswordReset(): void
    {
        $id = $this->db->addAuthUser('  ' . self::USER_USERNAME . '  ', self::PASSWORD, 'Spaced', Authorization::ROLE_USER);
        try {
            $stored = $this->dibi->select('username')->from('users')->where('id = %i', $id)->fetchSingle();
            $this->assertSame(self::USER_USERNAME, $stored);
            $auth = new Authorization($this->db);
            $this->assertTrue($auth->userLogin(' ' . self::USER_USERNAME . ' ', self::PASSWORD));
            $this->assertTrue($this->db->setUserPassword(' ' . self::USER_USERNAME . ' ', 'new-password-123'));
            $_SESSION = [];
            $this->assertTrue((new Authorization($this->db))->userLogin(self::USER_USERNAME, 'new-password-123'));
        } finally {
            $this->dibi->delete('users')->where('id = %i', $id)->execute();
        }
    }

    public function testWhitespaceOnlyUsernameCannotBeCreated(): void
    {
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->db->addAuthUser(" \t ", self::PASSWORD, 'Invalid', Authorization::ROLE_USER);
        } finally {
            $this->dibi->delete('users')->where('username = %s', " \t ")->execute();
        }
    }

    public function testWrongPasswordFails(): void
    {
        $auth = new Authorization($this->db);

        $this->assertFalse($auth->userLogin(self::USERNAME, 'definitely-wrong'));
        $this->assertFalse($auth->isUserLoggedIn());
    }

    public function testLockoutBlocksEvenCorrectPassword(): void
    {
        $auth = new Authorization($this->db);

        for ($i = 0; $i < 5; $i++) {
            $auth->userLogin(self::USERNAME, 'wrong');
        }

        // Now locked out; the correct password is rejected too.
        $this->assertFalse($auth->userLogin(self::USERNAME, self::PASSWORD));
    }

    public function testExpiredLockoutStartsWithOneFreshFailure(): void
    {
        $identifier = LoginThrottle::pairIdentifier(self::USERNAME, '203.0.113.10');
        $this->dibi->insert('login_attempts', [
            'identifier' => $identifier,
            'attempts' => 5,
            'locked_until' => '2000-01-01 00:00:00',
            'window_started_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->execute();

        LoginThrottle::recordFailure(self::USERNAME, '203.0.113.10');

        $row = $this->dibi->select('attempts, locked_until')
            ->from('login_attempts')
            ->where('identifier = %s', $identifier)
            ->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['locked_until']);
    }

    public function testLogoutClearsSession(): void
    {
        $auth = new Authorization($this->db);
        $auth->userLogin(self::USERNAME, self::PASSWORD);
        $this->assertTrue($auth->isUserLoggedIn());

        $auth->userLogout();
        $this->assertFalse($auth->isUserLoggedIn());
    }

    public function testLogoutClearsLocalAuthenticationWhenRememberRevocationFails(): void
    {
        $logger = Container::logger();
        Container::set('logger', new \Psr\Log\NullLogger());
        $cookieWrites = [];
        $auth = new Authorization($this->db, rememberCookieWriter: static function (string $value, int $expires) use (&$cookieWrites): void {
            $cookieWrites[] = $value;
        });
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD, true));
        $this->assertArrayHasKey(Authorization::REMEMBER_COOKIE, $_COOKIE);
        $sqlite = $this->db->getPlatform()->isSqlite();
        $this->dibi->query($sqlite
            ? 'CREATE TRIGGER phpunit_logout_failure BEFORE DELETE ON auth_remember_tokens BEGIN SELECT RAISE(ABORT, "synthetic logout failure"); END'
            : "CREATE TRIGGER phpunit_logout_failure BEFORE DELETE ON auth_remember_tokens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic logout failure'");
        try {
            $this->assertTrue($auth->userLogout());
            $this->assertFalse($auth->isUserLoggedIn());
            $this->assertArrayNotHasKey(Authorization::REMEMBER_COOKIE, $_COOKIE);
            $this->assertSame('', $cookieWrites[array_key_last($cookieWrites)]);
            $this->assertSame(1, $this->rememberTokenCount($this->userId));
        } finally {
            $this->dibi->query('DROP TRIGGER phpunit_logout_failure');
            Container::set('logger', $logger);
        }
    }

    public function testPushRevocationFailureDoesNotPreventRememberRevocationOrLocalLogout(): void
    {
        $logger = Container::logger();
        Container::set('logger', new \Psr\Log\NullLogger());
        $repository = new PushSubscriptionRepository($this->db);
        $capability = new PushDeviceCapability(cookieWriter: static fn (): bool => true);
        $hash = $capability->hashForEnrollment();
        $auth = new Authorization(
            $this->db,
            rememberCookieWriter: static function (): void {
            },
            pushSubscriptions: $repository,
            pushDeviceCapability: $capability
        );
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD, true));
        $repository->save(
            'https://updates.push.services.mozilla.com/wpush/v2/logout-current',
            rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
            'Firefox/130',
            $hash,
            $this->userId,
            true
        );
        $this->dibi->query($this->db->getPlatform()->isSqlite()
            ? 'CREATE TRIGGER phpunit_push_logout_failure BEFORE DELETE ON push_subscriptions BEGIN SELECT RAISE(ABORT, "synthetic push failure"); END'
            : "CREATE TRIGGER phpunit_push_logout_failure BEFORE DELETE ON push_subscriptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic push failure'");
        try {
            $this->assertTrue($auth->userLogout());
            $this->assertFalse($auth->isUserLoggedIn());
            $this->assertArrayNotHasKey(Authorization::REMEMBER_COOKIE, $_COOKIE);
            $this->assertArrayNotHasKey(PushDeviceCapability::COOKIE_NAME, $_COOKIE);
            $this->assertSame(0, $this->rememberTokenCount($this->userId));
            $this->assertSame(1, $repository->count());
        } finally {
            $this->dibi->query('DROP TRIGGER phpunit_push_logout_failure');
            Container::set('logger', $logger);
        }
    }

    public function testLogoutDoesNotRequireAUserLookup(): void
    {
        $database = Database::sqlite(':memory:');
        $database->ensureAuthSchema();
        $database->addAuthUser('logout-user', 'synthetic-password', 'Logout User', Authorization::ROLE_USER);
        $auth = new Authorization($database, rememberCookieWriter: static function (): void {
        });
        $this->assertTrue($auth->userLogin('logout-user', 'synthetic-password', true));
        $database->getDibi()->query('DROP TABLE users');
        $this->assertTrue($auth->userLogout());
        $this->assertFalse($auth->isUserLoggedIn());
        $this->assertArrayNotHasKey(Authorization::REMEMBER_COOKIE, $_COOKIE);
        $this->assertSame(0, (int) $database->getDibi()->select('COUNT(*)')->from('auth_remember_tokens')->fetchSingle());
        $database->getDibi()->disconnect();
    }

    public function testAuthenticatedRoleCapabilitiesAndLiveRoleChanges(): void
    {
        $previousAuth = getenv('AUTH_ENABLED');
        putenv('AUTH_ENABLED=true');
        try {
            $this->db->addAuthUser(self::OWNER_USERNAME, self::PASSWORD, 'Policy Owner', Authorization::ROLE_OWNER);
            $this->db->addAuthUser(self::USER_USERNAME, self::PASSWORD, 'Policy User', Authorization::ROLE_USER);
            $this->db->addAuthUser(self::GUEST_USERNAME, self::PASSWORD, 'Policy Guest', Authorization::ROLE_GUEST);

            $expectations = [
                self::OWNER_USERNAME => [true, true, true, true],
                self::USERNAME => [true, true, true, true],
                self::USER_USERNAME => [false, true, true, false],
                self::GUEST_USERNAME => [false, false, false, false],
            ];
            foreach ($expectations as $username => $expected) {
                $_SESSION = [];
                $auth = new Authorization($this->db);
                $this->assertTrue($auth->userLogin($username, self::PASSWORD));
                $this->assertSame($expected, [
                    $auth->can(Authorization::CAPABILITY_MANAGE_GLOBAL),
                    $auth->can(Authorization::CAPABILITY_ENROLL_PUSH),
                    $auth->can(Authorization::CAPABILITY_MANAGE_OWN_PUSH),
                    $auth->can(Authorization::CAPABILITY_MANAGE_ALL_PUSH),
                ], $username);
                $this->assertSame($expected[0], $auth->canSendGlobalNotificationTest(), $username);
            }

            $_SESSION = [];
            $auth = new Authorization($this->db);
            $this->assertTrue($auth->userLogin(self::USER_USERNAME, self::PASSWORD));
            $this->assertTrue($auth->can(Authorization::CAPABILITY_ENROLL_PUSH));
            $this->assertTrue($this->db->setUserRole(self::USER_USERNAME, Authorization::ROLE_GUEST));
            $this->assertFalse($auth->can(Authorization::CAPABILITY_ENROLL_PUSH));
            $this->dibi->update('users', ['role' => 9])->where('username = %s', self::USER_USERNAME)->execute();
            $this->assertNull($auth->verifiedUser());
        } finally {
            putenv($previousAuth === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previousAuth);
        }
    }

    public function testTrustedAuthOffModeKeepsHouseholdCapabilitiesAvailable(): void
    {
        $previousAuth = getenv('AUTH_ENABLED');
        putenv('AUTH_ENABLED=false');
        try {
            $auth = new Authorization($this->db);
            $this->assertFalse($auth->isUserLoggedIn());
            $this->assertTrue($auth->can(Authorization::CAPABILITY_MANAGE_GLOBAL));
            $this->assertFalse($auth->canSendGlobalNotificationTest());
            $this->assertTrue($auth->can(Authorization::CAPABILITY_ENROLL_PUSH));
            $this->assertTrue($auth->can(Authorization::CAPABILITY_MANAGE_ALL_PUSH));
        } finally {
            putenv($previousAuth === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previousAuth);
        }
    }

    public function testExplicitLogoutRevokesOnlyTheCurrentPushDevice(): void
    {
        $nextUserId = $this->db->addAuthUser(self::USER_USERNAME, self::PASSWORD, 'Next User', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->db);
        $token = rtrim(strtr(base64_encode(str_repeat("\x04", 32)), '+/', '-_'), '=');
        $_COOKIE[PushDeviceCapability::COOKIE_NAME] = $token;
        $capability = new PushDeviceCapability(cookieWriter: static fn (): bool => true);
        $auth = new Authorization(
            $this->db,
            pushSubscriptions: $repository,
            pushDeviceCapability: $capability,
        );
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $repository->save(
            'https://updates.push.services.mozilla.com/wpush/v2/logout-current',
            rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
            'Firefox/130',
            hash('sha256', $token),
            $this->userId,
            true,
        );
        $otherEndpoint = 'https://updates.push.services.mozilla.com/wpush/v2/logout-other';
        $repository->save(
            $otherEndpoint,
            rtrim(strtr(base64_encode(str_repeat('c', 65)), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(str_repeat('d', 16)), '+/', '-_'), '='),
            'Chrome/130',
            hash('sha256', 'other-device'),
            $this->userId,
            true,
        );

        $auth->userLogout();

        $this->assertArrayNotHasKey(PushDeviceCapability::COOKIE_NAME, $_COOKIE);
        $this->assertSame(1, $repository->count());
        $this->assertSame($otherEndpoint, $repository->all()[0]['endpoint']);

        $repository->save(
            'https://updates.push.services.mozilla.com/wpush/v2/logout-current',
            rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
            'Firefox/130',
            hash('sha256', 'replacement-device'),
            $nextUserId,
            true,
        );
        $owner = $this->dibi->select('user_id')->from('push_subscriptions')
            ->where('endpoint_hash = %s', hash('sha256', 'https://updates.push.services.mozilla.com/wpush/v2/logout-current'))
            ->fetchSingle();
        $this->assertSame($nextUserId, (int) $owner);
    }

    public function testSessionExpiryDoesNotRevokeThePushDevice(): void
    {
        $now = 1_788_192_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $repository = new PushSubscriptionRepository($this->db);
        $auth = new Authorization($this->db, clock: $clock);
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $repository->save(
            'https://updates.push.services.mozilla.com/wpush/v2/expiry-device',
            rtrim(strtr(base64_encode(str_repeat('e', 65)), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(str_repeat('f', 16)), '+/', '-_'), '='),
            null,
            hash('sha256', 'expiry-device'),
            $this->userId,
            true,
        );

        $now += Authorization::SESSION_ABSOLUTE_TIMEOUT + 1;
        $expired = new Authorization($this->db, clock: $clock);

        $this->assertFalse($expired->isUserLoggedIn());
        $this->assertSame(1, $repository->count());
    }

    public function testRememberedLoginRestoresAndRotatesAfterTheNormalSessionExpires(): void
    {
        $now = 1_788_192_000;
        $written = [];
        $writer = static function (string $value, int $expires) use (&$written): void {
            $written[] = ['value' => $value, 'expires' => $expires];
        };
        $clock = static function () use (&$now): int {
            return $now;
        };
        $tokens = new RememberTokenRepository($this->db);
        $auth = new Authorization($this->db, $tokens, $clock, $writer);

        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD, true));
        $originalToken = (string) $_COOKIE[Authorization::REMEMBER_COOKIE];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}\.[a-f0-9]{64}$/', $originalToken);
        $this->assertSame($now + Authorization::REMEMBER_LIFETIME, $written[array_key_last($written)]['expires']);

        $now += Authorization::SESSION_ABSOLUTE_TIMEOUT + 1;
        $restored = new Authorization($this->db, $tokens, $clock, $writer);

        $this->assertTrue($restored->isUserLoggedIn());
        $this->assertSame(self::USERNAME, $restored->getUserData()['username']);
        $rotatedToken = (string) $_COOKIE[Authorization::REMEMBER_COOKIE];
        $this->assertNotSame($originalToken, $rotatedToken);
        $this->assertSame(1, $this->rememberTokenCount($this->userId));
    }

    public function testOrdinaryLoginExpiresWithoutPersistentToken(): void
    {
        $now = 1_788_192_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $auth = new Authorization(
            $this->db,
            new RememberTokenRepository($this->db),
            $clock,
            static function (string $value, int $expires): void {
            },
        );

        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $now += Authorization::SESSION_ABSOLUTE_TIMEOUT + 1;
        $expired = new Authorization(
            $this->db,
            new RememberTokenRepository($this->db),
            $clock,
            static function (string $value, int $expires): void {
            },
        );

        $this->assertFalse($expired->isUserLoggedIn());
        $this->assertSame(0, $this->rememberTokenCount($this->userId));
    }

    public function testOrdinarySessionStillExpiresAfterIdleTimeout(): void
    {
        $now = 1_788_192_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $auth = new Authorization($this->db, clock: $clock);
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $now += Authorization::SESSION_IDLE_TIMEOUT + 1;
        $this->assertFalse((new Authorization($this->db, clock: $clock))->isUserLoggedIn());
    }

    public function testTransparentHashUpgradePreservesExistingSessionGeneration(): void
    {
        $auth = new Authorization($this->db);
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $firstSession = $_SESSION;
        $this->dibi->update('users', ['password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])])
            ->where('id = %i', $this->userId)->execute();
        $_SESSION = [];
        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $_SESSION = $firstSession;
        $this->assertTrue((new Authorization($this->db))->isUserLoggedIn());
    }

    public function testLogoutRevokesRememberedLogin(): void
    {
        $now = 1_788_192_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $writer = static function (string $value, int $expires): void {
        };
        $tokens = new RememberTokenRepository($this->db);
        $auth = new Authorization($this->db, $tokens, $clock, $writer);

        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD, true));
        $token = (string) $_COOKIE[Authorization::REMEMBER_COOKIE];
        $auth->userLogout();

        $this->assertArrayNotHasKey(Authorization::REMEMBER_COOKIE, $_COOKIE);
        $this->assertSame(0, $this->rememberTokenCount($this->userId));

        $_SESSION = [];
        $_COOKIE[Authorization::REMEMBER_COOKIE] = $token;
        $restored = new Authorization($this->db, $tokens, $clock, $writer);
        $this->assertFalse($restored->isUserLoggedIn());
    }

    public function testUserEnsureReadsInitialAdminFromEnvironment(): void
    {
        $result = $this->runConsole(['user:ensure'], [
            'AUTH_ADMIN_USER' => self::ENV_USERNAME,
            'AUTH_ADMIN_PASSWORD' => self::ENV_PASSWORD,
        ]);

        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $row = $this->dibi->select('username, password, role')->from('users')
            ->where('username = %s', self::ENV_USERNAME)->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(self::ENV_USERNAME, (string) $row['username']);
        $this->assertTrue(password_verify(self::ENV_PASSWORD, (string) $row['password']));
        $this->assertSame(Authorization::ROLE_OWNER, (int) $row['role']);
    }

    public function testInvalidAuthConfigurationStopsConsoleBeforeDatabaseAccess(): void
    {
        $result = $this->runConsole(['user:list'], ['AUTH_ENABLED' => 'invalid-auth-token', 'DB_NAME' => 'does-not-exist']);
        $this->assertNotSame(0, $result['exitCode']);
        $this->assertStringContainsString('AUTH_ENABLED must be a valid boolean.', $result['error']);
        $this->assertStringNotContainsString('invalid-auth-token', $result['error'] . $result['output']);
    }

    public function testUserEnsureStillAcceptsCommandLineCredentials(): void
    {
        $result = $this->runConsole([
            'user:ensure',
            self::CLI_USERNAME,
            self::CLI_PASSWORD,
        ], [
            'AUTH_ADMIN_USER' => self::ENV_USERNAME,
            'AUTH_ADMIN_PASSWORD' => self::ENV_PASSWORD,
        ]);

        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $row = $this->dibi->select('username, password, role')->from('users')
            ->where('username = %s', self::CLI_USERNAME)->fetch();
        $this->assertNotFalse($row);
        $this->assertTrue(password_verify(self::CLI_PASSWORD, (string) $row['password']));
        $this->assertSame(0, (int) $this->dibi->select('COUNT(*)')->from('users')
            ->where('username = %s', self::ENV_USERNAME)->fetchSingle());
    }

    public function testUserEnsureNormalizesSpacedEnvironmentNameAndRemainsIdempotent(): void
    {
        $result = $this->runConsole(['user:ensure'], [
            'AUTH_ADMIN_USER' => ' ' . self::ENV_USERNAME . ' ',
            'AUTH_ADMIN_PASSWORD' => self::ENV_PASSWORD,
        ]);
        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $this->assertSame(self::ENV_USERNAME, (string) $this->dibi->select('username')->from('users')
            ->where('username = %s', self::ENV_USERNAME)->fetchSingle());
        $again = $this->runConsole(['user:ensure', self::ENV_USERNAME, self::ENV_PASSWORD], []);
        $this->assertSame(0, $again['exitCode'], $again['error'] . $again['output']);
        $this->assertStringContainsString('already exists', $again['output']);
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('users')
            ->where('username = %s', self::ENV_USERNAME)->fetchSingle());
    }

    public function testUserEnsureDoesNotSilentlyDuplicateALegacySpacedAccount(): void
    {
        $legacy = ' ' . self::CLI_USERNAME . ' ';
        $this->dibi->insert('users', [
            'username' => $legacy,
            'password' => password_hash(self::CLI_PASSWORD, PASSWORD_DEFAULT),
            'name' => 'Legacy',
            'role' => Authorization::ROLE_OWNER,
        ])->execute();

        $result = $this->runConsole(['user:ensure', self::CLI_USERNAME, self::CLI_PASSWORD], []);

        $this->assertNotSame(0, $result['exitCode']);
        $this->assertStringContainsString('existing account', $result['error']);
        $this->assertSame($legacy, (string) $this->dibi->select('username')->from('users')
            ->where('username = %s', $legacy)->fetchSingle());
        $this->assertSame(0, (int) $this->dibi->select('COUNT(*)')->from('users')
            ->where('username = %s', self::CLI_USERNAME)->fetchSingle());
    }

    public function testConsoleCanExplicitlyRestoreAnOwnerRole(): void
    {
        $result = $this->runConsole(['user:role', self::USERNAME, (string) Authorization::ROLE_OWNER], []);

        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $this->assertStringContainsString('Role updated', $result['output']);
        $this->assertSame(
            Authorization::ROLE_OWNER,
            (int) $this->dibi->select('role')->from('users')->where('id = %i', $this->userId)->fetchSingle(),
        );
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environmentOverrides
     *
     * @return array{exitCode: int, output: string, error: string}
     */
    private function runConsole(array $arguments, array $environmentOverrides): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);

        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'DB_HOST' => DATABASE_HOST,
            'DB_DRIVER' => DATABASE_DRIVER_DIBI,
            'DB_NAME' => DATABASE_NAME,
            'DB_USER' => DATABASE_USERNAME,
            'DB_PASS' => DATABASE_PASSWORD,
        ], $environmentOverrides);
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $environment['DB_PORT'] = (string) DATABASE_PORT;
        }

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/bin/console.php', ...$arguments],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment
        );
        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'output' => $output,
            'error' => $error,
        ];
    }

    private function cleanup(): void
    {
        if ($this->databaseTableExists('push_subscriptions')) {
            $this->dibi->delete('push_subscriptions')->where('endpoint_hash IN %in', array_map(
                static fn (string $endpoint): string => hash('sha256', $endpoint),
                [
                    'https://updates.push.services.mozilla.com/wpush/v2/logout-current',
                    'https://updates.push.services.mozilla.com/wpush/v2/logout-other',
                    'https://updates.push.services.mozilla.com/wpush/v2/expiry-device',
                ],
            ))->execute();
        }
        $userIds = $this->dibi->select('id')->from('users')->where('username IN %in', [
            self::USERNAME,
            self::ENV_USERNAME,
            self::CLI_USERNAME,
            ' ' . self::CLI_USERNAME . ' ',
            ' ' . self::ENV_USERNAME . ' ',
            '  ' . self::USER_USERNAME . '  ',
            self::OWNER_USERNAME,
            self::USER_USERNAME,
            self::GUEST_USERNAME,
        ])->fetchPairs(null, 'id');
        foreach ($userIds as $userId) {
            $this->dibi->delete('auth_remember_tokens')->where('user_id = %i', (int) $userId)->execute();
        }
        $this->dibi->delete('users')->where('username IN %in', [
            self::USERNAME,
            self::ENV_USERNAME,
            self::CLI_USERNAME,
            ' ' . self::CLI_USERNAME . ' ',
            ' ' . self::ENV_USERNAME . ' ',
            '  ' . self::USER_USERNAME . '  ',
            self::OWNER_USERNAME,
            self::USER_USERNAME,
            self::GUEST_USERNAME,
        ])->execute();
        foreach ([self::USERNAME, self::ENV_USERNAME, self::CLI_USERNAME, self::OWNER_USERNAME, self::USER_USERNAME, self::GUEST_USERNAME] as $username) {
            $prefix = $username . '|';
            $this->dibi->delete('login_attempts')
                ->where('SUBSTR(identifier, 1, %i) = %s', strlen($prefix), $prefix)
                ->execute();
            foreach (['unknown', '203.0.113.10'] as $ip) {
                $this->dibi->delete('login_attempts')->where('identifier = %s', LoginThrottle::pairIdentifier($username, $ip))->execute();
            }
        }
        foreach (['unknown', '203.0.113.10'] as $ip) {
            $this->dibi->delete('login_attempts')->where('identifier = %s', LoginThrottle::sourceIdentifier($ip))->execute();
        }
    }

    private function rememberTokenCount(int $userId): int
    {
        return (int) $this->dibi->select('COUNT(*)')->from('auth_remember_tokens')
            ->where('user_id = %i', $userId)->fetchSingle();
    }

    private function databaseTableExists(string $table): bool
    {
        return in_array($table, $this->dibi->getDatabaseInfo()->getTableNames(), true);
    }
}
