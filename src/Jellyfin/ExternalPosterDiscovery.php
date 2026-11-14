<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Http\RemoteRequestException;

/** Finds possible titles without turning search results into identity evidence. */
final class ExternalPosterDiscovery
{
    public function __construct(private ArtworkRepository $art, private ExternalPosterProvider $provider)
    {
    }

    /** @param array<string, mixed> $job */
    public function discover(array $job, string $lease, int $now): void
    {
        $started = hrtime(true);
        if ((int) $job['snoozed_until'] > $now || $job['origin'] !== 'automatic' || (int) $job['identity_conflict'] !== 0) {
            $this->art->retryExternal($job, null, $lease, $now, 86400);
            return;
        }
        $input = $this->art->discoveryInput((string) $job['item_id']);
        if ($input === null) {
            $this->art->deferDiscovery($job, $lease, $now, $now + 604800);
            return;
        }
        $record = $this->art->discoveryRecord($input['key'], $now);
        if ($record !== null) {
            $this->art->deferDiscovery($job, $lease, $now, (int) $record['expires_at']);
            return;
        }
        if ((int) $job['discovery_after'] > $now) {
            $this->art->retryExternal($job, null, $lease, $now, min(86400, (int) $job['discovery_after'] - $now));
            return;
        }
        if (!$this->art->reserveDiscovery($job, $input, $lease, $now)) {
            $source = $this->art->source();
            $this->art->deferDiscovery($job, $lease, $now, max($now + 60, (int) $source['external_discovery_after'], (int) $source['external_next_attempt']));
            return;
        }
        try {
            $results = $this->provider->search($input['kind'], $input['title']);
            usort($results, static function (array $a, array $b) use ($input): int {
                return (int) self::sameTitle($b['title'], $input['title']) <=> (int) self::sameTitle($a['title'], $input['title']);
            });
            $candidates = [];
            foreach (array_slice($results, 0, 5) as $candidate) {
                $candidate['title'] = mb_substr($candidate['title'], 0, 512);
                $candidates[] = $candidate;
            }
            $this->art->completeDiscovery($job, $input, $candidates, $lease, $now + (int) ((hrtime(true) - $started) / 1e9));
        } catch (\Throwable $error) {
            $delay = $error instanceof RemoteRequestException ? max(900, $error->retryAfterSeconds) : 900;
            $this->art->completeDiscovery($job, $input, [], $lease, $now + (int) ((hrtime(true) - $started) / 1e9), 'request_failed', $delay);
            throw $error;
        }
    }

    public static function sameTitle(string $first, string $second): bool
    {
        $normalize = static fn (string $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
        return $normalize($first) === $normalize($second);
    }
}
