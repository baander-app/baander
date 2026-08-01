<?php

declare(strict_types=1);

namespace App\Party\Application\CommandHandler;

use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Infrastructure\PlaybackSynchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SyncPlaybackHandler
{
    public function __construct(
        private readonly PlaybackSynchronizer $synchronizer,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SyncPlaybackCommand $command): float
    {
        // Delegate to PlaybackSynchronizer so per-member drift tracking and the
        // jitter EMA actually run (the session sync alone left them inert).
        return $this->synchronizer->synchronize(
            $command->getSessionId(),
            $command->getUserId(),
            $command->getClientPosition(),
            $command->getClientLatency(),
        );
    }
}
