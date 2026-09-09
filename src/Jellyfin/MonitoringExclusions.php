<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;

/** Shared username rules for playback collection and its history views. */
final class MonitoringExclusions
{
    /** @var list<string> */
    private array $usernames;

    /** @param list<string>|null $usernames */
    public function __construct(?array $usernames = null)
    {
        $usernames ??= explode(',', AppSettings::get('ignore_users', requireAvailable: true) ?? (string) Config::get('IGNORE_USERS', ''));
        $normalized = [];
        foreach ($usernames as $username) {
            $name = mb_strtolower(trim($username));
            if ($name !== '') {
                $normalized[] = $name;
            }
        }
        $this->usernames = array_values(array_unique($normalized));
        sort($this->usernames, SORT_STRING);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->usernames;
    }

    public function excludes(?string $username): bool
    {
        return in_array(mb_strtolower(trim($username ?? '')), $this->usernames, true);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->usernames, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    public function filterSessions(array $sessions): array
    {
        return array_values(array_filter($sessions, fn (array $session): bool => !$this->excludes(
            isset($session['UserName']) ? (string) $session['UserName'] : null,
        )));
    }
}
