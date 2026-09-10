<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Push\PushSubscriptionLimitExceeded;
use Mk\Framework\Push\PushSubscriptionOwnershipException;
use Mk\Framework\Push\PushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionRepositoryTest extends TestCase
{
    private Database $database;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';
    private string $sqlitePath = '';

    protected function setUp(): void
    {
        $this->database = $this->isolatedDatabase();
        $this->resetSchemaState();
    }

    protected function tearDown(): void
    {
        $this->resetSchemaState();
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        foreach ([$this->sqlitePath, $this->sqlitePath . '-shm', $this->sqlitePath . '-wal'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^jellydash_phpunit_push_[a-z0-9_]+$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
    }

    public function testInstallationLimitAllowsRefreshButRejectsANewEndpoint(): void
    {
        $repository = new PushSubscriptionRepository($this->database, 1);
        $endpoint = $this->endpoint('first');
        $capabilityHash = hash('sha256', 'device-capability');
        $repository->save($endpoint, $this->encodedBytes(65, 'a'), $this->encodedBytes(16, 'b'), 'First agent', $capabilityHash);
        $repository->save($endpoint, $this->encodedBytes(65, 'c'), $this->encodedBytes(16, 'd'), 'Updated agent', $capabilityHash);

        try {
            $repository->save($this->endpoint('second'), $this->encodedBytes(65, 'e'), $this->encodedBytes(16, 'f'), null, hash('sha256', 'second-device'));
            $this->fail('A new endpoint must be rejected when the installation cap is full.');
        } catch (PushSubscriptionLimitExceeded) {
            $this->assertSame(1, $repository->count());
        }

        $row = $this->database->getDibi()->select('p256dh, auth, device_capability_hash')
            ->from('push_subscriptions')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame($this->encodedBytes(65, 'c'), (string) $row['p256dh']);
        $this->assertSame($this->encodedBytes(16, 'd'), (string) $row['auth']);
        $this->assertSame($capabilityHash, (string) $row['device_capability_hash']);
        $this->assertStringNotContainsString('device-capability', (string) $row['device_capability_hash']);
    }

    public function testConcurrentEnrollmentCannotExceedInstallationLimit(): void
    {
        $repository = new PushSubscriptionRepository($this->database, 1);
        $startFile = tempnam(sys_get_temp_dir(), 'jellydash-push-start-');
        $this->assertNotFalse($startFile);
        unlink($startFile);

        $workers = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $workers[] = $this->startWorker($startFile, $this->endpoint('concurrent-' . $index));
            }
            touch($startFile);
            $results = array_map($this->finishWorker(...), $workers);

            sort($results);
            $this->assertSame(['limited', 'saved'], $results);
            $this->assertSame(1, $repository->count());
        } finally {
            @unlink($startFile);
        }
    }

    public function testExistingSchemaGainsCapabilityHashWithoutLosingLegacyRow(): void
    {
        $this->database->getPlatform()->createTable(
            'CREATE TABLE `push_subscriptions` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `endpoint` text NOT NULL,
                `endpoint_hash` char(64) NOT NULL,
                `p256dh` varchar(255) NOT NULL,
                `auth` varchar(255) NOT NULL,
                `user_agent` varchar(255) DEFAULT NULL,
                `failure_count` int NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL,
                `last_success_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_endpoint_hash` (`endpoint_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE `push_subscriptions` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `endpoint` TEXT NOT NULL,
                `endpoint_hash` TEXT NOT NULL,
                `p256dh` TEXT NOT NULL,
                `auth` TEXT NOT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `failure_count` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT NOT NULL,
                `last_success_at` TEXT DEFAULT NULL,
                UNIQUE (`endpoint_hash`)
            )',
        );
        $endpoint = 'https://attacker.invalid/legacy-row';
        $this->database->getDibi()->insert('push_subscriptions', [
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => 'legacy-key',
            'auth' => 'legacy-auth',
            'failure_count' => 0,
            'created_at' => '2026-09-10 12:00:00',
        ])->execute();

        new PushSubscriptionRepository($this->database, 1);

        $this->assertTrue($this->database->getPlatform()->columnExists('push_subscriptions', 'device_capability_hash'));
        $this->assertTrue($this->database->getPlatform()->columnExists('push_subscriptions', 'user_id'));
        $row = $this->database->getDibi()->select('endpoint, p256dh, auth, device_capability_hash, user_id')
            ->from('push_subscriptions')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame($endpoint, (string) $row['endpoint']);
        $this->assertSame('legacy-key', (string) $row['p256dh']);
        $this->assertSame('legacy-auth', (string) $row['auth']);
        $this->assertNull($row['device_capability_hash']);
        $this->assertNull($row['user_id']);
    }

    public function testAuthenticatedEnrollmentEnforcesAccountOwnership(): void
    {
        $this->database->ensureAuthSchema();
        $firstUser = $this->database->addAuthUser('push-owner-one', 'password-123', 'First User', Authorization::ROLE_USER);
        $secondUser = $this->database->addAuthUser('push-owner-two', 'password-123', 'Second User', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->database);
        $endpoint = $this->endpoint('owned-device');
        $key = $this->encodedBytes(65, 'a');
        $secret = $this->encodedBytes(16, 'b');
        $capability = hash('sha256', 'first-device');

        $repository->save($endpoint, $key, $secret, 'Firefox/130', $capability, $firstUser, true);

        $this->expectException(PushSubscriptionOwnershipException::class);
        try {
            $repository->save($endpoint, $key, $secret, 'Firefox/130', $capability, $secondUser, true);
        } finally {
            $owner = $this->database->getDibi()->select('user_id')->from('push_subscriptions')->fetchSingle();
            $this->assertSame($firstUser, (int) $owner);
        }
    }

    public function testLegacyDeviceRequiresProofBeforeItIsAssignedToAnAccount(): void
    {
        $this->database->ensureAuthSchema();
        $userId = $this->database->addAuthUser('push-legacy-owner', 'password-123', 'Legacy Owner', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->database);
        $endpoint = $this->endpoint('legacy-device');
        $key = $this->encodedBytes(65, 'c');
        $secret = $this->encodedBytes(16, 'd');

        $repository->save($endpoint, $key, $secret, 'Chrome/130');
        $repository->save($endpoint, $key, $secret, 'Chrome/130', hash('sha256', 'new-capability'), $userId, true);
        $this->assertSame(
            $userId,
            (int) $this->database->getDibi()->select('user_id')->from('push_subscriptions')->fetchSingle(),
        );

        $this->expectException(PushSubscriptionOwnershipException::class);
        $repository->save(
            $this->endpoint('unproven-device'),
            $this->encodedBytes(65, 'e'),
            $this->encodedBytes(16, 'f'),
            null,
        );
        $repository->save(
            $this->endpoint('unproven-device'),
            $this->encodedBytes(65, 'g'),
            $this->encodedBytes(16, 'h'),
            null,
            hash('sha256', 'unproven-capability'),
            $userId,
            true,
        );
    }

    public function testLegacyDeviceClaimIsRejectedWhenAccountLimitIsFull(): void
    {
        $this->database->ensureAuthSchema();
        $userId = $this->database->addAuthUser('push-legacy-cap-full', 'password-123', 'Legacy Cap Full', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->database, 100, 1);
        $repository->save(
            $this->endpoint('owned-at-cap'),
            $this->encodedBytes(65, 'i'),
            $this->encodedBytes(16, 'j'),
            null,
            hash('sha256', 'owned-at-cap'),
            $userId,
            true,
        );
        $legacyEndpoint = $this->endpoint('legacy-at-cap');
        $legacyKey = $this->encodedBytes(65, 'k');
        $legacySecret = $this->encodedBytes(16, 'l');
        $repository->save($legacyEndpoint, $legacyKey, $legacySecret, 'Chrome/130');

        try {
            $repository->save(
                $legacyEndpoint,
                $legacyKey,
                $legacySecret,
                'Chrome/130',
                hash('sha256', 'legacy-at-cap'),
                $userId,
                true,
            );
            $this->fail('A legacy device claim must be rejected when the account cap is full.');
        } catch (PushSubscriptionLimitExceeded) {
            $row = $this->database->getDibi()->select('user_id, device_capability_hash')
                ->from('push_subscriptions')->where('endpoint_hash = %s', hash('sha256', $legacyEndpoint))->fetch();
            $this->assertNotFalse($row);
            $this->assertNull($row['user_id']);
            $this->assertNull($row['device_capability_hash']);
        }
    }

    public function testLegacyDeviceClaimIsAllowedBelowAccountLimit(): void
    {
        $this->database->ensureAuthSchema();
        $userId = $this->database->addAuthUser('push-legacy-cap-open', 'password-123', 'Legacy Cap Open', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->database, 100, 2);
        $repository->save(
            $this->endpoint('owned-below-cap'),
            $this->encodedBytes(65, 'm'),
            $this->encodedBytes(16, 'n'),
            null,
            hash('sha256', 'owned-below-cap'),
            $userId,
            true,
        );
        $legacyEndpoint = $this->endpoint('legacy-below-cap');
        $legacyKey = $this->encodedBytes(65, 'o');
        $legacySecret = $this->encodedBytes(16, 'p');
        $legacyCapability = hash('sha256', 'legacy-below-cap');
        $repository->save($legacyEndpoint, $legacyKey, $legacySecret, 'Firefox/130');

        $repository->save(
            $legacyEndpoint,
            $legacyKey,
            $legacySecret,
            'Firefox/130',
            $legacyCapability,
            $userId,
            true,
        );
        $refreshedKey = $this->encodedBytes(65, 'q');
        $refreshedSecret = $this->encodedBytes(16, 'r');
        $repository->save(
            $legacyEndpoint,
            $refreshedKey,
            $refreshedSecret,
            'Firefox/131',
            $legacyCapability,
            $userId,
            true,
        );

        $row = $this->database->getDibi()->select('user_id, device_capability_hash, p256dh, auth')
            ->from('push_subscriptions')->where('endpoint_hash = %s', hash('sha256', $legacyEndpoint))->fetch();
        $this->assertNotFalse($row);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame($legacyCapability, (string) $row['device_capability_hash']);
        $this->assertSame($refreshedKey, (string) $row['p256dh']);
        $this->assertSame($refreshedSecret, (string) $row['auth']);
    }

    public function testAuthenticationFiltersLegacyGuestAndDeletedAccountDevices(): void
    {
        $this->database->ensureAuthSchema();
        $activeUser = $this->database->addAuthUser('push-active-user', 'password-123', 'Active User', Authorization::ROLE_USER);
        $guest = $this->database->addAuthUser('push-guest-user', 'password-123', 'Guest User', Authorization::ROLE_GUEST);
        $deletedUser = $this->database->addAuthUser('push-deleted-user', 'password-123', 'Deleted User', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->database);

        $repository->save($this->endpoint('legacy'), $this->encodedBytes(65, 'i'), $this->encodedBytes(16, 'j'), null);
        $repository->save($this->endpoint('active'), $this->encodedBytes(65, 'k'), $this->encodedBytes(16, 'l'), null, hash('sha256', 'active'), $activeUser, true);
        $this->insertOwnedSubscription('guest', $guest, 'm', 'n');
        $this->insertOwnedSubscription('deleted', $deletedUser, 'o', 'p');
        $this->database->getDibi()->delete('users')->where('id = %i', $deletedUser)->execute();

        $this->assertCount(4, $repository->deliverySubscriptions(false));
        $eligible = $repository->deliverySubscriptions(true);
        $this->assertCount(1, $eligible);
        $this->assertSame($this->endpoint('active'), $eligible[0]['endpoint']);
        $device = $repository->devices($activeUser, false, true, hash('sha256', 'active'))[0];
        $this->assertSame(
            ['id', 'label', 'owner', 'state', 'current', 'created_at', 'last_success_at'],
            array_keys($device),
        );
        $this->assertNull($device['owner']);
        $this->assertTrue($device['current']);
    }

    public function testAccountLimitAndOwnedRevocationAreServerEnforced(): void
    {
        $this->database->ensureAuthSchema();
        $firstUser = $this->database->addAuthUser('push-limit-one', 'password-123', 'Limit One', Authorization::ROLE_USER);
        $secondUser = $this->database->addAuthUser('push-limit-two', 'password-123', 'Limit Two', Authorization::ROLE_USER);
        $repository = new PushSubscriptionRepository($this->database, 100, 1);
        $firstCapability = hash('sha256', 'limit-first');
        $repository->save($this->endpoint('limit-first'), $this->encodedBytes(65, 'q'), $this->encodedBytes(16, 'r'), null, $firstCapability, $firstUser, true);
        $repository->save($this->endpoint('limit-other'), $this->encodedBytes(65, 's'), $this->encodedBytes(16, 't'), null, hash('sha256', 'limit-other'), $secondUser, true);

        try {
            $repository->save($this->endpoint('limit-second'), $this->encodedBytes(65, 'u'), $this->encodedBytes(16, 'v'), null, hash('sha256', 'limit-second'), $firstUser, true);
            $this->fail('A second device must be rejected when the account cap is full.');
        } catch (PushSubscriptionLimitExceeded) {
            $this->assertSame(2, $repository->count());
        }

        $otherId = (int) $this->database->getDibi()->select('id')->from('push_subscriptions')
            ->where('user_id = %i', $secondUser)->fetchSingle();
        $this->assertFalse($repository->revokeById($otherId, $firstUser, false, true));
        $this->assertTrue($repository->revokeById($otherId, $firstUser, true, true));
        $this->assertSame(1, $repository->revokeCurrent($firstCapability, $firstUser, true));
        $this->assertSame(0, $repository->count());
    }

    private function endpoint(string $token): string
    {
        return 'https://updates.push.services.mozilla.com/wpush/v2/' . $token;
    }

    private function encodedBytes(int $length, string $byte): string
    {
        return rtrim(strtr(base64_encode(str_repeat($byte, $length)), '+/', '-_'), '=');
    }

    private function insertOwnedSubscription(string $token, int $userId, string $keyByte, string $authByte): void
    {
        $endpoint = $this->endpoint($token);
        $this->database->getDibi()->insert('push_subscriptions', [
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => $this->encodedBytes(65, $keyByte),
            'auth' => $this->encodedBytes(16, $authByte),
            'user_id' => $userId,
            'device_capability_hash' => hash('sha256', $token),
            'failure_count' => 0,
            'created_at' => '2026-09-10 12:00:00',
        ])->execute();
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function startWorker(string $startFile, string $endpoint): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment = array_replace($environment, [
            'DB_DRIVER' => (string) $this->database->getDibi()->getConfig('driver'),
            'DB_NAME' => (string) $this->database->getDibi()->getConfig('database'),
            'DB_HOST' => (string) $this->database->getDibi()->getConfig('host'),
            'DB_PORT' => (string) $this->database->getDibi()->getConfig('port'),
            'DB_USER' => (string) $this->database->getDibi()->getConfig('username'),
            'DB_PASS' => (string) $this->database->getDibi()->getConfig('password'),
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/push-subscription-worker.php', $startFile, $endpoint],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process: resource, pipes: array<int, resource>} $worker */
    private function finishWorker(array $worker): string
    {
        $output = stream_get_contents($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $this->assertSame(0, $exitCode, trim((string) $error));

        return trim((string) $output);
    }

    private function isolatedDatabase(): Database
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $path = tempnam(sys_get_temp_dir(), 'jellydash-push-');
            $this->assertNotFalse($path);
            $this->sqlitePath = $path;

            return Database::sqlite($path);
        }

        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
        ];
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }
        $this->admin = new \Dibi\Connection($config);
        $this->databaseName = 'jellydash_phpunit_push_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
        $config['database'] = $this->databaseName;

        return new Database(new \Dibi\Connection($config));
    }

    private function resetSchemaState(): void
    {
        (new ReflectionClass(PushSubscriptionRepository::class))
            ->getProperty('schemaConnections')->setValue(null, null);
    }
}
