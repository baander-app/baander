<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Transcode\Domain\Event\PlaybackPositionChanged;

/**
 * Bridges PlaybackPositionChanged domain events to the SeekSignalBroker.
 *
 * Runs in an HTTP worker coroutine. Pushes seek/pause signals to the
 * per-job channel so the encoding loop coroutine can pick them up.
 */
final class PlaybackPositionChangedListener
{
    public function __construct(
        private readonly SeekSignalBroker $seekSignalBroker,
    ) {
    }

    public function __invoke(PlaybackPositionChanged $event): void
    {
        $this->seekSignalBroker->signal(
            $event->getJobId(),
            $event->getPosition(),
            $event->getAction(),
        );
    }
}
