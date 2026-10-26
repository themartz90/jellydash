<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Database;
use Mk\Framework\DatabaseSchemaInitializer;
use PHPUnit\Framework\TestCase;

final class SQLiteConcurrencyTest extends TestCase
{
    private string $databasePath = '';
    private string $barrierPath = '';
    private Database $database;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('The sqlite3 extension is not available.');
        }

        $suffix = getmypid() . '_' . bin2hex(random_bytes(4));
        $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "jellydash_concurrency_{$suffix}.sqlite";
        $this->barrierPath = $this->databasePath . '.start';
        $this->database = Database::sqlite($this->databasePath);
        DatabaseSchemaInitializer::initialize($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }

        @unlink($this->barrierPath);
        @unlink($this->databasePath . '-shm');
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath);
    }

    public function testConcurrentWorkersClaimEveryRequestExactlyOnce(): void
    {
        $connection = $this->database->getDibi();
        $now = (new DateTimeImmutable('now', new DateTimeZone(Config::timezone())))->format('Y-m-d H:i:s');
        $connection->begin();
        for ($requestId = 1; $requestId <= 200; ++$requestId) {
            $connection->insert('seerr_requests', [
                'request_id' => $requestId,
                'media_type' => 'movie',
                'tmdb_id' => 1000 + $requestId,
                'title' => "Concurrent Movie {$requestId}",
                'request_status' => 1,
                'media_status' => 2,
                'is_4k' => 0,
                'requested_at' => $now,
                'requested_at_epoch' => time(),
                'notified' => 0,
                'created_at' => $now,
            ])->execute();
        }
        $connection->commit();

        $workers = [];
        for ($worker = 0; $worker < 4; ++$worker) {
            $workers[] = $this->startClaimWorker();
        }
        touch($this->barrierPath);

        $claimed = 0;
        foreach ($workers as $worker) {
            $result = $this->finishClaimWorker($worker);
            $this->assertSame('', $result['error']);
            $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
            $claimed += $result['claimed'];
        }

        $this->assertSame(200, $claimed);
        $this->assertSame(200, (int) $connection->select('COUNT(*)')
            ->from('seerr_requests')->where('notified = 1')->fetchSingle());
    }

    public function testConcurrentPlaybackWritersKeepOneSessionRow(): void
    {
        $workers = [];
        for ($worker = 0; $worker < 4; ++$worker) {
            $workers[] = $this->startPlaybackWorker();
        }
        touch($this->barrierPath);

        foreach ($workers as $worker) {
            $result = $this->finishClaimWorker($worker);
            $this->assertSame('', $result['error']);
            $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
            $this->assertContains($result['output'], ['ok', 'queued']);
        }

        (new \Mk\Framework\Jellyfin\PlayHistoryRepository($this->database))->logActiveStreams([]);
        $connection = $this->database->getDibi();
        $this->assertSame(1, (int) $connection->select('COUNT(*)')->from('play_history')->fetchSingle());
        $this->assertSame(30, (int) $connection->select('watched_sec')->from('play_history')->fetchSingle());
    }

    public function testConcurrentReplayWritersPreserveCompletedPlayAndCreateOneNewPlay(): void
    {
        $connection = $this->database->getDibi();
        $connection->insert('play_history', [
            'session_key' => 'shared-session', 'item_id' => 'shared-item', 'item_type' => 'Movie',
            'play_method' => 'DirectPlay', 'watched_sec' => 3500, 'watch_duration_sec' => 300,
            'runtime_sec' => 3600, 'is_finished' => 1, 'notified' => 1,
            'started_at' => '2026-08-11 11:00:00', 'started_at_epoch' => (new DateTimeImmutable('2026-08-11 11:00:00'))->getTimestamp(),
            'updated_at' => '2026-08-11 11:59:00', 'last_sample_epoch' => (new DateTimeImmutable('2026-08-11 11:59:00'))->getTimestamp(),
        ])->execute();
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->startPlaybackWorker();
        }
        touch($this->barrierPath);
        foreach ($workers as $worker) {
            $result = $this->finishClaimWorker($worker);
            $this->assertSame(0, $result['exitCode'], $result['error']);
            $this->assertContains($result['output'], ['ok', 'queued']);
        }
        (new \Mk\Framework\Jellyfin\PlayHistoryRepository($this->database))->logActiveStreams([]);
        $rows = $connection->select('*')->from('play_history')->orderBy('started_at')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame('shared-session', $rows[0]['session_key']);
        $this->assertSame(300, (int) $rows[0]['watch_duration_sec']);
        $this->assertSame(1, (int) $rows[0]['is_finished']);
        $this->assertSame(30, (int) $rows[1]['watched_sec']);
        $this->assertSame(0, (int) $rows[1]['watch_duration_sec']);
        $this->assertSame(0, (int) $rows[1]['notified']);
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function startClaimWorker(): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_DRIVER' => 'sqlite3',
            'DB_NAME' => $this->databasePath,
            'TEST_CLAIM_BARRIER' => $this->barrierPath,
        ]);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/seerr-claim-worker.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function startPlaybackWorker(): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_DRIVER' => 'sqlite3',
            'DB_NAME' => $this->databasePath,
            'TEST_PLAYBACK_BARRIER' => $this->barrierPath,
        ]);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/fixtures/play-history-worker.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>} $worker
     * @return array{claimed: int, output: string, error: string, exitCode: int}
     */
    private function finishClaimWorker(array $worker): array
    {
        $output = trim((string) stream_get_contents($worker['pipes'][1]));
        $error = trim((string) stream_get_contents($worker['pipes'][2]));
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);

        return [
            'claimed' => (int) $output,
            'output' => $output,
            'error' => $error,
            'exitCode' => proc_close($worker['process']),
        ];
    }
}
