<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Http\RemoteRequestException;

/** Repairs confirmed missing artwork separately from playback collection. */
final class ExternalPosterCollector
{
    private Database $database;
    private JellyfinClient $client;
    private ArtworkRepository $art;
    private ExternalPosterProvider $provider;
    /** @var \Closure(string): array{body: string, contentType: string} */
    private \Closure $fetcher;

    /** @param (\Closure(string): array{body: string, contentType: string})|null $fetcher */
    public function __construct(
        ?Database $database = null,
        ?JellyfinClient $client = null,
        ?ArtworkRepository $art = null,
        ?ExternalPosterProvider $provider = null,
        ?\Closure $fetcher = null
    ) {
        $this->database = $database ?? Container::db();
        $this->client = $client ?? new JellyfinClient();
        $this->art = $art ?? new ArtworkRepository($this->database, ThemePlaybackExclusions::serverKey($this->client->baseUrl()));
        $this->provider = $provider ?? new ExternalPosterProvider($this->client);
        $this->fetcher = $fetcher ?? $this->provider->fetch(...);
    }

    /** @return array{saved: int, checked: int, error: ?string} */
    public function runBatch(?int $now = null): array
    {
        $now ??= time();
        $this->art->purgeExpiredExternal($now);
        $started = hrtime(true);
        $result = ['saved' => 0, 'checked' => 0, 'error' => null];
        $source = $this->art->source();
        if (!$this->art->externalEnabled() || $this->art->externalBlocked() || $source['server_id'] === ''
            || (int) $source['next_attempt'] > $now || (int) ($source['external_next_attempt'] ?? 0) > $now) {
            return $result;
        }
        $lease = $this->art->claim($now);
        if ($lease === null) {
            return $result;
        }
        try {
            (new ArtworkCollector($this->database, $this->client, $this->art))->verifySource();
            $this->art->deferExternalSource($lease, $now, null, 0);
            $this->intake();
            foreach ($this->art->externalDue($now) as $job) {
                if ((hrtime(true) - $started) / 1e9 > 12) {
                    break;
                }
                ++$result['checked'];
                try {
                    $candidate = null;
                    $item = $this->art->item((string) $job['item_id']);
                    if ($item !== null && (new ArtworkResolver($this->database, $this->art))->jellyfinImage((string) $job['item_id'], $item['item_type'] === 'Episode') !== null) {
                        $this->art->externalCoveredByJellyfin((string) $job['item_id'], $lease, $now);
                        continue;
                    }
                    $this->inspect($job, $lease, $now);
                    $job = $this->art->externalBinding((string) $job['item_id']);
                    $currentNow = $now + (int) ((hrtime(true) - $started) / 1e9);
                    $cooldown = (int) $this->art->source()['external_next_attempt'];
                    if ($job !== null && $cooldown > $currentNow) {
                        $this->art->retryExternal($job, 'throttled', $lease, $currentNow, $cooldown - $currentNow);
                        continue;
                    }
                    if ($job === null || (int) $job['presence'] !== 0 || (int) $job['suppressed'] !== 0) {
                        if ($job !== null) {
                            $this->art->retryExternal($job, null, $lease, $now, 86400);
                        }
                        continue;
                    }
                    $saved = $this->art->externalImage((string) $job['item_id']);
                    if ($saved !== null && min((int) $saved['expires_at'], (int) $job['expires_at']) <= $now + 2592000) {
                        $fingerprint = $this->art->externalAssetFingerprint((string) $job['asset_key']);
                        $candidate = $this->provider->byId((string) $job['kind'], (string) $job['tmdb_id']);
                        $image = ($this->fetcher)($candidate['path']);
                        if ($this->art->renewExternal($job, $candidate, $image, $fingerprint, $lease, $now, ArtworkRepository::budget())) {
                            ++$result['saved'];
                        } else {
                            $this->art->retryExternal($job, 'capacity', $lease, $now, 3600);
                        }
                        continue;
                    }
                    if ($saved !== null) {
                        $this->art->retryExternal($job, null, $lease, $now, 86400);
                        continue;
                    }
                    if ($job['tmdb_id'] === null) {
                        if (!is_string($job['verified_id']) || (int) $job['identity_conflict'] !== 0 || $job['origin'] === 'manual') {
                            (new ExternalPosterDiscovery($this->art, $this->provider))->discover($job, $lease, $now + (int) ((hrtime(true) - $started) / 1e9));
                            continue;
                        }
                        $expectedFingerprint = $this->art->externalFingerprint([(string) $job['item_id']]);
                        if (!$this->art->externalEnabled()) {
                            continue;
                        }
                        $cached = $this->art->reusableExternalAsset((string) $job['kind'], $job['verified_id']);
                        $candidate = $cached !== null ? ['id' => $cached['provider_id'], 'kind' => $cached['kind'],
                            'path' => $cached['poster_path'], 'title' => $cached['title'], 'year' => $cached['year'], 'expires_at' => $cached['expires_at']]
                            : $this->provider->byId((string) $job['kind'], $job['verified_id']);
                        $this->art->externalSelect([(string) $job['item_id']], $expectedFingerprint, $candidate, true);
                        $job = $this->art->externalBinding((string) $job['item_id']);
                    }
                    if ($job === null || !is_string($job['tmdb_id']) || !is_string($job['poster_path'])) {
                        continue;
                    }
                    if (!$this->art->externalEnabled()) {
                        continue;
                    }
                    $cached = $this->art->reusableExternalAsset((string) $job['kind'], $job['tmdb_id'], $job['poster_path']);
                    $candidate ??= $cached !== null ? ['path' => $cached['poster_path']] : $this->provider->byId((string) $job['kind'], $job['tmdb_id']);
                    if ($candidate['path'] !== $job['poster_path']) {
                        throw new \RuntimeException('selection_changed');
                    }
                    $image = $cached !== null ? ['body' => (string) $cached['body'], 'contentType' => (string) $cached['content_type']]
                        : ($this->fetcher)($job['poster_path']);
                    $image = ArtworkFetcher::validate($image['body'], $image['contentType']);
                    if ($this->art->publishExternal($job, $image, $lease, $now + (int) ((hrtime(true) - $started) / 1e9), ArtworkRepository::budget())) {
                        ++$result['saved'];
                    }
                } catch (\Throwable $error) {
                    $currentBinding = $this->art->externalBinding((string) $job['item_id']);
                    if ($currentBinding !== null && ((int) $currentBinding['revision'] !== (int) $job['revision']
                        || (int) $currentBinding['config_revision'] !== (int) $job['config_revision']
                        || $currentBinding['context_key'] !== ($job['context_key'] ?? null))) {
                        continue;
                    }
                    $message = $error->getMessage();
                    $reason = in_array($message, ['identity_conflict', 'no_exact_match', 'provider_response', 'selection_changed', 'invalid_image', 'oversized', 'decoder_missing'], true)
                        ? $message : ($error->getCode() === 429 ? 'throttled' : 'request_failed');
                    $delay = $error instanceof RemoteRequestException ? max(900, $error->retryAfterSeconds) : 3600;
                    $this->art->retryExternal($job, $reason, $lease, $now, $delay);
                    if ($reason === 'no_exact_match') {
                        $this->art->deferExternalSource($lease, $now, $reason, $now + 60);
                        break;
                    }
                    if ($reason === 'request_failed' || in_array($error->getCode(), [401, 403, 429, 503], true)) {
                        $this->art->deferExternalSource($lease, $now, $reason, $now + $delay);
                        $result['error'] = $reason;
                        break;
                    }
                    if (in_array($error->getCode(), [401, 403, 429, 503], true)) {
                        $result['error'] = $reason;
                        break;
                    }
                }
            }
        } catch (\Throwable $error) {
            $result['error'] = 'request_failed';
            $delay = $error instanceof RemoteRequestException ? max(900, $error->retryAfterSeconds) : 900;
            $this->art->deferExternalSource($lease, $now, 'request_failed', $now + $delay);
        } finally {
            $this->art->release($lease);
        }
        return $result;
    }

