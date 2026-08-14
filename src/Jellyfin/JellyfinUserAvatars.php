<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Builds /api/image.php URLs for Jellyfin profile pictures.
 *
 * A user id is enough: the browser hits the proxy, which 404s when there is
 * no photo (initials stay). /Users is only fetched to map a display name to
 * an id for older history rows that never stored user_id.
 */
final class JellyfinUserAvatars
{
    /** @var array<string, string> lowercased name => user id */
    private array $idsByName = [];
    private bool $loaded = false;

    public function __construct(private ?JellyfinClient $client = null)
    {
    }

    public function url(?string $userId, ?string $userName = null, int $maxWidth = 80): ?string
    {
        $id = trim((string) $userId);
        if ($id === '') {
            $id = $this->idByName((string) $userName);
        }

        return self::proxyUrl($id, '', $maxWidth);
    }

    /**
     * @param array<int, array<string, mixed>> $users
     */
    public function loadFrom(array $users): void
    {
        $this->idsByName = [];

        foreach ($users as $user) {
            $id = trim((string) ($user['Id'] ?? ''));
            if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                continue;
            }

            $name = trim((string) ($user['Name'] ?? ''));
            if ($name !== '') {
                $this->idsByName[mb_strtolower($name)] = $id;
            }
        }

        $this->loaded = true;
    }

    public static function proxyUrl(string $userId, string $imageTag = '', int $maxWidth = 80): ?string
    {
        $userId = trim($userId);
        if ($userId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $userId)) {
            return null;
        }

        $url = '/api/image.php?user=' . rawurlencode($userId)
            . '&maxWidth=' . max(32, min(256, $maxWidth));

        $imageTag = trim($imageTag);
        if ($imageTag !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $imageTag)) {
            $url .= '&tag=' . rawurlencode($imageTag);
        }

        return $url;
    }

    private function idByName(string $userName): string
    {
        $name = mb_strtolower(trim($userName));
        if ($name === '') {
            return '';
        }

        $this->ensureLoaded();

        return $this->idsByName[$name] ?? '';
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            $this->loadFrom(($this->client ?? new JellyfinClient())->users());
        } catch (\Throwable) {
            $this->idsByName = [];
        }
    }
}
