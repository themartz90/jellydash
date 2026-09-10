<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Mk\Framework\Authorization;
use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

final class PushSubscriptionRepository
{
    private const DEFAULT_INSTALLATION_LIMIT = 100;
    private const DEFAULT_ACCOUNT_LIMIT = 10;
    private const MAX_INSTALLATION_LIMIT = 10_000;
    private const MAX_ACCOUNT_LIMIT = 1_000;

    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    private int $installationLimit;
    private int $accountLimit;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public function __construct(?Database $database = null, ?int $installationLimit = null, ?int $accountLimit = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->installationLimit = $installationLimit ?? self::configuredLimit('PUSH_MAX_SUBSCRIPTIONS', self::DEFAULT_INSTALLATION_LIMIT, self::MAX_INSTALLATION_LIMIT);
        $this->accountLimit = $accountLimit ?? self::configuredLimit('PUSH_MAX_SUBSCRIPTIONS_PER_ACCOUNT', self::DEFAULT_ACCOUNT_LIMIT, self::MAX_ACCOUNT_LIMIT);
        if ($this->installationLimit < 1 || $this->installationLimit > self::MAX_INSTALLATION_LIMIT) {
            throw new \InvalidArgumentException('The Web Push installation limit is out of range.');
        }
        if ($this->accountLimit < 1 || $this->accountLimit > self::MAX_ACCOUNT_LIMIT) {
            throw new \InvalidArgumentException('The Web Push account limit is out of range.');
        }
        $this->ensureSchema();
    }

    public function save(
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $userAgent,
        ?string $deviceCapabilityHash = null,
        ?int $userId = null,
        bool $authEnabled = false,
    ): void {
        if (!PushSubscriptionValidator::isValid($endpoint, $p256dh, $auth)) {
            throw new \InvalidArgumentException('Invalid Web Push subscription.');
        }
        if ($deviceCapabilityHash !== null && !self::isValidCapabilityHash($deviceCapabilityHash)) {
            throw new \InvalidArgumentException('Invalid push device capability hash.');
        }
        if ($authEnabled && $deviceCapabilityHash === null) {
            throw new PushSubscriptionOwnershipException('A device capability is required for authenticated enrollment.');
        }
        if ($authEnabled && !$this->isEligibleAccount($userId)) {
            throw new PushSubscriptionOwnershipException('The current account cannot register notification devices.');
        }

        $hash = hash('sha256', $endpoint);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $data = [
            'endpoint' => $endpoint,
            'endpoint_hash' => $hash,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'user_agent' => $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'device_capability_hash' => $deviceCapabilityHash,
            'failure_count' => 0,
        ];

        $this->withInstallationLock(function () use ($hash, $data, $now, $p256dh, $auth, $deviceCapabilityHash, $userId, $authEnabled): void {
            $existing = $this->db->select('id, user_id, device_capability_hash, p256dh, auth')
                ->from('push_subscriptions')->where('endpoint_hash = %s', $hash)->fetch();
            if ($existing) {
                if ($authEnabled) {
                    $existingUserId = $existing['user_id'] !== null ? (int) $existing['user_id'] : null;
                    if ($existingUserId !== null && $existingUserId !== $userId) {
                        throw new PushSubscriptionOwnershipException('This notification device belongs to another account.');
                    }
                    $storedCapability = (string) ($existing['device_capability_hash'] ?? '');
                    $capabilityMatches = self::isValidCapabilityHash($storedCapability)
                        && hash_equals($storedCapability, $deviceCapabilityHash);
                    $keysMatch = hash_equals((string) $existing['p256dh'], $p256dh)
                        && hash_equals((string) $existing['auth'], $auth);
                    if (!$capabilityMatches && !$keysMatch) {
                        throw new PushSubscriptionOwnershipException('Notification device possession could not be verified.');
                    }
                    if ($existingUserId === null && $userId !== null && $this->countForUser($userId) >= $this->accountLimit) {
                        throw new PushSubscriptionLimitExceeded('The Web Push account limit has been reached.');
                    }
                    $data['user_id'] = $userId;
                }
                $this->db->update('push_subscriptions', $data)->where('endpoint_hash = %s', $hash)->execute();

                return;
            }

            if ($this->count() >= $this->installationLimit) {
                throw new PushSubscriptionLimitExceeded('The Web Push installation limit has been reached.');
            }
            if ($authEnabled && $userId !== null && $this->countForUser($userId) >= $this->accountLimit) {
                throw new PushSubscriptionLimitExceeded('The Web Push account limit has been reached.');
            }

            $insert = $data;
            $insert['user_id'] = $authEnabled ? $userId : null;
            $insert['created_at'] = $now;
            $this->db->insert('push_subscriptions', $insert)->execute();
        });
    }

