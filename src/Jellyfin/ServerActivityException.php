<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class ServerActivityException extends \RuntimeException
{
    public function __construct(public readonly string $state = 'unavailable')
    {
        parent::__construct(match ($state) {
            'forbidden' => 'The Jellyfin credential cannot read this section. Check its access in Jellyfin.',
            'unconfigured' => 'Jellyfin is not configured.',
            'expired' => 'This activity view has expired. Refresh activity to continue.',
            'checking' => 'Jellyfin details are refreshing. Try again shortly.',
            default => 'Jellyfin could not supply this section. Try refreshing shortly.',
        });
    }
}
