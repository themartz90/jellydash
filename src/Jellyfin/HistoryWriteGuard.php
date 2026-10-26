<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Database;

/** Serializes local History evidence and replacement decisions before transactions start. */
final class HistoryWriteGuard
{
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $owners = null;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * MariaDB waits at most five seconds, then refuses the operation. Acquisition is
     * reentrant on one connection. SQLite writers use their existing transactions;
     * replacement decisions read inside BEGIN IMMEDIATE. Never wrap network calls.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation, int $waitSeconds = 5): mixed
    {
        $db = $this->database->getDibi();
        self::$owners ??= new \WeakMap();
        if (isset(self::$owners[$db])) {
            return $operation();
        }
        if ($this->database->getPlatform()->isSqlite()) {
            self::$owners[$db] = true;
            try {
                return $operation();
            } finally {
                unset(self::$owners[$db]);
            }
        }
        $name = 'jellydash:history:' . substr(hash('sha256', (string) $db->getConfig('database')), 0, 46);
        if ((int) $db->query('SELECT GET_LOCK(%s, %i)', $name, max(0, min(5, $waitSeconds)))->fetchSingle() !== 1) {
            throw new HistoryWriteBusyException('History is being updated. Please try again shortly.');
        }
        self::$owners[$db] = true;
        try {
            return $operation();
        } finally {
            unset(self::$owners[$db]);
            $db->query('SELECT RELEASE_LOCK(%s)', $name);
        }
    }

    public function isOwned(): bool
    {
        return isset(self::$owners[$this->database->getDibi()]);
    }

    /**
     * A queued sample must not wait behind a restore. SQLite reserves the write
     * before reading the active play so two collectors cannot invent two restarts.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function runSample(callable $operation): mixed
    {
        if ($this->isOwned()) {
            return $operation();
        }
        if (!$this->database->getPlatform()->isSqlite()) {
            return $this->run($operation, 0);
        }

        $db = $this->database->getDibi();
        $timeout = (int) $db->query('PRAGMA busy_timeout')->fetchSingle();
        $db->query('PRAGMA busy_timeout = 0');
        try {
            return $this->run(function () use ($db, $operation): mixed {
                $db->query('BEGIN IMMEDIATE');
                try {
                    $result = $operation();
                    $db->commit();
                    return $result;
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
            }, 0);
        } catch (\Dibi\DriverException $e) {
            if (in_array((int) $e->getCode(), [5, 6], true)) {
                throw new HistoryWriteBusyException('History is being updated. Please try again shortly.', previous: $e);
            }
            throw $e;
        } finally {
            $db->query('PRAGMA busy_timeout = %i', $timeout);
        }
    }
}
