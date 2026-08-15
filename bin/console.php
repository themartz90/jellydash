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
date_default_timezone_set(Config::get('APP_TIMEZONE', TIMEZONE_DEFAULT) ?? TIMEZONE_DEFAULT);

$command = $argv[1] ?? '';

try {
    $db = Container::db();

    switch ($command) {
        case 'user:add':
            $db->ensureAuthSchema();
            $username = $argv[2] ?? null;
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
            $username = $useEnvironment ? Config::get('AUTH_ADMIN_USER') : ($argv[2] ?? null);
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
            $exists = $db->getDibi()->select('id')->from('users')
                ->where('username = %s', trim(strtolower($username)))->fetch();

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

        case 'user:list':
            $rows = $db->getDibi()->select('id, username, name, role')->from('users')->fetchAll();
            foreach ($rows as $u) {
                echo "#{$u['id']}\t{$u['username']}\t{$u['name']}\t(role {$u['role']})\n";
            }
            break;

        case 'history:poll':
            // Poll Jellyfin for active sessions and record them. Run on a timer
            // (Docker poller sidecar / cron) so history logs even when nobody
            // has the dashboard open. Stays quiet unless something is playing.
            $logged = (new Jellyfin\NowPlayingService())->recordActivePlays();
            if ($logged > 0) {
                echo date('c') . " history:poll - recorded {$logged} active stream(s)\n";
            }

            // Alert subscribed devices about plays that just started (skips the
            // users in PUSH_IGNORE_USERS). No-op unless VAPID keys are set.
            $alerts = (new Push\PlaybackNotifier())->dispatch();
            if ($alerts > 0) {
                echo date('c') . " history:poll - sent {$alerts} playback alert(s)\n";
            }
            break;

        case 'seerr:poll':
            // Mirror the latest Jellyseerr requests locally (one list call, plus
            // a detail lookup only for requests we've never seen) and alert
            // subscribed devices about new ones. The page reads the mirror, so
            // it never waits on Jellyseerr.
            $added = (new Jellyseerr\RequestSyncService())->sync();
            if ($added > 0) {
                echo date('c') . " seerr:poll - stored {$added} new request(s)\n";
            }

            $announced = (new Jellyseerr\RequestNotifier())->dispatch();
            if ($announced > 0) {
                echo date('c') . " seerr:poll - sent {$announced} request alert(s)\n";
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

        case 'libraries:warm':
            // Refresh the cached library overview so the Libraries page never
            // triggers a cold multi-second scan inside a visitor's request.
            // Run on a timer by the entrypoint. Leaves the cache intact if
            // Jellyfin is unavailable.
            try {
                (new Jellyfin\LibraryOverviewService())->refreshCache();
                echo date('c') . " libraries:warm - cache refreshed\n";
            } catch (\Throwable $e) {
                fwrite(STDERR, date('c') . ' libraries:warm skipped: ' . $e->getMessage() . "\n");
            }
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
            echo "  php bin/console.php user:list\n";
            echo "  php bin/console.php history:poll   (record currently-playing sessions)\n";
            echo "  php bin/console.php libraries:warm (refresh the cached library overview)\n";
            echo "  php bin/console.php database:migrate-to-sqlite <file> --confirm-stopped\n";
            echo "  php bin/console.php seerr:poll     (sync Jellyseerr requests + alert on new ones)\n";
            echo "  php bin/console.php push:vapid     (generate a Web Push VAPID keypair)\n";
            echo "  php bin/console.php push:test      (send a test notification to subscribers)\n\n";
            echo "Roles: 1 owner, 2 admin, 3 user, 4 guest.\n";
            echo "Targets the database in .env (DB_NAME=" . DATABASE_NAME . ").\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
