<?php

declare(strict_types=1);

namespace Mk\Framework;

use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Health\WorkerStatusRepository;

final class DatabaseSchemaInitializer
{
    public static function initialize(Database $database): void
    {
        $database->ensureAuthSchema();
        AppSettings::ensureSchema($database);
        new PlayHistoryRepository($database);
        new ThemePlaybackExclusions($database);
        new PushSubscriptionRepository($database);
        new SeerrRequestRepository($database);
        WorkerStatusRepository::ensureSchema($database);
    }
}
