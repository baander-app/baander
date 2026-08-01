<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\UnstarStationCommand;
use App\Radio\Application\Port\StarredStationPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnstarStationHandler
{
    public function __construct(
        private readonly StarredStationPortInterface $starredPort,
    ) {
    }

    public function __invoke(UnstarStationCommand $command): void
    {
        $this->starredPort->unstar(
            userId: $command->getUserId(),
            stationId: $command->getStationId(),
        );
    }
}
