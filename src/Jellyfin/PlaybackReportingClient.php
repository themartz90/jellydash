<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Playback Reporting plugin HTTP API (custom query, presence, compatibility).
 */
class PlaybackReportingClient
{
    public const CHUNK_SIZE = 500;

    private const QUERY_PATH = '/user_usage_stats/submit_custom_query';
    private const ACTIVITY_COLUMNS = 'DateCreated, UserId, ItemId, ItemType, ItemName, PlaybackMethod, ClientName, DeviceName, PlayDuration';

    private JellyfinClient $jellyfin;

    public function __construct(?JellyfinClient $jellyfin = null)
    {
        $this->jellyfin = $jellyfin ?? new JellyfinClient();
    }

    /**
     * One page of PlaybackActivity rows, already mapped to play_history fields.
     *
     * @return array{rows: list<array<string, mixed>>, fetched: int}
     */
    public function activityChunk(PlaybackReportingParser $parser, int $offset, int $limit = self::CHUNK_SIZE): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $sql = 'SELECT ' . self::ACTIVITY_COLUMNS
            . ' FROM PlaybackActivity ORDER BY DateCreated LIMIT ' . $limit . ' OFFSET ' . $offset;

        $payload = $this->customQuery($sql, 60);

        return [
            'rows' => $parser->parseApiResults($payload['columns'], $payload['results']),
            'fetched' => count($payload['results']),
        ];
    }

    /**
     * Number of PlaybackActivity rows the plugin can see. Used for the
     * confirmation dialog; the actual import still maps each row.
     */
    public function count(): int
    {
        $payload = $this->customQuery(
            'SELECT COUNT(*) FROM PlaybackActivity',
            30,
        );

        $first = $payload['results'][0] ?? null;
        if (!is_array($first) || $first === []) {
            throw new \RuntimeException('Playback Reporting returned an invalid count.');
        }

        return max(0, (int) reset($first));
    }

    /**
     * Cheap probe: the Playback Reporting plugin is installed and answering.
     */
    public function available(): bool
    {
        try {
            $payload = $this->jellyfin->getJson('/user_usage_stats/type_filter_list', 4);

            return is_array($payload);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Plugin presence plus whether CustomQuery can actually run.
     * v17 on Jellyfin 10.11.9+ is a known broken combo; HTTP 500 is the fallback.
     *
     * @return array{available: bool, importable: bool, broken: bool, help_url: ?string}
     */
    public function status(bool $probeQuery = false): array
    {
        $available = $this->available();
        $jellyfinVersion = $available ? $this->jellyfinVersion() : null;
        $pluginVersion = $available ? $this->pluginVersion() : null;
        $customQueryOk = null;
        if ($available && $probeQuery) {
            $customQueryOk = $this->customQueryAlive();
        }

        $state = PlaybackReportingCompatibility::importState(
            $available,
            $jellyfinVersion,
            $pluginVersion,
            $customQueryOk,
        );

        return [
            'available' => $available,
            'importable' => $state['importable'],
            'broken' => $state['broken'],
            'help_url' => $state['broken'] ? PlaybackReportingCompatibility::HELP_URL : null,
        ];
    }

    /**
     * @return array{columns: array<int, mixed>, results: array<int, mixed>}
     */
    private function customQuery(string $sql, int $timeout): array
    {
        try {
            $payload = $this->jellyfin->postJson(
                self::QUERY_PATH,
                [
                    'CustomQueryString' => $sql,
                    'ReplaceUserId' => false,
                ],
                $timeout,
            );
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->queryErrorMessage($e->getMessage()), previous: $e);
        }

        if (!is_array($payload)) {
            throw new \RuntimeException('Playback Reporting returned an invalid payload.');
        }

        $message = strtolower((string) ($payload['message'] ?? ''));
        if ($message !== '' && !str_contains($message, 'query executed')) {
            throw new \RuntimeException('Playback Reporting query failed: ' . (string) $payload['message']);
        }

        $columns = $payload['colums'] ?? $payload['columns'] ?? [];
        $results = $payload['results'] ?? [];
        if (!is_array($columns) || !is_array($results)) {
            throw new \RuntimeException('Playback Reporting returned an invalid payload.');
        }

        return [
            'columns' => $columns,
            'results' => $results,
        ];
    }

    private function queryErrorMessage(string $message): string
    {
        if (str_contains($message, 'HTTP 500')) {
            return 'Playback Reporting API is incompatible with this Jellyfin (needs plugin v18+ on 10.11.9+). Drop a TSV backup instead.';
        }

        if (str_contains($message, 'HTTP 404')) {
            return 'Jellyfin request failed with HTTP 404. Is the Playback Reporting plugin installed?';
        }

        return $message;
    }

    private function jellyfinVersion(): ?string
    {
        try {
            $payload = $this->jellyfin->getJson('/System/Info/Public', 4);
            if (!is_array($payload)) {
                return null;
            }
            $version = PlaybackReportingCompatibility::normalizeVersion((string) ($payload['Version'] ?? ''));

            return $version !== '' ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pluginVersion(): ?string
    {
        try {
            $payload = $this->jellyfin->getJson('/Plugins', 4);
            if (!is_array($payload)) {
                return null;
            }

            $guid = strtolower(str_replace('-', '', PlaybackReportingCompatibility::PLUGIN_GUID));
            foreach ($payload as $plugin) {
                if (!is_array($plugin)) {
                    continue;
                }
                $id = strtolower(str_replace('-', '', (string) ($plugin['Id'] ?? '')));
                $name = strtolower(trim((string) ($plugin['Name'] ?? '')));
                if ($id !== $guid && $name !== 'playback reporting') {
                    continue;
                }
                $version = PlaybackReportingCompatibility::normalizeVersion((string) ($plugin['Version'] ?? ''));
                if ($version !== '') {
                    return $version;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * true = CustomQuery ran, false = known 500 incompatibility, null = other failure.
     */
    private function customQueryAlive(): ?bool
    {
        try {
            $this->customQuery('SELECT DateCreated FROM PlaybackActivity LIMIT 1', 8);

            return true;
        } catch (\Throwable $e) {
            return PlaybackReportingCompatibility::isCustomQueryBrokenMessage($e->getMessage()) ? false : null;
        }
    }
}
