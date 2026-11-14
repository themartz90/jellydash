<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\ArtworkRepository;
use PHPUnit\Framework\TestCase;

final class ArtworkRepositoryTest extends TestCase
{
    private Database $database;
    private ArtworkRepository $repository;
    private string $scope;

    protected function setUp(): void
    {
        $this->database = Container::db();
        $this->scope = hash('sha256', random_bytes(16));
        $this->repository = new ArtworkRepository($this->database, $this->scope);
    }

    protected function tearDown(): void
    {
        if (!isset($this->repository)) {
            return;
        }
        foreach (['artwork_images', 'artwork_posters', 'artwork_items', 'artwork_sources'] as $table) {
            $this->database->getDibi()->delete($table)->where('source_key = %s', $this->scope)->execute();
        }
    }

    public function testBinaryPosterSurvivesRepositoryRecreationAndSourceOutage(): void
    {
        self::assertTrue($this->repository->anchor('server-one'));
        $this->repository->enqueue('series-one', 'Series', 'custom-a', 100);
        $job = $this->repository->jobs(100)[0];
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        $body = "\x00\xff\xfe\x80custom\x00poster";
        self::assertTrue($this->repository->publish($job, $body, 'image/png', $lease, 101));
        $other = new ArtworkRepository($this->database, $this->scope);
        self::assertSame($body, $other->image('series-one')['body']);
        self::assertSame(hash('sha256', $body), $other->image('series-one')['sha256']);
        self::assertSame(strlen($body), (int) $other->source()['used_bytes']);
        self::assertSame([], $other->jobs(102));
    }

    public function testOldDownloadAndExpiredLeaseCannotReplaceNewCustomArtwork(): void
    {
        $this->repository->anchor('server-one');
        $this->repository->enqueue('series-one', 'Series', 'a', 100);
        $old = $this->repository->jobs(100)[0];
        $lease = $this->repository->claim(100, 30);
        self::assertNotNull($lease);
        $this->repository->enqueue('series-one', 'Series', 'b', 200);
        self::assertFalse($this->repository->publish($old, 'old', 'image/png', $lease, 101));
        $new = $this->repository->jobs(101)[0];
        self::assertTrue($this->repository->publish($new, 'new', 'image/png', $lease, 102));
        $this->repository->enqueue('series-one', 'Series', 'old-tag', 150);
        self::assertSame('new', $this->repository->image('series-one')['body']);
        self::assertFalse($this->repository->publish($new, 'late', 'image/png', $lease, 130));
    }

    public function testCapacityAndClearPreserveLastGoodCopyAndPreventImmediateReacquisition(): void
    {
        $this->repository->anchor('server-one');
        $this->repository->enqueue('movie-one', 'Movie', 'a', 100);
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        self::assertTrue($this->repository->publish($this->repository->jobs(100)[0], 'good', 'image/png', $lease, 101, 5));
        $this->repository->enqueue('movie-one', 'Movie', 'b', 200);
        self::assertFalse($this->repository->publish($this->repository->jobs(101)[0], 'too-big', 'image/png', $lease, 102, 5));
        self::assertSame('good', $this->repository->image('movie-one')['body']);
        $this->repository->clear('movie-one');
        self::assertNull($this->repository->image('movie-one'));
        self::assertSame(0, (int) $this->repository->source()['used_bytes']);
        $this->repository->enqueue('movie-one', 'Movie', 'c', 300);
        self::assertSame([], $this->repository->jobs(10000));
        $this->repository->resume('movie-one');
        self::assertCount(1, $this->repository->jobs(10000));
    }

    public function testDifferentServerLatchesQuarantineAndStopsReadsAndPublication(): void
    {
        $this->repository->anchor('original');
        $this->repository->enqueue('movie-one', 'Movie', 'a', 100);
        $job = $this->repository->jobs(100)[0];
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        self::assertTrue($this->repository->publish($job, 'original', 'image/png', $lease, 101));
        self::assertFalse($this->repository->anchor('different'));
        self::assertFalse($this->repository->anchor('original'));
        self::assertNull($this->repository->image('movie-one'));
        self::assertFalse($this->repository->publish($job, 'wrong', 'image/png', $lease, 102));
        self::assertNull($this->repository->claim(200));
    }

    public function testAmbientTransactionRollbackPreservesCallerBoundary(): void
    {
        $connection = $this->database->getDibi();
        $connection->begin();
        try {
            if ($this->database->getPlatform()->isSqlite()) {
                $this->repository->anchor('server-one');
                $this->repository->enqueue('series-one', 'Series', 'a', 100);
            } else {
                try {
                    $this->repository->anchor('server-one');
                    self::fail('An ambient MariaDB transaction must be rejected before any write.');
                } catch (\LogicException) {
                    self::assertSame(1, (int) $connection->query('SELECT @@in_transaction')->fetchSingle());
                }
            }
        } finally {
            $connection->rollback();
        }
        self::assertNull($this->repository->poster('series-one'));
        self::assertSame('', $this->repository->source()['server_id']);
    }

