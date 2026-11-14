<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Jellyfin\ArtworkRepository;
use PHPUnit\Framework\TestCase;

final class ArtworkExpiryCommandTest extends TestCase
{
    public function testRealConsoleExpiryCommandRemovesOldScopeMetadataWithPollingDisabled(): void
    {
        $directory = sys_get_temp_dir() . '/jellydash-expiry-command-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        mkdir($directory . '/utils', 0700, true);
        mkdir($directory . '/vendor', 0700, true);
        $databasePath = $directory . '/isolated.sqlite';
        $database = Database::sqlite($databasePath);
        try {
            // Copy the real command unchanged, with isolated paths for its normal bootstrap.
            copy(ROOT_DIR . '/bin/console.php', $directory . '/bin/console.php');
            copy(ROOT_DIR . '/utils/@constants.php', $directory . '/utils/@constants.php');
            file_put_contents($directory . '/vendor/autoload.php', '<?php require ' . var_export(ROOT_DIR . '/vendor/autoload.php', true) . ';');
            $art = new ArtworkRepository($database, hash('sha256', 'isolated-expired-source'));
            $art->anchor('isolated-source');
            $database->getDibi()->insert('artwork_external_searches', ['source_key' => $art->scope, 'query_key' => hash('sha256', 'expired-query'),
                'server_id' => 'isolated-source', 'config_revision' => 1, 'kind' => 'Series', 'query_title' => '期限切れ',
                'acquired_at' => 1, 'expires_at' => 2, 'candidates_json' => '[]'])->execute();
            $environment = getenv();
            foreach (['DB_DRIVER' => 'sqlite3', 'DB_NAME' => $databasePath, 'AUTH_ENABLED' => 'false', 'POLLER_ENABLED' => 'false',
                'JELLYFIN_URL' => '', 'JELLYFIN_API_TOKEN' => '', 'JELLYFIN_API_KEY' => ''] as $key => $value) {
                $environment[$key] = $value;
            }
            $process = proc_open([PHP_BINARY, $directory . '/bin/console.php', 'artwork:expire'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertStringContainsString('Expired external poster records: 1', $output);
            self::assertSame(0, (int) $database->getDibi()->select('COUNT(*)')->from('artwork_external_searches')->fetchSingle());
        } finally {
            $database->getDibi()->disconnect();
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($directory);
        }
    }
}
