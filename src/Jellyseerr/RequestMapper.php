<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

/**
 * Translates Jellyseerr's raw API shapes into what the UI shows.
 *
 * A request carries two independent state fields (the approval state and the
 * media's availability state), which together decide the single status pill the
 * card displays.
 */
final class RequestMapper
{
    // MediaRequestStatus
    public const REQUEST_PENDING = 1;
    public const REQUEST_APPROVED = 2;
    public const REQUEST_DECLINED = 3;
    public const REQUEST_FAILED = 4;
    public const REQUEST_COMPLETED = 5;

    // MediaStatus
    public const MEDIA_UNKNOWN = 1;
    public const MEDIA_PENDING = 2;
    public const MEDIA_PROCESSING = 3;
    public const MEDIA_PARTIALLY_AVAILABLE = 4;
    public const MEDIA_AVAILABLE = 5;
    public const MEDIA_BLOCKLISTED = 6;
    public const MEDIA_DELETED = 7;

    /**
     * Collapse the approval + availability states into one label, using
     * Jellyseerr's own vocabulary. Approval problems (declined/failed/awaiting)
     * win because they need attention; otherwise anything not yet on the server
     * is simply "Requested"; how far along the grab is isn't something we can
     * report accurately, and Jellyseerr doesn't either.
     *
     * @return array{label: string, kind: string}
     */
    public static function status(int $requestStatus, int $mediaStatus): array
    {
        return match ($requestStatus) {
            self::REQUEST_DECLINED => ['label' => 'Declined', 'kind' => 'declined'],
            self::REQUEST_FAILED => ['label' => 'Failed', 'kind' => 'failed'],
            self::REQUEST_PENDING => ['label' => 'Pending approval', 'kind' => 'pending'],
            default => match ($mediaStatus) {
                self::MEDIA_AVAILABLE => ['label' => 'Available', 'kind' => 'available'],
                self::MEDIA_PARTIALLY_AVAILABLE => ['label' => 'Partially available', 'kind' => 'partial'],
                self::MEDIA_BLOCKLISTED => ['label' => 'Blocklisted', 'kind' => 'blocked'],
                self::MEDIA_DELETED => ['label' => 'Deleted', 'kind' => 'deleted'],
                default => ['label' => 'Requested', 'kind' => 'requested'],
            },
        };
    }

    /**
     * Project a list or detail result into display fields. Metadata can be
     * supplied separately from the movie/TV endpoint or a local snapshot.
     *
     * @param array<string, mixed> $request
     * @param array{title: string, year: ?string, posterPath: ?string}|null $details
     * @return array{requestId: int, mediaId: ?int, tmdbId: ?int, mediaType: ?string, is4k: ?bool,
     *     requestStatus: ?int, mediaStatus: ?int, seasonNumbers: ?list<int>, requestedAt: ?string,
     *     requester: string, title: string, year: ?string, posterUrl: ?string, statusLabel: string, statusKind: string}
     */
    public static function display(array $request, ?array $details = null): array
    {
        $requestId = self::positiveId($request['id'] ?? null);
        if ($requestId === null) {
            throw new \RuntimeException('Seerr returned a request without a valid ID.');
        }

        $media = is_array($request['media'] ?? null) ? $request['media'] : [];
        $type = $request['type'] ?? $media['mediaType'] ?? null;
        $type = in_array($type, ['movie', 'tv'], true) ? $type : null;
        $requestStatus = is_int($request['status'] ?? null) ? $request['status'] : null;
        $is4k = is_bool($request['is4k'] ?? null) ? $request['is4k'] : null;
        $availability = match ($is4k) {
            true => $media['status4k'] ?? null,
            false => $media['status'] ?? null,
            null => null,
        };
        $mediaStatus = is_int($availability) ? $availability : null;
        $status = $requestStatus === null
            ? ['label' => 'Unknown', 'kind' => 'unknown']
            : self::status($requestStatus, $mediaStatus ?? self::MEDIA_UNKNOWN);
        $requestedBy = is_array($request['requestedBy'] ?? null) ? $request['requestedBy'] : [];

        return [
            'requestId' => $requestId,
            'mediaId' => self::positiveId($media['id'] ?? null),
            'tmdbId' => self::positiveId($media['tmdbId'] ?? null),
            'mediaType' => $type,
            'is4k' => $is4k,
            'requestStatus' => $requestStatus,
            'mediaStatus' => $mediaStatus,
            'seasonNumbers' => $type === 'movie' ? [] : self::seasonNumbers($request['seasons'] ?? null),
            'requestedAt' => self::text($request['createdAt'] ?? null),
            'requester' => self::text($requestedBy['displayName'] ?? null)
                ?? self::text($requestedBy['jellyfinUsername'] ?? null)
                ?? self::text($requestedBy['username'] ?? null)
                ?? 'Unknown',
            'title' => $details['title'] ?? 'Unknown title',
            'year' => $details['year'] ?? null,
            'posterUrl' => self::posterUrl($details['posterPath'] ?? null),
            'statusLabel' => $status['label'],
            'statusKind' => $status['kind'],
        ];
    }

    private static function positiveId(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 9_007_199_254_740_991 ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<int>|null */
    private static function seasonNumbers(mixed $seasons): ?array
    {
        if (!is_array($seasons) || !array_is_list($seasons)) {
            return null;
        }

        $numbers = [];
        foreach ($seasons as $season) {
            $number = is_array($season) ? ($season['seasonNumber'] ?? null) : null;
            if (!is_int($number) || $number < 0) {
                return null;
            }
            $numbers[$number] = $number;
        }
        sort($numbers, SORT_NUMERIC);

        return $numbers;
    }

    /**
     * Pull the display fields out of a TMDB detail payload. Movies use
     * title/releaseDate; series use name/firstAirDate.
     *
     * @param array<string, mixed> $detail
     * @return array{title: string, year: ?string, posterPath: ?string}
     */
    public static function details(array $detail, string $mediaType): array
    {
        $isTv = $mediaType === 'tv';

        $title = trim((string) ($isTv ? ($detail['name'] ?? '') : ($detail['title'] ?? '')));
        if ($title === '') {
            // Fall back to the other field in case a payload surprises us.
            $title = trim((string) ($detail['title'] ?? $detail['name'] ?? ''));
        }

        $date = trim((string) ($isTv ? ($detail['firstAirDate'] ?? '') : ($detail['releaseDate'] ?? '')));
        $year = preg_match('/^(\d{4})/', $date, $m) === 1 ? $m[1] : null;

        $poster = trim((string) ($detail['posterPath'] ?? ''));

        return [
            'title' => $title !== '' ? $title : 'Unknown title',
            'year' => $year,
            'posterPath' => $poster !== '' ? $poster : null,
        ];
    }

    /**
     * Poster URL on the TMDB CDN. `w342` is the smallest size that still looks
     * sharp on a retina card.
     */
    public static function posterUrl(?string $posterPath): ?string
    {
        if ($posterPath === null || $posterPath === '') {
            return null;
        }

        return 'https://image.tmdb.org/t/p/w342' . $posterPath;
    }

    /**
     * Up to two initials for the requester avatar bubble.
     */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) === 1) {
            return $first;
        }

        return $first . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