    public function testEqualTimeConflictingTagsInvalidateDownloadUntilFreshMetadata(): void
    {
        $this->repository->anchor('server-one');
        $this->repository->enqueue('series-one', 'Series', 'a', 100);
        $job = $this->repository->jobs(100)[0];
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        $this->repository->enqueue('series-one', 'Series', 'b', 100);
        self::assertNull($this->repository->poster('series-one')['requested_tag']);
        self::assertFalse($this->repository->publish($job, 'uncertain', 'image/png', $lease, 101));
        $this->repository->enqueue('series-one', 'Series', 'fresh', 101);
        self::assertSame('fresh', $this->repository->poster('series-one')['requested_tag']);
    }

    public function testSecondConnectionLeaseTakeoverFencesExpiredOwner(): void
    {
        $this->repository->anchor('server-one');
        $this->repository->enqueue('movie-one', 'Movie', 'a', 100);
        $job = $this->repository->jobs(100)[0];
        $first = $this->repository->claim(100, 10);
        self::assertNotNull($first);
        $otherDatabase = new Database(new \Dibi\Connection($this->database->getDibi()->getConfig()));
        try {
            $other = new ArtworkRepository($otherDatabase, $this->scope);
            self::assertNull($other->claim(109));
            $second = $other->claim(110);
            self::assertNotNull($second);
            self::assertFalse($this->repository->publish($job, 'stale', 'image/png', $first, 111));
            self::assertTrue($other->publish($job, 'current', 'image/png', $second, 111));
            $this->repository->release($first);
            self::assertSame($second, $other->source()['lease_token']);
        } finally {
            $otherDatabase->getDibi()->disconnect();
        }
    }

    public function testFailureAfterImageWriteRollsBackBytesAndAccounting(): void
    {
        $this->repository->anchor('server-one');
        $this->repository->enqueue('movie-one', 'Movie', 'a', 100);
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        $this->repository->publish($this->repository->jobs(100)[0], 'good', 'image/png', $lease, 101);
        $this->repository->enqueue('movie-one', 'Movie', 'b', 200);
        $connection = $this->database->getDibi();
        $trigger = 'artwork_fail_' . substr($this->scope, 0, 16);
        $condition = $connection->translate('NEW.used_bytes <> OLD.used_bytes AND OLD.source_key = %s', $this->scope);
        $connection->query($this->database->getPlatform()->isSqlite()
            ? "CREATE TRIGGER $trigger BEFORE UPDATE ON artwork_sources WHEN $condition BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END"
            : "CREATE TRIGGER $trigger BEFORE UPDATE ON artwork_sources FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic failure'; END IF; END");
        try {
            try {
                $this->repository->publish($this->repository->jobs(102)[0], 'replacement', 'image/png', $lease, 102);
                self::fail('Publication must fail at the accounting write.');
            } catch (\Dibi\Exception $error) {
                self::assertStringContainsString('synthetic failure', $error->getMessage());
            }
            self::assertSame('good', $this->repository->image('movie-one')['body']);
            self::assertSame(4, (int) $this->repository->source()['used_bytes']);
        } finally {
            $connection->query('DROP TRIGGER %n', $trigger);
        }
    }

    public function testMaximumSizePosterCanRefreshAtCapacityWithoutLosingBytes(): void
    {
        $this->repository->anchor('server-one');
        $this->repository->enqueue('series-one', 'Series', 'a', 100);
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        $body = str_repeat("\xff\x00", ArtworkRepository::MAX_IMAGE_BYTES / 2);
        self::assertTrue($this->repository->publish($this->repository->jobs(100)[0], $body, 'image/png', $lease, 101, strlen($body)));
        $this->repository->enqueue('series-one', 'Series', 'b', 200);
        $body = str_repeat("\x80\x00", ArtworkRepository::MAX_IMAGE_BYTES / 2);
        self::assertTrue($this->repository->publish($this->repository->jobs(102)[0], $body, 'image/png', $lease, 102, strlen($body)));
        self::assertSame($body, $this->repository->image('series-one')['body']);
        self::assertSame(strlen($body), (int) $this->repository->source()['used_bytes']);
    }

    public function testAmbientMariaDbSnapshotIsRejectedWithoutCommittingCaller(): void
    {
        if ($this->database->getPlatform()->isSqlite()) {
            self::markTestSkipped('MariaDB repeatable-read snapshot behavior.');
        }
        $this->repository->anchor('server-one');
        $this->repository->enqueue('series-one', 'Series', 'a', 100);
        $job = $this->repository->jobs(100)[0];
        $lease = $this->repository->claim(100);
        self::assertNotNull($lease);
        $otherDatabase = new Database(new \Dibi\Connection($this->database->getDibi()->getConfig()));
        $other = new ArtworkRepository($otherDatabase, $this->scope);
        $connection = $this->database->getDibi();
        $connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->begin();
        try {
            self::assertSame('a', $this->repository->poster('series-one')['requested_tag']);
            $other->enqueue('series-one', 'Series', 'b', 200);
            self::assertTrue($other->publish($other->jobs(101)[0], 'new', 'image/png', $lease, 101));
            try {
                $this->repository->publish($job, 'stale', 'image/png', $lease, 102);
                self::fail('An existing MariaDB snapshot must not be used for artwork writes.');
            } catch (\LogicException) {
                self::assertSame(1, (int) $connection->query('SELECT @@in_transaction')->fetchSingle());
            }
        } finally {
            $connection->rollback();
            $otherDatabase->getDibi()->disconnect();
        }
        self::assertSame('new', $this->repository->image('series-one')['body']);
    }
}
