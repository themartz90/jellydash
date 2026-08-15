<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;
use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Main;

/**
 * The Settings page: user preferences stored in the DB (AppSettings), as
 * opposed to infrastructure config which stays in the environment.
 */
final class SettingsController extends Controller
{
    public function handle(): void
    {
        // Library names for the trending-exclusion checkboxes. A Jellyfin
        // outage degrades to a free-text field instead of an error page.
        $libraries = null;
        try {
            $libraries = (new JellyfinClient())->libraryNames();
        } catch (\Throwable) {
            $libraries = null;
        }

        // Users seen in play history, for the notification-ignore checkboxes.
        $users = [];
        try {
            $users = (new PlayHistoryRepository())->users();
        } catch (\Throwable) {
            $users = [];
        }

        $excluded = $this->csvValues(
            AppSettings::get('trending_exclude_libraries')
                ?? (string) Config::get('TRENDING_EXCLUDE_LIBRARIES', '')
        );
        $ignoredUsers = $this->csvValues(
            AppSettings::get('push_ignore_users')
                ?? (string) Config::get('PUSH_IGNORE_USERS', '')
        );

        // Excluded names that aren't among the discovered libraries (renamed
        // or Jellyfin briefly down when they were saved) still need showing.
        $knownLower = array_map('mb_strtolower', $libraries ?? []);
        $extraExcluded = array_values(array_filter(
            $excluded,
            static fn (string $name): bool => !in_array(mb_strtolower($name), $knownLower, true)
        ));

        // Same for ignored users the history table hasn't seen yet.
        $knownUsersLower = array_map('mb_strtolower', $users);
        $extraIgnored = array_values(array_filter(
            $ignoredUsers,
            static fn (string $name): bool => !in_array(mb_strtolower($name), $knownUsersLower, true)
        ));

        $this->render('settings/index', [
            'layout' => $this->layout([
                'title' => 'Settings',
                'page' => 'settings',
                'hide_footer' => true,
            ]),
            'saved' => isset($_GET['saved']),
            'server_label_value' => AppSettings::get('server_label', 'Jellyfin dashboard'),
            'show_server_stats' => AppSettings::bool('show_server_stats', true),
            'libraries' => $libraries,
            'excluded' => $excluded,
            'extra_excluded' => implode(', ', $extraExcluded),
            'users' => $users,
            'ignored_users' => $ignoredUsers,
            'extra_ignored' => implode(', ', $extraIgnored),
            'import' => [
                'inserted' => max(0, (int) (Main::captureGetString('imported') ?? 0)),
                'skipped' => max(0, (int) (Main::captureGetString('skipped') ?? 0)),
                'unresolved' => max(0, (int) (Main::captureGetString('unresolved') ?? 0)),
                'error' => trim((string) (Main::captureGetString('import_error') ?? '')),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function csvValues(string $raw): array
    {
        return array_values(array_filter(array_map(
            static fn (string $s): string => trim($s, " \t\n\r\0\x0B\"'"),
            explode(',', $raw)
        ), static fn (string $s): bool => $s !== ''));
    }
}
