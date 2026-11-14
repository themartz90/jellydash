<?php

declare(strict_types=1);

namespace Mk\Framework;

use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Health\WorkerStatusRepository;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Jellyfin\ArtworkRepository;
use Mk\Framework\Jellyfin\MovieIdentityRepository;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Push\PushSubscriptionRepository;

final class DatabaseSchemaInitializer
{
    public static function initialize(Database $database): void
    {
        $database->ensureAuthSchema();
        AppSettings::ensureSchema($database);
        new PlayHistoryRepository($database);
        new ThemePlaybackExclusions($database);
        new MovieReplacements($database);
        new MovieIdentityRepository($database);
        new ArtworkRepository($database);
        new PushSubscriptionRepository($database);
        new SeerrRequestRepository($database);
        WorkerStatusRepository::ensureSchema($database);
        ConnectionRepository::ensureSchema($database);
        DownloadRepository::ensureSchema($database);
    }
}