    /** @return list<array{endpoint: string, p256dh: string, auth: string}> */
    public function deliverySubscriptions(bool $authEnabled): array
    {
        $rows = $this->db->select('endpoint, p256dh, auth, user_id')->from('push_subscriptions')->fetchAll();
        $eligibleUserIds = $authEnabled ? $this->eligibleUserIds() : [];
        $subscriptions = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
            if ($authEnabled && ($userId === null || !isset($eligibleUserIds[$userId]))) {
                continue;
            }
            $subscriptions[] = [
                'endpoint' => (string) $row['endpoint'],
                'p256dh' => (string) $row['p256dh'],
                'auth' => (string) $row['auth'],
            ];
        }

        return $subscriptions;
    }

    /** @return array{endpoint: string, p256dh: string, auth: string}|null */
    public function currentSubscription(string $capabilityHash, ?int $userId, bool $authEnabled): ?array
    {
        if (!self::isValidCapabilityHash($capabilityHash) || ($authEnabled && !$this->isEligibleAccount($userId))) {
            return null;
        }
        $selection = $this->db->select('endpoint, p256dh, auth')->from('push_subscriptions')
            ->where('device_capability_hash = %s', $capabilityHash);
        if ($authEnabled) {
            $selection->where('user_id = %i', $userId);
        }
        $row = $selection->orderBy('id DESC')->limit(1)->fetch();

        return $row ? [
            'endpoint' => (string) $row['endpoint'],
            'p256dh' => (string) $row['p256dh'],
            'auth' => (string) $row['auth'],
        ] : null;
    }

    /**
     * @return list<array{id: int, label: string, owner: ?string, state: string, current: bool, created_at: string, last_success_at: ?string}>
     */
    public function devices(?int $userId, bool $canManageAll, bool $authEnabled, ?string $currentCapabilityHash): array
    {
        $selection = $this->db->select('id, endpoint, p256dh, auth, user_agent, user_id, device_capability_hash, created_at, last_success_at')
            ->from('push_subscriptions');
        if ($authEnabled && !$canManageAll) {
            if ($userId === null) {
                return [];
            }
            $selection->where('user_id = %i', $userId);
        }
        $rows = $selection->orderBy('id DESC')->fetchAll();
        $owners = $this->usersById();
        $devices = [];
        foreach ($rows as $row) {
            $ownerId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
            $owner = $ownerId !== null ? ($owners[$ownerId] ?? null) : null;
            $state = 'active';
            if (!PushSubscriptionValidator::isValid((string) $row['endpoint'], (string) $row['p256dh'], (string) $row['auth'])) {
                $state = 'ineligible';
            } elseif ($authEnabled && $ownerId === null) {
                $state = 'needs_reenrollment';
            } elseif ($authEnabled && ($owner === null || !Authorization::isValidRole($owner['role']) || $owner['role'] > Authorization::ROLE_USER)) {
                $state = 'ineligible';
            }
            $storedCapability = (string) ($row['device_capability_hash'] ?? '');
            $devices[] = [
                'id' => (int) $row['id'],
                'label' => self::deviceLabel($row['user_agent'] !== null ? (string) $row['user_agent'] : null),
                'owner' => $canManageAll && $owner !== null ? $owner['name'] : null,
                'state' => $state,
                'current' => $currentCapabilityHash !== null && self::isValidCapabilityHash($storedCapability) && hash_equals($storedCapability, $currentCapabilityHash),
                'created_at' => (string) $row['created_at'],
                'last_success_at' => $row['last_success_at'] !== null ? (string) $row['last_success_at'] : null,
            ];
        }

        return $devices;
    }

    public function revokeCurrent(string $capabilityHash, ?int $userId, bool $authEnabled): int
    {
        if (!self::isValidCapabilityHash($capabilityHash) || ($authEnabled && $userId === null)) {
            return 0;
        }
        $delete = $this->db->delete('push_subscriptions')->where('device_capability_hash = %s', $capabilityHash);
        if ($authEnabled) {
            $delete->where('user_id = %i', $userId);
        }

        $delete->execute();

        return $this->db->getAffectedRows();
    }

    public function revokeCurrentEndpoint(string $endpoint, string $capabilityHash, ?int $userId, bool $authEnabled): bool
    {
        if (!self::isValidCapabilityHash($capabilityHash) || ($authEnabled && $userId === null)) {
            return false;
        }
        $delete = $this->db->delete('push_subscriptions')
            ->where('endpoint_hash = %s', hash('sha256', $endpoint))
            ->where('device_capability_hash = %s', $capabilityHash);
        if ($authEnabled) {
            $delete->where('user_id = %i', $userId);
        }

        $delete->execute();

        return $this->db->getAffectedRows() > 0;
    }

    public function revokeById(int $id, ?int $userId, bool $canManageAll, bool $authEnabled): bool
    {
        if ($id < 1 || ($authEnabled && !$canManageAll && $userId === null)) {
            return false;
        }
        $delete = $this->db->delete('push_subscriptions')->where('id = %i', $id);
        if ($authEnabled && !$canManageAll) {
            $delete->where('user_id = %i', $userId);
        }

        $delete->execute();

        return $this->db->getAffectedRows() > 0;
    }

    public function delete(string $endpoint): void
    {
        $this->db->delete('push_subscriptions')->where('endpoint_hash = %s', hash('sha256', $endpoint))->execute();
    }

    /** @return list<array{endpoint: string, p256dh: string, auth: string}> */
    public function all(): array
    {
        return $this->deliverySubscriptions(false);
    }

    public function count(): int
    {
        return (int) $this->db->select('COUNT(*)')->from('push_subscriptions')->fetchSingle();
    }

    public function markSuccess(string $endpoint): void
    {
        $this->db->update('push_subscriptions', [
            'failure_count' => 0,
            'last_success_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ])->where('endpoint_hash = %s', hash('sha256', $endpoint))->execute();
    }

    private function withInstallationLock(callable $save): void
    {
        if ($this->platform->isSqlite()) {
            $this->db->query('BEGIN IMMEDIATE');
            try {
                $save();
                $this->db->query('COMMIT');
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');
                throw $e;
            }

            return;
        }
        $lockName = 'jellydash_push_cap_' . substr(hash('sha256', (string) $this->db->getConfig('database')), 0, 32);
        $acquired = (int) $this->db->query('SELECT GET_LOCK(%s, %i)', $lockName, 5)->fetchSingle();
        if ($acquired !== 1) {
            throw new \RuntimeException('Could not reserve the Web Push installation limit.');
        }
        try {
            $save();
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(%s)', $lockName);
        }
    }

    private static function configuredLimit(string $key, int $default, int $max): int
    {
        $configured = Config::get($key);
        if ($configured === null || preg_match('/^[1-9][0-9]*$/', $configured) !== 1) {
            return $default;
        }
        $limit = (int) $configured;

        return $limit <= $max ? $limit : $default;
    }

    private function countForUser(int $userId): int
    {
        return (int) $this->db->select('COUNT(*)')->from('push_subscriptions')->where('user_id = %i', $userId)->fetchSingle();
    }

    private function isEligibleAccount(?int $userId): bool
    {
        if ($userId === null || $userId < 1) {
            return false;
        }
        $role = $this->db->select('role')->from('users')->where('id = %i', $userId)->fetchSingle();

        return $role !== false && Authorization::isValidRole((int) $role) && (int) $role <= Authorization::ROLE_USER;
    }

    /** @return array<int, true> */
    private function eligibleUserIds(): array
    {
        $ids = [];
        foreach ($this->db->select('id, role')->from('users')->fetchAll() as $user) {
            $role = (int) $user['role'];
            if (Authorization::isValidRole($role) && $role <= Authorization::ROLE_USER) {
                $ids[(int) $user['id']] = true;
            }
        }

        return $ids;
    }

    /** @return array<int, array{name: string, role: int}> */
    private function usersById(): array
    {
        $users = [];
        foreach ($this->db->select('id, name, role')->from('users')->fetchAll() as $user) {
            $users[(int) $user['id']] = ['name' => (string) $user['name'], 'role' => (int) $user['role']];
        }

        return $users;
    }

    private static function isValidCapabilityHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }

    private static function deviceLabel(?string $userAgent): string
    {
        $userAgent ??= '';
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser notification device',
        };
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `endpoint` text NOT NULL,
                `endpoint_hash` char(64) NOT NULL,
                `p256dh` varchar(255) NOT NULL,
                `auth` varchar(255) NOT NULL,
                `user_agent` varchar(255) DEFAULT NULL,
                `device_capability_hash` char(64) DEFAULT NULL,
                `user_id` mediumint(9) DEFAULT NULL,
                `failure_count` int NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL,
                `last_success_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_endpoint_hash` (`endpoint_hash`),
                KEY `idx_push_subscription_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `endpoint` TEXT NOT NULL,
                `endpoint_hash` TEXT NOT NULL,
                `p256dh` TEXT NOT NULL,
                `auth` TEXT NOT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `device_capability_hash` TEXT DEFAULT NULL,
                `user_id` INTEGER DEFAULT NULL,
                `failure_count` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT NOT NULL,
                `last_success_at` TEXT DEFAULT NULL,
                UNIQUE (`endpoint_hash`)
            )',
        );
        foreach ([
            'device_capability_hash' => ['`device_capability_hash` char(64) DEFAULT NULL', '`device_capability_hash` TEXT DEFAULT NULL'],
            'user_id' => ['`user_id` mediumint(9) DEFAULT NULL', '`user_id` INTEGER DEFAULT NULL'],
        ] as $column => [$mariaDb, $sqlite]) {
            if (!$this->platform->columnExists('push_subscriptions', $column)) {
                try {
                    $this->platform->addColumn('push_subscriptions', $mariaDb, $sqlite);
                } catch (\Dibi\Exception $e) {
                    if (!$this->platform->columnExists('push_subscriptions', $column)) {
                        throw $e;
                    }
                }
            }
        }
        $this->platform->createIndexIfMissing('idx_push_subscription_user', 'push_subscriptions', ['user_id']);
        self::$schemaConnections[$this->db] = true;
    }
}
