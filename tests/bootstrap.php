<?php

declare(strict_types=1);

use Mk\Framework\Config;

/**
 * PHPUnit bootstrap.
 *
 * Define ROOT_DIR, load Composer (PSR-4 autoloads the Mk\Framework classes),
 * load the path constants, and isolate database-backed tests from configured
 * application data unless the process supplies an explicit database profile.
 */

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/vendor/autoload.php';
require_once ROOT_DIR . '/utils/@constants.php';

$processDatabaseDriver = getenv('DB_DRIVER');
$processDatabaseName = getenv('DB_NAME');
$explicitDatabase = $processDatabaseDriver !== false && trim($processDatabaseDriver) !== ''
    && $processDatabaseName !== false && trim($processDatabaseName) !== '';
if (!$explicitDatabase) {
    $temporaryDatabase = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'jellydash-phpunit-' . getmypid() . '-' . bin2hex(random_bytes(8)) . '.sqlite';
    foreach (['DB_DRIVER' => 'sqlite3', 'DB_NAME' => $temporaryDatabase] as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    putenv('JELLYDASH_TEST_DB_OWNER_PID=' . getmypid());
    $_ENV['JELLYDASH_TEST_DB_OWNER_PID'] = (string) getmypid();
    $_SERVER['JELLYDASH_TEST_DB_OWNER_PID'] = (string) getmypid();

    register_shutdown_function(static function () use ($temporaryDatabase): void {
        if ((int) getenv('JELLYDASH_TEST_DB_OWNER_PID') !== getmypid()) {
            return;
        }
        // Release the shared Dibi connection before unlinking SQLite on
        // Windows, where an open database handle prevents deletion.
        \Mk\Framework\Container::reset();
        gc_collect_cycles();
        foreach ([$temporaryDatabase, $temporaryDatabase . '-wal', $temporaryDatabase . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    });
}

$sampleRoot = getenv('JELLYDASH_TEST_SAMPLE_ROOT');
if ($sampleRoot === false || $sampleRoot === '') {
    $sampleRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-history-samples-' . getmypid() . '-' . bin2hex(random_bytes(8));
    putenv('JELLYDASH_TEST_SAMPLE_ROOT=' . $sampleRoot);
    putenv('JELLYDASH_TEST_SAMPLE_OWNER_PID=' . getmypid());
}
if (strcasecmp(dirname($sampleRoot), rtrim(sys_get_temp_dir(), '/\\')) !== 0
    || preg_match('/^jellydash-history-samples-[0-9]+-[a-f0-9]{16}$/D', basename($sampleRoot)) !== 1) {
    throw new RuntimeException('Unsafe temporary History sample directory.');
}
putenv('HISTORY_SAMPLE_QUEUE_DIR=' . $sampleRoot);
$_ENV['HISTORY_SAMPLE_QUEUE_DIR'] = $_SERVER['HISTORY_SAMPLE_QUEUE_DIR'] = $sampleRoot;
register_shutdown_function(static function () use ($sampleRoot): void {
    if ((int) getenv('JELLYDASH_TEST_SAMPLE_OWNER_PID') !== getmypid() || !is_dir($sampleRoot)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sampleRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($sampleRoot);
});

Dotenv\Dotenv::createImmutable(ROOT_DIR)->safeLoad();

// A developer's configured services must never receive traffic from tests.
foreach ([
    'JELLYFIN_URL',
    'JELLYFIN_LINK_URL',
    'JELLYFIN_API_TOKEN',
    'JELLYFIN_API_KEY',
    'JELLYSEER_URL',
    'JELLYSEER_API_TOKEN',
    'TELEGRAM_BOT_TOKEN',
    'TELEGRAM_CHAT_ID',
    'PUSHOVER_APP_TOKEN',
    'PUSHOVER_USER_KEY',
    'DISCORD_WEBHOOK_URL',
    'NTFY_URL',
    'NTFY_TOPIC',
    'NTFY_TOKEN',
    'GOTIFY_URL',
    'GOTIFY_APP_TOKEN',
    'IGNORE_USERS',
    'PUSH_IGNORE_LIBRARIES',
    'SAB_API_URL',
    'SAB_API_KEY',
] as $key) {
    putenv($key . '=');
    $_ENV[$key] = '';
    $_SERVER[$key] = '';
}

define('DATABASE_NAME', Config::get('DB_NAME', 'framework'));
define('DATABASE_HOST', Config::get('DB_HOST', 'localhost'));
define('DATABASE_PORT', Config::get('DB_PORT'));
define('DATABASE_DRIVER_DIBI', Config::get('DB_DRIVER', 'mysqli'));
define('DATABASE_USERNAME', Config::get('DB_USER', 'root'));
define('DATABASE_PASSWORD', Config::get('DB_PASS', ''));

if (!defined('PAGE')) {
    define('PAGE', null);
}

if (!defined('CATEGORY')) {
    define('CATEGORY', null);
}
