<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;

/** Reads saved artwork without contacting Jellyfin. */
final class ArtworkResolver
{
    private ArtworkRepository $artwork;
    private MovieReplacements $movies;
    private MovieIdentityRepository $movieIdentity;

    public function __construct(?Database $database = null, ?ArtworkRepository $artwork = null, ?MovieReplacements $movies = null)
    {
        $database ??= Container::db();
        $this->artwork = $artwork ?? new ArtworkRepository($database);
        $this->movies = $movies ?? new MovieReplacements($database, $this->artwork->scope);
        $this->movieIdentity = new MovieIdentityRepository($database, $this->artwork->scope);
    }

    public function blocked(): bool
    {
        $source = $this->artwork->source();
        $movies = $this->movieIdentity->state();
        return (int) $source['blocked'] !== 0 || !empty($movies['serverMismatch'])
            || ($source['server_id'] !== '' && !empty($movies['serverId']) && $source['server_id'] !== $movies['serverId']);
    }

    public function target(string $id, bool $series): ?string
    {
        if ($this->blocked()) {
            return null;
        }
        $item = $this->artwork->item($id);
        if ($series) {
            if ($item === null || $item['item_type'] !== 'Episode' || !is_string($item['poster_id'])) {
                return null;
            }
            $poster = $this->artwork->poster($item['poster_id']);
            $liveParent = (int) ($poster['presence'] ?? -1) === 1 && (int) ($poster['provider_conflict'] ?? 0) === 0
                && (int) ($poster['suppressed'] ?? 0) === 0
                && (in_array($item['evidence'], ['observed', 'manual'], true) || (int) $this->artwork->source()['legacy_confirmed'] !== 0);
            return $item['status'] === 'ready' || $liveParent ? $item['poster_id'] : null;
        }
        if ($item !== null && $item['evidence'] === 'manual' && is_string($item['poster_id'])) {
            return $item['poster_id'];
        }
        return $this->movies->resolve($id);
    }

    /** @return array<string, mixed>|null */
    public function image(string $id, bool $series, bool $direct = false): ?array
    {
        $image = $this->jellyfinImage($id, $series, $direct);
        if ($image !== null || $direct || $this->blocked()) {
            return $image;
        }
        $binding = $this->artwork->externalBinding($id);
        $target = $this->target($id, $series);
        $item = $this->artwork->item($id);
        if ($item !== null && is_string($item['poster_id'])) {
            $rawPoster = $this->artwork->poster($item['poster_id']);
            if ((int) ($rawPoster['presence'] ?? -1) === 1 || (int) ($rawPoster['suppressed'] ?? 0) !== 0) {
                return null;
            }
        }
        if ($binding === null || (int) $binding['suppressed'] !== 0 || (int) $binding['presence'] !== 0
            || ($target !== null && ((int) ($this->artwork->poster($target)['presence'] ?? -1) === 1
                || (int) ($this->artwork->poster($target)['suppressed'] ?? 0) !== 0))) {
            return null;
        }
        $image = $this->artwork->externalImage($id);
        if ($image !== null || $series) {
            return $image;
        }
        // Resolve the current movie group on every read so Undo never leaves a borrowed alias.
        $members = $this->movies->historyMembers($id);
        sort($members, SORT_STRING);
        foreach (array_unique([$target ?? $id, ...$members]) as $member) {
            $image = $this->artwork->externalImage($member);
            if ($image !== null) {
                return $image;
            }
        }
        return null;
    }

    public function placeholder(string $id): string
    {
        $item = $this->blocked() ? null : $this->artwork->item($id);
        $title = $item === null ? 'Poster unavailable' : (string) ($item['item_type'] === 'Episode' && $item['series_name'] !== '' ? $item['series_name'] : $item['title']);
        $title = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $title);
        $lines = [];
        $remaining = mb_substr($title, 0, 100);
        while ($remaining !== '' && count($lines) < 4) {
            $lines[] = mb_substr($remaining, 0, 21);
            $remaining = mb_substr($remaining, 21);
        }
        $text = '';
        foreach ($lines as $index => $line) {
            $text .= '<tspan x="150" dy="' . ($index === 0 ? '0' : '27') . '">' . htmlspecialchars($line, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</tspan>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="450" viewBox="0 0 300 450"><rect width="300" height="450" fill="#191522"/><text x="150" y="185" text-anchor="middle" font-family="sans-serif" font-size="21" fill="#ddd8e6">' . $text . '</text><text x="150" y="395" text-anchor="middle" font-family="sans-serif" font-size="14" fill="#aaa1b8">Poster unavailable</text></svg>';
    }

    /** @return array<string, mixed>|null */
    public function jellyfinImage(string $id, bool $series, bool $direct = false): ?array
    {
        if ($this->blocked()) {
            return null;
        }
        if ($direct) {
            return $this->artwork->image($id);
        }
        $item = $this->artwork->item($id);
        if ($item !== null && $item['evidence'] === 'manual' && is_string($item['poster_id'])) {
            $selected = $this->artwork->image($item['poster_id']);
            if ($selected !== null) {
                return $selected;
            }
        }
        $target = $this->target($id, $series);
        if ($target === null) {
            return null;
        }
        if ($series) {
            return $this->artwork->image($target);
        }
        if ((int) ($this->artwork->poster($target)['suppressed'] ?? 0) !== 0) {
            return null;
        }
        $canonical = $this->artwork->item($target);
        if ($canonical !== null && $canonical['evidence'] === 'manual' && is_string($canonical['poster_id'])) {
            $selected = $this->artwork->image($canonical['poster_id']);
            if ($selected !== null) {
                return $selected;
            }
        }
        $current = $this->artwork->image($target);
        if ($current !== null) {
            return $current;
        }
        $members = $this->movies->historyMembers($id);
        sort($members, SORT_STRING);
        foreach ($members as $member) {
            $overlay = $this->artwork->item($member);
            if ($overlay !== null && $overlay['evidence'] === 'manual' && is_string($overlay['poster_id'])) {
                $selected = $this->artwork->image($overlay['poster_id']);
                if ($selected !== null) {
                    return $selected;
                }
            }
        }
        foreach (array_unique([$id, ...$members]) as $member) {
            $image = $this->artwork->image($member);
            if ($image !== null) {
                return $image;
            }
        }
        return null;
    }
}
