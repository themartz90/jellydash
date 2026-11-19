<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class HistoryPollDeferredException extends HistoryWriteBusyException
{
    public const CAPTURE = 'history_capture_busy';
    public const DRAIN = 'history_drain_deferred';
    public const CLASSIFICATION = 'history_classification_deferred';

    /** @param self::CAPTURE|self::DRAIN|self::CLASSIFICATION $reason */
    public function __construct(public readonly string $reason, HistoryWriteBusyException $previous)
    {
        parent::__construct(match ($reason) {
            self::CAPTURE => 'History was busy before playback could be captured.',
            self::DRAIN => 'Captured playback samples are waiting to be recorded.',
            self::CLASSIFICATION => 'Playback was recorded and theme classification was deferred.',
        }, previous: $previous);
    }
}
