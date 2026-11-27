<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use PHPUnit\Framework\TestCase;

final class SeerrManagementPolicyTest extends TestCase
{
    private const AUTHENTICATED = 'seerr_manage_requests_authenticated';
    private const OPEN = 'seerr_manage_requests_open';

    public function testDefaultsModeOverridesFreshReadsAndRoles(): void
    {
        $oldAuth = getenv('AUTH_ENABLED');
        $oldSession = $_SESSION ?? [];
        $filesAuth = AppSettings::seerrFileRemovalKey(true);
        $filesOpen = AppSettings::seerrFileRemovalKey(false);
        $oldSettings = [self::AUTHENTICATED => AppSettings::getFresh(self::AUTHENTICATED), self::OPEN => AppSettings::get(self::OPEN),
            $filesAuth => AppSettings::getFresh($filesAuth), $filesOpen => AppSettings::getFresh($filesOpen)];
        $db = Container::db();
        $db->ensureAuthSchema();
        $id = null;
        $client = new JellyseerrClient('http://127.0.0.1:1', 'fixture-token');
        $_SESSION = [];

        try {
            AppSettings::set(self::AUTHENTICATED, null);
            AppSettings::set(self::OPEN, null);
            AppSettings::set($filesAuth, null);
            AppSettings::set($filesOpen, null);
            putenv('AUTH_ENABLED=false');
            $auth = new Authorization($db);
            self::assertTrue($auth->can(Authorization::CAPABILITY_MANAGE_GLOBAL));
            self::assertTrue($auth->canManageSeerrRequests($client));
            self::assertFalse($auth->canRemoveSeerrFiles($client));
            self::assertFalse($auth->canManageSeerrRequests(new JellyseerrClient('', 'fixture-token')));
            self::assertFalse($auth->canManageSeerrRequests(new JellyseerrClient('http://127.0.0.1:1', '')));
            AppSettings::set(self::OPEN, '0');
            self::assertFalse($auth->canManageSeerrRequests($client));

            putenv('AUTH_ENABLED=true');
            self::assertFalse($auth->canManageSeerrRequests($client));
            $name = 'seerr-test-' . bin2hex(random_bytes(5));
            $id = $db->addAuthUser($name, 'fixture-password-123', 'Fixture Owner', Authorization::ROLE_OWNER);
            self::assertTrue($auth->userLogin($name, 'fixture-password-123'));
            self::assertTrue($auth->canManageSeerrRequests($client));
            self::assertFalse($auth->canRemoveSeerrFiles($client));
            AppSettings::set($filesAuth, '1');
            self::assertTrue($auth->canRemoveSeerrFiles($client));
            self::assertFalse($auth->canManageSeerrRequests(new JellyseerrClient('', '')));

            $users = $db->getDibi();
            $users->update('users', ['role' => Authorization::ROLE_ADMIN])->where('id = %i', $id)->execute();
            self::assertTrue($auth->canManageSeerrRequests($client));
            AppSettings::set(self::AUTHENTICATED, '1');
            $users->update('app_settings', ['setting_value' => '0'])->where('setting_key = %s', self::AUTHENTICATED)->execute();
            self::assertFalse($auth->canManageSeerrRequests($client));
            self::assertTrue($auth->can(Authorization::CAPABILITY_MANAGE_GLOBAL));

            AppSettings::set(self::AUTHENTICATED, '1');
            putenv('AUTH_ENABLED=false');
            self::assertFalse($auth->canManageSeerrRequests($client));
            AppSettings::set(self::OPEN, '1');
            self::assertTrue($auth->canManageSeerrRequests($client));
            self::assertFalse($auth->canRemoveSeerrFiles($client));
            AppSettings::set($filesOpen, '1');
            self::assertTrue($auth->canRemoveSeerrFiles($client));

            putenv('AUTH_ENABLED=true');
            foreach ([Authorization::ROLE_USER, Authorization::ROLE_GUEST] as $role) {
                $users->update('users', ['role' => $role])->where('id = %i', $id)->execute();
                self::assertFalse($auth->canManageSeerrRequests($client));
                self::assertFalse($auth->canRemoveSeerrFiles($client));
            }
            $users->update('users', ['role' => Authorization::ROLE_OWNER])->where('id = %i', $id)->execute();
            self::assertTrue($auth->canManageSeerrRequests($client));

            putenv('AUTH_ENABLED=false');
            AppSettings::set(self::OPEN, '0');
            self::assertFalse($auth->canManageSeerrRequests($client));
            self::assertFalse($auth->canRemoveSeerrFiles($client));
            putenv('AUTH_ENABLED=true');
            self::assertTrue($auth->canManageSeerrRequests($client));
        } finally {
            if ($id !== null) {
                $db->getDibi()->delete('users')->where('id = %i', $id)->execute();
            }
            foreach ($oldSettings as $key => $value) {
                AppSettings::set($key, $value);
            }
            $_SESSION = $oldSession;
            putenv($oldAuth === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $oldAuth);
        }
    }

    public function testSettingsReadFailureRevokesPreviouslyEnabledPermissions(): void
    {
        $original = Container::db();
        $fixture = Database::sqlite(':memory:');
        Container::set('db', $fixture);
        $oldAuth = getenv('AUTH_ENABLED');
        $oldSession = $_SESSION ?? [];
        $_SESSION = [];
        $client = new JellyseerrClient('http://127.0.0.1:1', 'fixture-token');

        try {
            putenv('AUTH_ENABLED=true');
            $fixture->ensureAuthSchema();
            $fixture->addAuthUser('seerr-owner', 'fixture-password-123', 'Fixture Owner', Authorization::ROLE_OWNER);
            $auth = new Authorization($fixture);
            self::assertTrue($auth->userLogin('seerr-owner', 'fixture-password-123'));
            self::assertTrue($auth->canManageSeerrRequests($client));
            AppSettings::set(self::OPEN, '1');
            putenv('AUTH_ENABLED=false');
            self::assertTrue($auth->canManageSeerrRequests($client));

            $fixture->getDibi()->query('DROP TABLE app_settings');
            self::assertFalse($auth->canManageSeerrRequests($client));
            putenv('AUTH_ENABLED=true');
            self::assertFalse($auth->canManageSeerrRequests($client));
            self::assertTrue($auth->can(Authorization::CAPABILITY_MANAGE_GLOBAL));
        } finally {
            $_SESSION = $oldSession;
            putenv($oldAuth === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $oldAuth);
            Container::set('db', $original);
            AppSettings::getFresh(self::AUTHENTICATED);
        }
    }
}