    private function intake(): void
    {
        $query = $this->database->getDibi()->select('a.*')->from('artwork_items')->as('a')
            ->leftJoin('artwork_external_bindings')->as('e')->on('a.source_key = e.source_key AND a.item_id = e.item_id')
            ->where('a.source_key = %s AND e.item_id IS NULL', $this->art->scope)->where('a.item_type IN %in', ['Movie', 'Episode']);
        if ((int) $this->art->source()['legacy_confirmed'] === 0) {
            $query->where('a.evidence IN %in', ['observed', 'manual']);
        }
        foreach ($query->orderBy('a.item_id')->limit(50)->fetchAll() as $item) {
            $this->art->externalObserve((string) $item['item_id']);
        }
    }

    /** @param array<string, mixed> $binding */
    private function inspect(array $binding, string $lease, int $now): void
    {
        $expectedFingerprint = $this->art->externalFingerprint([(string) $binding['item_id']]);
        $item = $this->art->item((string) $binding['item_id']);
        if ($item === null) {
            throw new \RuntimeException('identity_changed');
        }
        $collector = new ArtworkCollector($this->database, $this->client, $this->art);
        $target = is_string($item['poster_id']) ? $item['poster_id'] : (string) $item['item_id'];
        if ($item['item_type'] === 'Movie') {
            $target = (new MovieReplacements($this->database, $this->art->scope))->resolve($target);
        }
        $poster = $this->art->poster($target);
        $conflicted = $poster !== null && (int) $poster['provider_conflict'] !== 0;
        $verified = $poster !== null && (int) $poster['provider_conflict'] === 0 ? $poster['provider_tmdb_id'] : null;
        if ($item['item_type'] === 'Movie' && $verified === null) {
            $identity = (new MovieIdentityRepository($this->database, $this->art->scope))->get((string) $item['item_id']);
            $conflicted = $conflicted || ($identity !== null && !empty($identity['conflicted']));
            if ($identity !== null && empty($identity['conflicted']) && ($identity['serverId'] ?? null) === $this->art->source()['server_id']) {
                $id = $identity['providers']['tmdb'] ?? null;
                $verified = is_string($id) && preg_match('/^[1-9][0-9]{0,17}$/D', $id) ? $id : null;
            }
        }
        try {
            $meta = $collector->lookup($target);
            if ($target === $item['item_id'] && $item['evidence'] !== 'manual'
                && trim((string) ($meta['Name'] ?? '')) !== trim((string) $item['title'])) {
                $this->art->acceptExternalObservation((string) $item['item_id'], $expectedFingerprint, $lease, $now, -1, null, true);
                throw new \RuntimeException('identity_conflict');
            }
            if ($item['item_type'] === 'Episode' && $item['poster_id'] === null) {
                if (($meta['Type'] ?? null) !== 'Episode' || !is_string($meta['SeriesId'] ?? null)) {
                    throw new \RuntimeException('metadata');
                }
                if (trim((string) ($meta['SeriesName'] ?? '')) !== trim((string) $item['series_name'])) {
                    $this->art->acceptExternalObservation((string) $item['item_id'], $expectedFingerprint, $lease, $now, -1, null, true);
                    throw new \RuntimeException('identity_conflict');
                }
                $target = $meta['SeriesId'];
                $meta = $collector->lookup($target);
            }
            $kind = $item['item_type'] === 'Episode' ? 'Series' : 'Movie';
            if (($meta['Type'] ?? null) !== $kind) {
                throw new \RuntimeException('metadata');
            }
            if ($kind === 'Series' && $item['evidence'] !== 'manual'
                && trim((string) ($meta['Name'] ?? '')) !== trim((string) $item['series_name'])) {
                $this->art->acceptExternalObservation((string) $item['item_id'], $expectedFingerprint, $lease, $now, -1, null, true);
                throw new \RuntimeException('identity_conflict');
            }
            $poster = $this->art->poster($target);
            $conflicted = $poster !== null && (int) $poster['provider_conflict'] !== 0;
            $tag = $meta['ImageTags']['Primary'] ?? null;
            $presence = is_array($meta['ImageTags'] ?? null) ? ($tag === null ? 0 : 1) : (int) ($poster['presence'] ?? -1);
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'missing_item') {
                throw $error;
            }
            $presence = 0;
            $meta = $poster !== null || ($item['item_type'] === 'Episode' && $target !== $item['item_id']) || $item['item_type'] === 'Movie'
                ? ['Id' => $target, 'Type' => $item['item_type'] === 'Episode' ? 'Series' : 'Movie'] : null;
        }
        if (!$this->art->acceptExternalObservation(
            (string) $binding['item_id'],
            $expectedFingerprint,
            $lease,
            $now,
            $presence,
            is_string($verified) ? $verified : null,
            $conflicted,
            $target,
            $meta
        )) {
            throw new \RuntimeException('identity_changed');
        }
    }
}
