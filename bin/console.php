#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Mk\Framework;

use Dotenv\Dotenv;

/**
 * Command-line console: manage the app without the web server.
 *
 * Run from a project root:  php bin/console.php <command> [args]
 * It reads that project's .env, so it targets whatever DB_NAME points at.
 */

// --- Bootstrap (the non-web half of public/index.php) ---
define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';
require ROOT_DIR . '/utils/@constants.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
define('DATABASE_NAME', Config::get('DB_NAME', 'framework'));
define('DATABASE_HOST', Config::get('DB_HOST', 'localhost'));
define('DATABASE_PORT', Config::get('DB_PORT'));
define('DATABASE_DRIVER_DIBI', Config::get('DB_DRIVER', 'mysqli'));
define('DATABASE_USERNAME', Config::get('DB_USER', 'root'));
define('DATABASE_PASSWORD', Config::get('DB_PASS', ''));

// Match the web app's timezone so CLI-written timestamps line up with the UI.
date_default_timezone_set(Config::timezone());

$command = $argv[1] ?? '';

try {
    Config::bool('AUTH_ENABLED', false);
    $db = Container::db();

    switch ($command) {
        case 'user:add':
            $db->ensureAuthSchema();
            $username = isset($argv[2]) ? trim(strtolower($argv[2])) : null;
            $password = $argv[3] ?? null;
            $name = $argv[4] ?? null;
            $role = (int) ($argv[5] ?? Authorization::ROLE_USER);

            if (!$username || !$password || !$name) {
                exit("Usage: php bin/console.php user:add <username> <password> <name> [role 1-4]\n");
            }

            $id = $db->addAuthUser($username, $password, $name, $role);
            echo "Created user #{$id}: {$username} (role {$role})\n";
            break;

        case 'user:ensure':
            // Idempotent admin seeding (used by the container entrypoint when
            // AUTH_ADMIN_USER/AUTH_ADMIN_PASSWORD are set): creates the user if
            // missing, never touches an existing one, so a password changed in
            // the app isn't reset on every container restart.
            $useEnvironment = !isset($argv[2]) && !isset($argv[3]);
            $rawUsername = $useEnvironment ? Config::get('AUTH_ADMIN_USER') : ($argv[2] ?? null);
            $username = $rawUsername !== null ? trim(strtolower($rawUsername)) : null;
            $password = $useEnvironment ? Config::get('AUTH_ADMIN_PASSWORD') : ($argv[3] ?? null);
            $name = $argv[4] ?? $username;

            if (!$username || !$password) {
                exit("Usage: php bin/console.php user:ensure <username> <password> [name]\n"
                    . "   or: set AUTH_ADMIN_USER and AUTH_ADMIN_PASSWORD, then run without arguments\n");
            }

            if (strlen($password) < Database::MIN_PASSWORD_LENGTH) {
                fwrite(STDERR, 'user:ensure: the password must be at least '
                    . Database::MIN_PASSWORD_LENGTH . " characters. The admin account was NOT created.\n"
                    . "Fix AUTH_ADMIN_PASSWORD in .env and restart the container.\n");
                exit(1);
            }

            $db->ensureAuthSchema();
            $exists = false;
            foreach ($db->getDibi()->select('username')->from('users')->fetchAll() as $account) {
                $stored = (string) $account['username'];
                if (trim(strtolower($stored)) !== $username) {
                    continue;
                }
                if ($stored !== $username) {
                    fwrite(STDERR, "user:ensure: an existing account has a username with surrounding whitespace or different case. No account was created. Review the users table before trying again.\n");
                    exit(1);
                }
                $exists = true;
            }

            if ($exists) {
                echo "User {$username} already exists; left untouched.\n";
                break;
            }

            $id = $db->addAuthUser($username, $password, $name, Authorization::ROLE_OWNER);
            echo "Created user #{$id}: {$username} (owner)\n";
            break;

        case 'user:password':
            $username = $argv[2] ?? null;
            $password = $argv[3] ?? null;

            if (!$username || !$password) {
                exit("Usage: php bin/console.php user:password <username> <new-password>\n");
            }

            echo $db->setUserPassword($username, $password)
                ? "Password updated for {$username}\n"
                : "No user found: {$username}\n";
            break;

        case 'user:role':
            $username = $argv[2] ?? null;
            $role = isset($argv[3]) ? (int) $argv[3] : 0;
            if (!$username || !Authorization::isValidRole($role)) {
                exit("Usage: php bin/console.php user:role <username> <role 1-4>\n");
            }
            echo $db->setUserRole($username, $role)
                ? "Role updated for {$username}: {$role}\n"
                : "No user found: {$username}\n";
            break;

        case 'user:list':
            $rows = $db->getDibi()->select('id, username, name, role')->from('users')->fetchAll();
            foreach ($rows as $u) {
                echo "#{$u['id']}\t{$u['username']}\t{$u['name']}\t(role {$u['role']})\n";
            }
            break;

        case 'downloads:poll':
            if (!Downloads\Feature::enabled(true)) {
                break;
            }
            $connections = new Integrations\ConnectionRepository();
            if (array_filter($connections->all(), static fn ($connection): bool => $connection->enabled) === []) {
                break;
            }
            (new Health\WorkerMonitor())->observe('downloads', static function () use ($connections): bool {
                $result = (new Downloads\Collector($connections, new Downloads\DownloadRepository()))->run();
                return $result['failed'] === 0;
            }, 'request_failed');
            break;

        case 'history:poll':
            $monitor = new Health\WorkerMonitor();
            // Once a browser has introduced the one-time library repair, keep
            // advancing it in the background so closing the page cannot lose
            // progress. A missing task is deliberately not started here: the
            // user should see its upgrade dialog first.
            try {
                (new Jellyfin\HistoryLibraryBackfillService())->continueStartedBatch();
            } catch (\Throwable $e) {
                Log::logException($e);
            }

            // Poll Jellyfin for active sessions and record them. Run on a timer
            // (Docker poller sidecar / cron) so history logs even when nobody
            // has the dashboard open. Stays quiet unless something is playing.
            // Alert subscribed devices about plays that just started (skips the
            // users in PUSH_IGNORE_USERS). No-op unless VAPID keys are set.
            (new Console\HistoryPoll(
                static fn (): int => $monitor->run('history', static fn (): int => (new Jellyfin\NowPlayingService())->recordActivePlays()),
                static function () use ($monitor): int {
                    if (!Config::bool('PUSH_ENABLED', true)) {
                        return 0;
                    }
                    return $monitor->run('playback_notifications', static function (): int {
                        $dispatcher = new Notifications\NotificationDispatcher();

                        return $dispatcher->hasAnyChannel()
                            ? (new Push\PlaybackNotifier(null, $dispatcher))->dispatch()
                            : 0;
                    });
                },
                static function (\Throwable $error): void {
                    Log::logException($error);
                },
                static function (string $message): void {
                    echo date('c') . $message . "\n";
                },
            ))->run();
            try {
                if (trim((string) Config::get('JELLYFIN_URL', '')) !== '') {
                    (new Jellyfin\MovieRecoveryService())->runBatch();
                }
            } catch (\Throwable $error) {
                Log::logException($error);
            }
            break;

        case 'artwork:expire':
            $removed = (new Jellyfin\ArtworkRepository($db))->purgeExpiredExternal(time());
            Jellyfin\ExternalPosterCache::expire();
            echo "Expired external poster records: {$removed}\n";
            break;

        case 'artwork:poll':
            $result = (new Jellyfin\ArtworkCollector())->runBatch();
            if ($result['error'] !== null) {
                echo 'Artwork collection paused: ' . $result['error'] . "\n";
            }
            $external = (new Jellyfin\ExternalPosterCollector())->runBatch();
            if ($external['error'] !== null) {
                echo 'External poster recovery paused: ' . $external['error'] . "\n";
            }
            break;

        case 'seerr:poll':
            // Mirror the latest Jellyseerr requests locally (one list call, plus
            // a detail lookup only for requests we've never seen) and alert
            // subscribed devices about new ones. The page reads the mirror, so
            // it never waits on Jellyseerr.
            $monitor = new Health\WorkerMonitor();
            $client = new Jellyseerr\JellyseerrClient();
            $syncError = null;
            try {
                $added = $client->isConfigured()
                    ? $monitor->run('jellyseerr', static fn (): int => (new Jellyseerr\RequestSyncService($client))->sync())
                    : 0;
                if ($added > 0) {
                    echo date('c') . " seerr:poll - stored {$added} new request(s)\n";
                }
            } catch (\Throwable $error) {
                $syncError = $error;
            }

            try {
                $announced = 0;
                if (Config::bool('PUSH_ENABLED', true) && Config::bool('SEERR_NOTIFY_ENABLED', true)) {
                    $dispatcher = new Notifications\NotificationDispatcher();
                    if ($dispatcher->hasAnyChannel()) {
                        $announced = $monitor->run('request_notifications', static fn (): int => (new Jellyseerr\RequestNotifier(null, $dispatcher))->dispatch());
                    }
                }
                if ($announced > 0) {
                    echo date('c') . " seerr:poll - sent {$announced} request alert(s)\n";
                }
            } catch (\Throwable $error) {
                if ($syncError === null) {
                    $syncError = $error;
                } else {
                    Log::logException($error);
                }
            }
            if ($syncError !== null) {
                throw $syncError;
            }
            break;

        case 'push:vapid':
            // Generate a VAPID keypair for Web Push. Run once, paste into .env.
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            echo "VAPID keypair generated. Add to your .env and restart:\n\n";
            echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
            echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n";
            echo "VAPID_SUBJECT=mailto:you@example.com\n";
            break;

        case 'push:test':
            // Send a test notification through every configured channel and
            // report each one separately.
            $report = (new Push\PlaybackNotifier())->sendTest();
            foreach ($report as $channel => $r) {
                if (!$r['configured']) {
                    echo str_pad($channel, 9) . " not configured\n";
                    continue;
                }
                if ($channel === 'webpush') {
                    echo str_pad($channel, 9) . " subscriptions={$r['subscriptions']} sent={$r['sent']} failed={$r['failed']}\n";
                    continue;
                }
                echo str_pad($channel, 9) . ' ' . ($r['sent'] ? "sent\n" : "FAILED (see var/log for the reason)\n");
            }
            break;

        case 'push:devices':
            $devices = (new Push\PushSubscriptionRepository($db))->devices(
                null,
                true,
                Config::bool('AUTH_ENABLED', false),
                null,
            );
            if ($devices === []) {
                echo "No notification devices are registered.\n";
                break;
            }
            foreach ($devices as $device) {
                $owner = $device['owner'] !== null ? " owner={$device['owner']}" : '';
                echo "#{$device['id']}\t{$device['label']}\tstate={$device['state']}{$owner}\tcreated={$device['created_at']}\n";
            }
            break;

        case 'push:revoke':
            $deviceId = isset($argv[2]) ? (int) $argv[2] : 0;
            if ($deviceId < 1) {
                exit("Usage: php bin/console.php push:revoke <device-id>\n");
            }
            echo (new Push\PushSubscriptionRepository($db))->revokeById($deviceId, null, true, false)
                ? "Revoked notification device #{$deviceId}.\n"
                : "No notification device found: #{$deviceId}\n";
            break;

        case 'libraries:warm':
            // Refresh the cached library overview so the Libraries page never
            // triggers a cold multi-second scan inside a visitor's request.
            // Run on a timer by the entrypoint. Leaves the cache intact if
            // Jellyfin is unavailable.
            try {
                (new Health\WorkerMonitor())->run('libraries', static fn () => (new Jellyfin\LibraryOverviewService())->refreshCache());
                echo date('c') . " libraries:warm - cache refreshed\n";
            } catch (\Throwable $e) {
                fwrite(STDERR, date('c') . ' libraries:warm skipped: ' . $e->getMessage() . "\n");
            }
            break;

        case 'history:replace-movie':
            $oldId = $argv[2] ?? '';
            $currentId = $argv[3] ?? '';
            $flags = array_slice($argv, 4);
            if ($oldId === '' || $currentId === ''
                || array_diff($flags, ['--apply', '--confirm-same-movie']) !== []) {
                throw new \InvalidArgumentException('Usage: php bin/console.php history:replace-movie <old-id> <current-id> [--apply --confirm-same-movie]');
            }
            $service = new Jellyfin\MovieReplacementService($db);
            $apply = in_array('--apply', $flags, true);
            $preview = $apply
                ? $service->apply($oldId, $currentId, in_array('--confirm-same-movie', $flags, true))
                : $service->preview($oldId, $currentId);
            echo json_encode($preview, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            echo $apply
                ? "Movie replacement saved. Original history rows were preserved.\n"
                : "Preview only. Check both movies, then repeat with --apply --confirm-same-movie to save.\n";
            break;

        case 'history:unlink-movie':
            $oldId = $argv[2] ?? '';
            $flags = array_slice($argv, 3);
            if ($oldId === '' || array_diff($flags, ['--apply']) !== []) {
                throw new \InvalidArgumentException('Usage: php bin/console.php history:unlink-movie <old-id> [--apply]');
            }
            $result = (new Jellyfin\MovieReplacementService($db))->unlink($oldId, in_array('--apply', $flags, true));
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            echo $result['applied'] ? "Replacement mapping removed if present.\n" : "Preview only. Repeat with --apply to remove this mapping.\n";
            break;

        case 'database:migrate-to-sqlite':
            $requestedPath = $argv[2] ?? '';
            $confirmedStopped = in_array('--confirm-stopped', array_slice($argv, 3), true);
            if ($requestedPath === '' || !$confirmedStopped) {
                fwrite(STDERR, "Usage: php bin/console.php database:migrate-to-sqlite <sqlite-file> --confirm-stopped\n");
                fwrite(STDERR, "Stop the web app and every poller before running this command.\n");
                exit(1);
            }

            $directory = realpath(dirname($requestedPath));
            if ($directory === false && dirname($requestedPath) === '.') {
                $directory = ROOT_DIR;
            }
            if ($directory === false) {
                throw new \InvalidArgumentException('The destination directory does not exist.');
            }
            $destination = $directory . DIRECTORY_SEPARATOR . basename($requestedPath);

            echo "Copying MariaDB into a new SQLite database. The MariaDB source will not be changed.\n";
            $counts = (new Migration\MariaDbToSqliteMigrator($db))->migrate($destination);
            foreach ($counts as $table => $count) {
                echo "  {$table}: {$count} row(s)\n";
            }
            echo "Migration verified successfully: {$destination}\n";
            break;

        case 'trending:debug':
            // Diagnose why a library is / isn't excluded from the Trending strip.
            $range = $argv[2] ?? 'week';
            $raw = (string) Config::get('TRENDING_EXCLUDE_LIBRARIES', '');
            echo "TRENDING_EXCLUDE_LIBRARIES raw value: [{$raw}]\n";

            $names = array_values(array_filter(array_map(
                static fn (string $s): string => mb_strtolower(trim($s, " \t\n\r\0\x0B\"'")),
                explode(',', $raw)
            )));
            echo 'parsed exclude names: ' . (implode(' | ', $names) ?: '(none)') . "\n";

            $client = new Jellyfin\JellyfinClient();
            $locations = $client->libraryLocations();
            echo 'libraries Jellyfin reports: ' . (implode(', ', array_keys($locations)) ?: '(none; token not admin?)') . "\n";

            $prefixes = [];
            foreach ($names as $n) {
                foreach ($locations[$n] ?? [] as $loc) {
                    $prefixes[] = rtrim($loc, '/');
                }
            }
            echo 'excluded path prefixes: ' . (implode(', ', $prefixes) ?: '(NONE resolved; name did not match a library)') . "\n\n";

            $data = (new Jellyfin\PlaybackStatisticsService())->data($range);
            echo "Trending (range={$range}) after exclusion:\n";
            foreach ($data['trending'] as $t) {
                $path = $client->itemPath((string) $t['itemId']);
                $hit = $path === ''; // unresolvable (deleted) items are excluded too
                foreach ($prefixes as $p) {
                    if ($path === $p || str_starts_with($path, $p . '/')) {
                        $hit = true;
                        break;
                    }
                }
                $reason = $path === '' ? ' (deleted/unresolvable)' : '';
                echo sprintf("  - %-26s\n      itemId=%s\n      path=%s\n      would-exclude=%s%s\n", (string) $t['title'], (string) $t['itemId'], $path !== '' ? $path : '(empty)', $hit ? 'YES' : 'no', $reason);
            }
            break;

        default:
            echo "MK Framework console\n\n";
            echo "Usage:\n";
            echo "  php bin/console.php user:add <username> <password> <name> [role]\n";
            echo "  php bin/console.php user:password <username> <new-password>\n";
            echo "  php bin/console.php user:role <username> <role 1-4>\n";
            echo "  php bin/console.php user:list\n";
            echo "  php bin/console.php history:poll   (record currently-playing sessions)\n";
            echo "  php bin/console.php artwork:poll   (save and refresh historical posters)\n";
            echo "  php bin/console.php artwork:expire (remove expired online poster data without network requests)\n";
            echo "  php bin/console.php history:replace-movie <old-id> <current-id> [--apply --confirm-same-movie]\n";
            echo "  php bin/console.php history:unlink-movie <old-id> [--apply]\n";
            echo "  php bin/console.php downloads:poll (refresh configured download clients)\n";
            echo "  php bin/console.php libraries:warm (refresh the cached library overview)\n";
            echo "  php bin/console.php database:migrate-to-sqlite <file> --confirm-stopped\n";
            echo "  php bin/console.php seerr:poll     (sync Jellyseerr requests + alert on new ones)\n";
            echo "  php bin/console.php push:vapid     (generate a Web Push VAPID keypair)\n";
            echo "  php bin/console.php push:test      (send a test notification to subscribers)\n\n";
            echo "  php bin/console.php push:devices   (list safe notification device metadata)\n";
            echo "  php bin/console.php push:revoke <device-id>\n\n";
            echo "Roles: 1 owner, 2 admin, 3 user, 4 guest.\n";
            echo 'Targets the database in .env (DB_NAME=' . DATABASE_NAME . ").\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
